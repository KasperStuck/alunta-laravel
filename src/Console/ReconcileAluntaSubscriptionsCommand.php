<?php

namespace KasperStuck\Alunta\Laravel\Console;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use KasperStuck\Alunta\Laravel\AluntaApiClient;
use KasperStuck\Alunta\Laravel\Checkout\AluntaCheckoutService;
use KasperStuck\Alunta\Laravel\Checkout\CheckoutAttempt;
use KasperStuck\Alunta\Laravel\Contracts\Billable;
use KasperStuck\Alunta\Laravel\Contracts\BillableRepository;
use KasperStuck\Alunta\Laravel\Sync\AluntaSubscriptionSync;
use KasperStuck\Alunta\Laravel\Sync\InvoiceAccessThreshold;
use KasperStuck\Alunta\Laravel\Webhooks\BillingWebhookEvent;
use KasperStuck\Alunta\Laravel\Webhooks\ProcessAluntaWebhookJob;
use KasperStuck\Alunta\Sdk\AluntaApiException;
use RuntimeException;
use Throwable;

/**
 * Repairs what a lost or failed Alunta webhook left behind. Each run:
 *
 * 1. binds the billables with a pending checkout attempt to the running subscription Alunta holds
 *    for them, at most 20 attempts, least recently checked first, so steps 2 and 3 keep their share
 *    of the rate limit. Binding syncs the subscription, so a first invoice paid since is
 *    granted even when its payment webhook was lost. Only when Alunta holds none is an expired
 *    attempt retired, and only when Alunta says its session is safe to restart or does not know it.
 *    Age never retires an attempt: one still pending after the 48-hour binding window, which
 *    outlasts Alunta's webhook retries, is reported once and kept;
 * 2. syncs the paying Alunta billables not synced for 20 hours, whose period or end date has
 *    passed, or whose open invoice has passed its access threshold while they are not past due
 *    (BillableRepository::eachDueForSync()), so access closes at the first run that can read the
 *    invoice; a subscription Alunta answered 404 for, or whose grant the sync refused
 *    (AluntaSubscriptionSync::isHeldOff()), is tried again once a day;
 * 3. reads again, at most once every 20 hours each, the stored open invoice of the billables
 *    without a paid plan that owe it past its due date, so a lost invoice.paid cannot keep a
 *    paid invoice blocking a new purchase (recheckEndedInvoices());
 * 4. retries the stored webhooks that failed, or never ran, more than an hour ago, at most 10 times,
 *    with the job class the container resolves for ProcessAluntaWebhookJob.
 *
 * Before step 1, the run notes the billables whose open invoice has passed its access threshold
 * while they are not past due, whether or not step 2 may sync them. At the end, also when an
 * account-wide error stops the run, one warning lists those still in that state.
 *
 * Every Alunta request, retries included, waits 0.7 seconds after the one before, so a minute holds
 * at most 86 of them, below Alunta's 120 reads a minute per team. An account-wide error
 * (RUN_STOPPING_ERRORS) stops the run; any other failure skips that item. --org reconciles one
 * billable (BillableRepository::findForReconcile()) whatever is due: it syncs its subscription and,
 * when that leaves it on the free plan, binds a new checkout.
 *
 * The package registers the command (AluntaServiceProvider); the app schedules it.
 */
class ReconcileAluntaSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:reconcile-alunta {--org= : Reconcile only this organization (UUID or slug)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs due Alunta subscriptions, binds open checkouts and retries failed Alunta webhooks.';

    private const int STALE_AFTER_HOURS = 20;

    private const int BINDING_WINDOW_HOURS = 48;

    private const int MISSING_SUBSCRIPTION_RETRY_HOURS = 24;

    private const int WEBHOOK_RETRY_AFTER_MINUTES = 60;

    private const int MAX_WEBHOOK_ATTEMPTS = 10;

    private const int MAX_CHECKOUT_BINDINGS_PER_RUN = 20;

    private const int REQUEST_SPACING_MILLISECONDS = 700;

    private const int INVOICE_RECHECK_HOURS = 20;

    /**
     * The timezone of Alunta's calendar days, in which an invoice's due date is a day (see
     * AluntaSubscriptionSync::ALUNTA_TIMEZONE).
     */
    private const string ALUNTA_TIMEZONE = 'Europe/Copenhagen';

    /**
     * The errors that concern the whole account rather than one item, so the run stops.
     */
    private const array RUN_STOPPING_ERRORS = [
        AluntaApiException::RATE_LIMITED,
        AluntaApiException::NOT_CONFIGURED,
        AluntaApiException::UNAUTHORIZED,
        AluntaApiException::PAYMENT_REQUIRED,
        AluntaApiException::CONNECTION,
    ];

    private int $failures = 0;

    /**
     * Execute the console command.
     */
    public function handle(AluntaSubscriptionSync $sync, AluntaCheckoutService $checkouts, BillableRepository $billables, InvoiceAccessThreshold $invoiceAccess): int
    {
        if (config('services.alunta.enabled') !== true) {
            $this->components->info('Alunta is not enabled; nothing to reconcile.');

            return self::SUCCESS;
        }

        AluntaApiClient::paceRequests(self::REQUEST_SPACING_MILLISECONDS);
        $now = CarbonImmutable::instance(now());
        $pastThresholdKeys = [];

        try {
            $identifier = $this->option('org');

            if (is_string($identifier) && $identifier !== '') {
                $this->reconcileBillable($identifier, $sync, $checkouts, $billables);
            } else {
                $pastThresholdKeys = self::pastThresholdKeys($billables, $invoiceAccess, $now);
                $this->bindOpenCheckouts($checkouts, $billables);
                $this->syncDueBillables($sync, $billables, $now);
                $this->recheckEndedInvoices($sync, $billables);
                $this->retryWebhookEvents();
            }
        } catch (AluntaApiException $exception) {
            Log::warning('[ReconcileAluntaSubscriptionsCommand] Stopped by an Alunta error', ['error_code' => $exception->errorCode]);
            $this->components->error("Stopped: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            AluntaApiClient::paceRequests(null);
            self::warnStillOpen($pastThresholdKeys, $billables, $invoiceAccess, $now);
        }

        if ($this->failures > 0) {
            $this->components->warn("Reconciled with {$this->failures} failure(s); see the log.");

            return self::FAILURE;
        }

        $this->components->info('Alunta reconciled.');

        return self::SUCCESS;
    }

    /**
     * Syncs the named billable's subscription and, when it is on the free plan afterwards, binds its
     * checkout. The billable is read again after the sync, which throws as a refresh would when it
     * no longer exists.
     */
    private function reconcileBillable(string $identifier, AluntaSubscriptionSync $sync, AluntaCheckoutService $checkouts, BillableRepository $billables): void
    {
        $billable = $billables->findForReconcile($identifier);

        if ($billable === null) {
            $this->components->error('No organization has that UUID or slug.');
            $this->failures++;

            return;
        }

        $state = $billable->billingState();

        if ($state->isAluntaLinked()) {
            $this->attempt('sync', $billable->getBillingKey(), fn () => $sync->sync((string) $state->subscriptionId));

            $billable = $billables->find($billable->getBillingKey())
                ?? throw new ModelNotFoundException('No query results for model ['.$billable::class.'].');

            if ($billable->billingState()->paidPlan !== null) {
                return;
            }
        }

        $this->reconcileCheckout($billable, $checkouts);
    }

    /**
     * Reconciles the billable of each of the least recently checked pending attempts, once per
     * billable. A billable the repository no longer holds is skipped.
     */
    private function bindOpenCheckouts(AluntaCheckoutService $checkouts, BillableRepository $billables): void
    {
        $attempts = CheckoutAttempt::appModel();

        $attempts->newQuery()
            ->pending()
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::MAX_CHECKOUT_BINDINGS_PER_RUN)
            ->get()
            ->unique($attempts->billableKeyName())
            ->each(function (CheckoutAttempt $attempt) use ($checkouts, $billables): void {
                $billableKey = $attempt->getAttribute($attempt->billableKeyName());
                $billable = is_int($billableKey) || is_string($billableKey) ? $billables->find($billableKey) : null;

                if ($billable !== null) {
                    $this->reconcileCheckout($billable, $checkouts);
                }
            });
    }

    /**
     * Binds the billable to the subscription its checkout created. When Alunta holds no running
     * subscription for it, retires each pending attempt whose expired session Alunta says is safe to
     * restart or does not know. Unless bound, reports once each attempt still pending after the
     * binding window. The pending attempts are marked as checked first (updated_at), so the next run
     * starts with the others even when this check stops the run.
     */
    private function reconcileCheckout(Billable $billable, AluntaCheckoutService $checkouts): void
    {
        $billableKey = $billable->getBillingKey();

        CheckoutAttempt::appModel()->newQuery()->forBillable($billable)->pending()->update(['updated_at' => now()]);

        $binding = $this->attempt('bind', $billableKey, fn (): string => $checkouts->bindRunningSubscription($billable));

        if ($binding === null || $binding === 'bound') {
            return;
        }

        $windowStart = now()->subHours(self::BINDING_WINDOW_HOURS);

        CheckoutAttempt::appModel()->newQuery()
            ->forBillable($billable)
            ->pending()
            ->orderBy('id')
            ->get()
            ->each(function (CheckoutAttempt $attempt) use ($checkouts, $billableKey, $windowStart, $binding): void {
                if ($binding === 'none' && ! AluntaCheckoutService::sessionLives($attempt)
                    && $this->attempt('session', $billableKey, fn (): bool => $checkouts->retireIfRestartable($attempt)) === true) {
                    return;
                }

                if ($attempt->created_at !== null && $attempt->created_at->lessThan($windowStart) && $attempt->recordAlert('checkout_unresolved')) {
                    Log::warning('[ReconcileAluntaSubscriptionsCommand] A checkout attempt is unresolved after the binding window', [
                        'checkout_attempt_id' => $attempt->id,
                        'organization_id' => $billableKey,
                        'has_session' => $attempt->alunta_session_id !== null,
                    ]);
                    report(new RuntimeException("Alunta checkout attempt {$attempt->id} is still unresolved after ".self::BINDING_WINDOW_HOURS.' hours.'));
                }
            });
    }

    /**
     * Syncs each due billable, unless a refused grant holds its subscription off.
     */
    private function syncDueBillables(AluntaSubscriptionSync $sync, BillableRepository $billables, CarbonImmutable $now): void
    {
        $billables->eachDueForSync(
            $now,
            $now->subHours(self::STALE_AFTER_HOURS),
            $now->subHours(self::MISSING_SUBSCRIPTION_RETRY_HOURS),
            function (Billable $billable) use ($sync): void {
                $this->attempt('sync', $billable->getBillingKey(), function () use ($billable, $sync): ?Billable {
                    $subscriptionUuid = (string) $billable->billingState()->subscriptionId;

                    return AluntaSubscriptionSync::isHeldOff($subscriptionUuid) ? null : $sync->sync($subscriptionUuid);
                });
            },
        );
    }

    /**
     * Reads again the stored open invoice of each billable that holds no paid plan and owes it past
     * its due date (AluntaCheckoutService::owesInvoicePastDue()), at most once every
     * INVOICE_RECHECK_HOURS per billable (invoiceRecheckKey()). It recovers a lost invoice.paid:
     * that invoice blocks a new purchase, and no sync reads it any more. The read and the clear are
     * AluntaSubscriptionSync::recheckOpenInvoice()'s, under the billable's row lock: Alunta showing
     * it closed clears it, open or unclear changes nothing. Once Alunta has answered, also with a
     * not-found, the billable waits for the next window. Any other failed read (a server error, for
     * example) leaves no mark, so the next run reads it again; an account-wide error stops the run
     * before the mark.
     */
    private function recheckEndedInvoices(AluntaSubscriptionSync $sync, BillableRepository $billables): void
    {
        $billables->eachWithOpenInvoiceDueBefore(
            CarbonImmutable::now(self::ALUNTA_TIMEZONE)->startOfDay(),
            function (Billable $billable) use ($sync): void {
                $billableKey = $billable->getBillingKey();

                if (Cache::has(self::invoiceRecheckKey($billableKey))) {
                    return;
                }

                $outcome = $this->attempt('invoice', $billableKey, function () use ($billable, $sync, $billableKey): string {
                    if ($billable->billingState()->paidPlan !== null || ! AluntaCheckoutService::owesInvoicePastDue($billable)) {
                        return 'skipped';
                    }

                    try {
                        $sync->recheckOpenInvoice($billable);
                    } catch (AluntaApiException $exception) {
                        if ($exception->errorCode !== AluntaApiException::NOT_FOUND) {
                            throw $exception;
                        }

                        $this->recordFailure('invoice', $billableKey, $exception->errorCode);

                        return 'answered';
                    }

                    return 'answered';
                });

                if ($outcome === 'answered') {
                    Cache::put(self::invoiceRecheckKey($billableKey), true, now()->addHours(self::INVOICE_RECHECK_HOURS));
                }
            },
        );
    }

    /**
     * The cache key that marks the billable's stored open invoice as read by the reconciliation in
     * the last INVOICE_RECHECK_HOURS.
     */
    private static function invoiceRecheckKey(int|string $billableKey): string
    {
        return 'alunta-open-invoice-rechecked:'.$billableKey;
    }

    /**
     * The keys of the billables whose open invoice has passed its access threshold at $now while
     * they are not past due (InvoiceAccessThreshold::isPastWhileOpen()), whether or not this run
     * may sync them: also those a 404 or a refused grant holds back. Read from the database only,
     * before the run sends anything.
     *
     * @return list<int|string>
     */
    private static function pastThresholdKeys(BillableRepository $billables, InvoiceAccessThreshold $invoiceAccess, CarbonImmutable $now): array
    {
        $lastClosedDueOn = $invoiceAccess->lastClosedDueOn($now);
        $keys = [];

        if ($lastClosedDueOn === null) {
            return $keys;
        }

        $billables->eachWithOpenInvoiceDueBefore($lastClosedDueOn->addDay(), function (Billable $billable) use ($invoiceAccess, $now, &$keys): void {
            if (self::stillOpenInvoice($billable, $invoiceAccess, $now) !== null) {
                $keys[] = $billable->getBillingKey();
            }
        });

        return $keys;
    }

    /**
     * Writes one warning that lists the billables of $pastThresholdKeys that, as stored at the end
     * of the run, are still not past due while their open invoice has passed its access threshold:
     * a read failed, came back unknown, was held off, or never ran because an account-wide error
     * stopped the run. The warning names keys and identifiers only. A billable that no longer
     * exists or cannot be read is left out.
     *
     * @param  list<int|string>  $pastThresholdKeys
     */
    private static function warnStillOpen(array $pastThresholdKeys, BillableRepository $billables, InvoiceAccessThreshold $invoiceAccess, CarbonImmutable $now): void
    {
        $stillOpen = [];

        foreach ($pastThresholdKeys as $billableKey) {
            try {
                $billable = $billables->find($billableKey);
            } catch (Throwable) {
                continue;
            }

            $entry = $billable !== null ? self::stillOpenInvoice($billable, $invoiceAccess, $now) : null;

            if ($entry !== null) {
                $stillOpen[] = $entry;
            }
        }

        if ($stillOpen !== []) {
            Log::warning('[ReconcileAluntaSubscriptionsCommand] Invoices past their access threshold are still open', ['organizations' => $stillOpen]);
        }
    }

    /**
     * The keys of the billable when, linked to an Alunta subscription, its open invoice has passed
     * its access threshold while it is not past due, else null. A billable whose state cannot be
     * read is left out.
     *
     * @return array{organization_id: int|string, subscription_uuid: ?string, invoice_uuid: ?string}|null
     */
    private static function stillOpenInvoice(Billable $billable, InvoiceAccessThreshold $invoiceAccess, CarbonImmutable $now): ?array
    {
        try {
            $state = $billable->billingState();
        } catch (Throwable) {
            return null;
        }

        return $state->isAluntaLinked() && $invoiceAccess->isPastWhileOpen($state, $now) ? [
            'organization_id' => $billable->getBillingKey(),
            'subscription_uuid' => $state->subscriptionId,
            'invoice_uuid' => $state->openInvoiceId,
        ] : null;
    }

    private function retryWebhookEvents(): void
    {
        $cutoff = now()->subMinutes(self::WEBHOOK_RETRY_AFTER_MINUTES);

        BillingWebhookEvent::query()
            ->where('provider', 'alunta')
            ->whereNull('processed_at')
            ->where('attempts', '<', self::MAX_WEBHOOK_ATTEMPTS)
            ->where(fn (Builder $query) => $query
                ->where('failed_at', '<', $cutoff)
                ->orWhere(fn (Builder $neverRan) => $neverRan->whereNull('failed_at')->where('created_at', '<', $cutoff)))
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (BillingWebhookEvent $webhookEvent) => $this->attempt(
                'webhook',
                null,
                fn () => app()->call([app()->make(ProcessAluntaWebhookJob::class, ['webhookEventId' => $webhookEvent->id]), 'handle']),
            ));
    }

    /**
     * Runs one Alunta step. An account-wide Alunta error is rethrown to stop the run; any other
     * failure is logged and counted, and null is returned.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $step
     * @return TResult|null
     *
     * @throws AluntaApiException
     */
    private function attempt(string $label, int|string|null $billableKey, Closure $step): mixed
    {
        try {
            return $step();
        } catch (AluntaApiException $exception) {
            if (in_array($exception->errorCode, self::RUN_STOPPING_ERRORS, true)) {
                throw $exception;
            }

            $this->recordFailure($label, $billableKey, $exception->errorCode);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($label, $billableKey, $exception::class);
        }

        return null;
    }

    private function recordFailure(string $label, int|string|null $billableKey, string $error): void
    {
        $this->failures++;

        Log::warning('[ReconcileAluntaSubscriptionsCommand] An Alunta step failed', [
            'step' => $label,
            'organization_id' => $billableKey,
            'error' => $error,
        ]);
    }
}
