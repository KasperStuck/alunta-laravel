<?php

namespace KasperStuck\Alunta\Laravel\Webhooks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use KasperStuck\Alunta\Laravel\AluntaAccount;
use KasperStuck\Alunta\Laravel\Billing\BillingChange;
use KasperStuck\Alunta\Laravel\Billing\BillingField;
use KasperStuck\Alunta\Laravel\Checkout\AluntaCheckoutService;
use KasperStuck\Alunta\Laravel\Checkout\CheckoutAttempt;
use KasperStuck\Alunta\Laravel\Contracts\Billable;
use KasperStuck\Alunta\Laravel\Contracts\BillableIdentity;
use KasperStuck\Alunta\Laravel\Contracts\BillableRepository;
use KasperStuck\Alunta\Laravel\Sync\AluntaSubscriptionSync;
use KasperStuck\Alunta\Sdk\AluntaApiException;
use Throwable;

/**
 * Processes a stored Alunta webhook delivery. The payload never sets state: a subscription.* event
 * or a completed subscription checkout syncs the subscription (AluntaSubscriptionSync), which also
 * reads the settlement, so a succeeded payment grants nothing by itself; for a subscription paid by
 * invoice the sync reads its invoice standing, so a partial payment never reopens access.
 * invoice.created and invoice.paid name no subscription (applyInvoiceEvent()): for a billable
 * with a pending checkout attempt they bind the running subscription Alunta holds for it, for a
 * failed renewal or a billable that follows its invoices they sync the bound subscription. After
 * an invoice event, and after the sync of any other event, a billable left without a paid plan
 * that still has a stored open invoice reads that invoice again (recheckEndedInvoice()), so the
 * order of the events does not matter.
 *
 * After the sync, a completed checkout completes its attempt, and a payment event applies to the
 * billables bound to its subscription only when it is newer than the last one applied
 * (LastPaymentEventAt, the envelope timestamp to the microsecond, the same across retries): a
 * failure sets PaymentFailedAt, keeping the first failure in a row, and a success clears it. Other
 * events are only marked processed.
 *
 * A failure is recorded on the delivery (failed_at, a safe error and the attempt count) and
 * rethrown, so the queue retries it with a backoff and billing:reconcile-alunta retries it later.
 *
 * The job runs on the queue connection's default queue. The webhook controller queues the class
 * the container resolves for this one, so an app can bind its own subclass.
 */
class ProcessAluntaWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $webhookEventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 3600];
    }

    public function handle(AluntaSubscriptionSync $sync, AluntaCheckoutService $checkouts, BillableRepository $billables, BillableIdentity $identity): void
    {
        $event = BillingWebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');

        try {
            $this->process($event, $sync, $checkouts, $billables, $identity);
        } catch (Throwable $exception) {
            $event->forceFill(['failed_at' => now(), 'error' => self::safeError($exception)])->save();

            throw $exception;
        }

        $event->forceFill(['processed_at' => now(), 'failed_at' => null, 'error' => null])->save();
    }

    private function process(BillingWebhookEvent $event, AluntaSubscriptionSync $sync, AluntaCheckoutService $checkouts, BillableRepository $billables, BillableIdentity $identity): void
    {
        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (in_array($event->event, ['invoice.created', 'invoice.paid'], true)) {
            $this->applyInvoiceEvent($data, $sync, $checkouts, $billables, $identity);

            return;
        }

        $isSubscriptionCheckout = $event->event === 'checkout.completed' && ($data['type'] ?? null) === 'subscription';

        if (! str_starts_with($event->event, 'subscription.') && ! $isSubscriptionCheckout) {
            return;
        }

        $subscriptionUuid = is_array($data['subscription'] ?? null) ? ($data['subscription']['uuid'] ?? null) : null;

        if (! is_string($subscriptionUuid) || ! Str::isUuid($subscriptionUuid)) {
            Log::warning('Alunta webhook names no subscription.', ['webhook_event_id' => $event->id, 'event' => $event->event]);

            return;
        }

        $sync->sync($subscriptionUuid);

        $occurredAt = self::occurredAt($payload['timestamp'] ?? null, $event);

        match ($event->event) {
            'checkout.completed' => $this->completeCheckoutAttempt($data, $subscriptionUuid, $billables, $identity),
            'subscription.payment_failed' => $this->recordPayment($subscriptionUuid, $occurredAt, $billables, failed: true),
            'subscription.payment_succeeded' => $this->recordPayment($subscriptionUuid, $occurredAt, $billables, failed: false),
            default => null,
        };

        foreach ($billables->boundTo($subscriptionUuid) as $boundBillable) {
            self::recheckEndedInvoice($boundBillable, $sync);
        }
    }

    /**
     * Reads the billable's stored open invoice again (AluntaSubscriptionSync::recheckOpenInvoice())
     * when, as read now, it holds no paid plan but still has one: its subscription has ended, so no
     * sync reads that invoice any more. Alunta showing it closed clears it; open or unclear changes
     * nothing. Alunta not knowing the invoice is logged and changes nothing; any other Alunta error
     * fails the job, which is retried.
     *
     * @throws AluntaApiException
     */
    private static function recheckEndedInvoice(?Billable $billable, AluntaSubscriptionSync $sync): void
    {
        $state = $billable?->billingState();

        if ($billable !== null && $state !== null && $state->paidPlan === null && $state->openInvoiceId !== null) {
            try {
                $sync->recheckOpenInvoice($billable);
            } catch (AluntaApiException $exception) {
                if ($exception->errorCode !== AluntaApiException::NOT_FOUND) {
                    throw $exception;
                }

                Log::warning('Alunta does not know the stored open invoice; nothing changed.', [
                    'organization_id' => $billable->getBillingKey(),
                    'invoice_uuid' => $state->openInvoiceId,
                ]);
            }
        }
    }

    /**
     * Applies a payment event to the billables bound to the subscription, under each billable's
     * lock, unless a newer payment event was applied already. At the same microsecond a success
     * wins, so a retry that succeeds right after a failure clears it.
     */
    private function recordPayment(string $subscriptionUuid, CarbonInterface $occurredAt, BillableRepository $billables, bool $failed): void
    {
        foreach ($billables->boundTo($subscriptionUuid) as $boundBillable) {
            DB::transaction(function () use ($billables, $boundBillable, $subscriptionUuid, $occurredAt, $failed): void {
                $billable = $billables->lockForBilling($boundBillable->getBillingKey());
                $state = $billable->billingState();

                $lastPaymentEventAt = $state->lastPaymentEventAt;
                $isOlder = $lastPaymentEventAt !== null
                    && ($failed ? $occurredAt->lessThanOrEqualTo($lastPaymentEventAt) : $occurredAt->lessThan($lastPaymentEventAt));

                if ($state->subscriptionId !== $subscriptionUuid || $isOlder) {
                    return;
                }

                $billable->applyBillingChange(BillingChange::make()
                    ->with(BillingField::PaymentFailedAt, $failed ? ($state->paymentFailedAt ?? $occurredAt) : null)
                    ->with(BillingField::LastPaymentEventAt, $occurredAt));
            });
        }
    }

    /**
     * Applies an invoice.created or invoice.paid event. The event names no subscription, and its
     * external customer id only says which billable to look at, never that the invoice is that
     * billable's: every path below reads Alunta back through the billable's own pending checkout,
     * bound subscription or stored open invoice, so a false hint changes nothing and the order of
     * the events does not matter.
     *
     * - With a pending checkout attempt, binds the billable to its running subscription.
     * - A billable linked to an Alunta subscription syncs it while its payment fails (the sync
     *   clears the warning only when Alunta shows the renewal settled), and, while a paid plan is
     *   granted, when it is paid by invoice or has an open invoice (the sync reads its standing).
     * - Then, read again, a billable without a paid plan (its subscription ended, also by the sync
     *   just run) that still has a stored open invoice reads that invoice again and clears it once
     *   Alunta shows it closed (recheckEndedInvoice()); an open or unclear one stays. This runs
     *   whatever invoice the event names: the stored invoice is read, never the event's, and a
     *   credit note that closes the stored invoice is an invoice.created of its own UUID.
     *
     * Anything else, such as a billable paid by card without a failure or an open invoice, reads
     * nothing.
     *
     * @param  array<mixed>  $data
     *
     * @throws AluntaApiException
     */
    private function applyInvoiceEvent(array $data, AluntaSubscriptionSync $sync, AluntaCheckoutService $checkouts, BillableRepository $billables, BillableIdentity $identity): void
    {
        $reference = $identity->referenceFromExternalCustomerId($data['external_customer_id'] ?? null);
        $billable = $reference !== null ? $billables->findByReference($reference) : null;

        if ($billable === null) {
            return;
        }

        $state = $billable->billingState();
        $followsInvoices = $state->paidPlan !== null && ($state->paymentProvider === 'invoice' || $state->openInvoiceId !== null);

        if (AluntaCheckoutService::unresolvedAttempt($billable) !== null) {
            $checkouts->bindRunningSubscription($billable);
        } elseif ($state->isAluntaLinked() && ($state->paymentFailedAt !== null || $followsInvoices)) {
            $sync->sync((string) $state->subscriptionId);
        }

        self::recheckEndedInvoice($billables->find($billable->getBillingKey()), $sync);
    }

    /**
     * Completes the pending checkout attempt named in the checkout metadata
     * (BillableIdentity::checkoutAttemptKeyFrom()), when its billable is now bound to the
     * subscription. The bound billables are read first and the attempt is then completed in one
     * UPDATE scoped to their keys.
     *
     * @param  array<mixed>  $data
     */
    private function completeCheckoutAttempt(array $data, string $subscriptionUuid, BillableRepository $billables, BillableIdentity $identity): void
    {
        $idempotencyKey = $identity->checkoutAttemptKeyFrom($data['metadata'] ?? null);

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return;
        }

        $attempts = CheckoutAttempt::appModel();

        $attempts->newQuery()
            ->where('idempotency_key', $idempotencyKey)
            ->pending()
            ->whereIn($attempts->qualifyColumn($attempts->billableKeyName()), array_map(
                fn (Billable $billable): int|string => $billable->getBillingKey(),
                $billables->boundTo($subscriptionUuid),
            ))
            ->update(['status' => CheckoutAttempt::STATUS_COMPLETED]);
    }

    /**
     * When the event happened, to the microsecond: the webhook envelope's timestamp, which stays the
     * same across retries, else when the delivery arrived.
     */
    private static function occurredAt(mixed $timestamp, BillingWebhookEvent $event): CarbonImmutable
    {
        return AluntaAccount::timestamp($timestamp) ?? CarbonImmutable::parse($event->created_at ?? now())->utc();
    }

    /**
     * The stored error: the Alunta error code and its safe message, or only the class of any other
     * exception, whose message could hold payload or query details.
     */
    private static function safeError(Throwable $exception): string
    {
        return $exception instanceof AluntaApiException
            ? "{$exception->errorCode}: {$exception->getMessage()}"
            : $exception::class;
    }
}
