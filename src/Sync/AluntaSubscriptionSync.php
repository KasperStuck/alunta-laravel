<?php

namespace KasperStuck\Alunta\Laravel\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use KasperStuck\Alunta\Laravel\AluntaAccount;
use KasperStuck\Alunta\Laravel\AluntaApiClient;
use KasperStuck\Alunta\Laravel\Billing\BillingChange;
use KasperStuck\Alunta\Laravel\Billing\BillingField;
use KasperStuck\Alunta\Laravel\Billing\BillingState;
use KasperStuck\Alunta\Laravel\Billing\FirstGrantRequirement;
use KasperStuck\Alunta\Laravel\Checkout\CheckoutAttempt;
use KasperStuck\Alunta\Laravel\Contracts\Billable;
use KasperStuck\Alunta\Laravel\Contracts\BillableIdentity;
use KasperStuck\Alunta\Laravel\Contracts\BillableRepository;
use KasperStuck\Alunta\Laravel\Contracts\InvoiceAccessPolicy;
use KasperStuck\Alunta\Laravel\Contracts\PlanMapper;
use KasperStuck\Alunta\Laravel\Contracts\SettlementPolicy;
use KasperStuck\Alunta\Sdk\AluntaApiException;
use Throwable;

/**
 * Brings a billable in line with its Alunta subscription as GET /subscriptions/{uuid} returns it
 * now: a webhook only triggers a sync, so an old or repeated event never overwrites newer state.
 * The read and the apply run under one lock per subscription.
 *
 * The billable is the one bound to the subscription, else the one its external customer id or
 * metadata names (BillableIdentity); these must not name different billables. active and
 * under_cancellation grant the mapped plan (PlanMapper) and period (trialing in an active trial,
 * and the subscription end for a cancellation); cancelled moves the bound billable to no paid plan
 * and active, keeping the billing ids and the last known open invoice as history, also after a
 * transfer; pending changes nothing. Sync never sets a failed or cancelled status, sets past_due
 * only for an unpaid invoice (below), and never touches a plan set by hand.
 *
 * Only a subscription whose payment provider the SettlementPolicy grants gets a paid plan. The
 * policy decides new grants only: a billable granted on this subscription keeps following it while
 * its stored payment provider (BillingField::PaymentProvider) is still the subscription's, also
 * after the policy stops granting that provider (mayGrantProvider()). A first grant also needs
 * what SettlementPolicy::firstGrantRequirement() asks for:
 *
 * - SettledFirstInvoice: the first billed invoice settled in full by the payments the policy
 *   counts (firstBilledInvoiceSettlement()). The status is active or trialing.
 * - IssuedFirstInvoice: the subscription is paid by invoice. Its first invoice must be issued
 *   and neither credited nor cancelled, and its access then follows its open invoice, the oldest
 *   unpaid one, which stays the stored open invoice while it is unpaid even when the pages read
 *   do not reach it (InvoiceStandingReader): once a fresh read shows it still outstanding at or after
 *   InvoiceAccessPolicy::accessClosesAt() of its due day, the status is past_due; once it is paid,
 *   whatever provider recorded the payment, active or trialing again. A standing the reader cannot
 *   tell within its budget changes nothing.
 *
 * Every grant writes the subscription's payment provider; a grant of a subscription not paid by
 * invoice clears the open invoice. The payment webhooks, invoice.paid and the reconciliation all
 * reach this read, so a lost payment webhook is recovered. While a payment failure is recorded, a
 * newer renewal settled by counted payments clears it (renewalPaidAt()).
 *
 * The customer id signs the owner in to the customer portal, so it is set only to a customer whose
 * own external customer id names the billable: an unverified customer binds nothing, and a changed
 * customer of a bound subscription is reported, never applied.
 *
 * A bound subscription Alunta answers 404 for is marked (BillingField::SubscriptionMissingAt) and
 * reported, keeping its plan. A refusal is reported once per unresolved checkout attempt
 * (CheckoutAttempt::recordAlert()), else once a day per subscription and reason; a refused grant of the
 * bound billable also holds the subscription off the reconciliation's due syncs for that day
 * (isHeldOff()).
 *
 * The app-specific data lives in the bound contracts, never here: the identity prefix and metadata
 * keys (BillableIdentity), the plan keys and intervals (PlanMapper), the payment providers it
 * trusts (SettlementPolicy) and the checkout attempt model (CheckoutAttempt). The log messages,
 * log context keys, refusal reasons and cache keys are those of the first consumer and stay
 * unchanged so its logs and alerts keep matching: the billable's key is logged as organization_id,
 * and a refused provider is payment_provider_not_card.
 */
class AluntaSubscriptionSync
{
    /**
     * The subscription statuses that may grant a paid plan.
     */
    private const array GRANTABLE_STATUSES = ['active', 'under_cancellation'];

    /**
     * The timezone of the calendar day Alunta's end timestamps end, part of the Alunta API's wire
     * encoding: the OpenAPI specification returns end_date as "the end of that day in Danish time
     * (Europe/Copenhagen), expressed in UTC", and current_period_end "like end_date" (Subscription
     * schema, end_date and current_period_end). It does not depend on the team's timezone setting
     * (GET /me), so it is never read from there or from app configuration.
     */
    private const string ALUNTA_TIMEZONE = 'Europe/Copenhagen';

    /**
     * The most Alunta requests one sync of a subscription not paid by invoice sends: the
     * subscription, and for a first grant or a failed renewal its transactions, one invoice and
     * that invoice's payments.
     */
    public const int MAX_REQUESTS = 4;

    /**
     * The most Alunta requests one sync of an invoice-paid subscription sends: the subscription,
     * and the pages of its invoiced periods and the invoices InvoiceStandingReader reads (8).
     */
    public const int MAX_INVOICE_REQUESTS = 1 + InvoiceStandingReader::MAX_TRANSACTION_PAGES + InvoiceStandingReader::MAX_INVOICE_READS;

    /**
     * How long a sync may hold its lock: the larger request budget, MAX_INVOICE_REQUESTS, at the
     * client's worst case (8 × 95 = 760 seconds), plus a minute for the database work. The lock is
     * taken before the subscription shows how it is paid. A sync whose lock expired anyway applies
     * nothing (see readAndApply()).
     */
    public const int LOCK_SECONDS = self::MAX_INVOICE_REQUESTS * AluntaApiClient::WORST_CASE_REQUEST_SECONDS + 60;

    /**
     * How long a sync waits for another sync of the same subscription before it gives up.
     */
    private const int LOCK_WAIT_SECONDS = 30;

    /**
     * How long a refusal without a pending checkout attempt stays reported, and a refused grant of
     * a bound billable holds its subscription off the reconciliation.
     */
    private const int REPORT_AGAIN_AFTER_HOURS = 24;

    /**
     * The refusals that keep the bound billable from following its subscription.
     */
    private const array GRANT_REFUSALS = ['identity_mismatch', 'unknown_plan', 'payment_provider_not_card', 'first_period_deferred', 'first_period_unsettled'];

    public function __construct(
        private AluntaApiClient $client,
        private BillableRepository $billables,
        private BillableIdentity $identity,
        private PlanMapper $plans,
        private SettlementPolicy $settlement,
        private CheckoutAttempt $attempts,
        private InvoiceAccessPolicy $invoiceAccess,
        private InvoiceStandingReader $invoiceStandings,
    ) {}

    /**
     * The cache lock that serializes the syncs of one subscription.
     */
    public static function lockKey(string $subscriptionUuid): string
    {
        return 'alunta-subscription-sync:'.strtolower($subscriptionUuid);
    }

    /**
     * Whether a refused grant of its bound billable holds the subscription off the
     * reconciliation's due syncs.
     */
    public static function isHeldOff(string $subscriptionUuid): bool
    {
        return Cache::has(self::heldOffKey($subscriptionUuid));
    }

    private static function heldOffKey(string $subscriptionUuid): string
    {
        return 'alunta-subscription-held-off:'.strtolower($subscriptionUuid);
    }

    /**
     * Reads the subscription back and applies it; returns the changed billable, or null when
     * nothing was changed.
     *
     * @throws AluntaApiException when Alunta cannot be read
     * @throws LockTimeoutException when another sync of the subscription holds the lock too long
     */
    public function sync(string $subscriptionUuid): ?Billable
    {
        $lock = Cache::lock(self::lockKey($subscriptionUuid), self::LOCK_SECONDS);
        $lock->block(self::LOCK_WAIT_SECONDS);

        try {
            return $this->readAndApply($subscriptionUuid, $lock);
        } finally {
            $lock->release();
        }
    }

    /**
     * Reads the billable's stored open invoice again on its own (InvoiceStandingReader::invoiceOpenness())
     * and returns how Alunta shows it now. When it is closed (paid in full, cancelled, credited or a
     * credit note), the stored open invoice is cleared under the billable's row lock, while that
     * same invoice is still stored. An open or unclear invoice changes nothing. A billable with no
     * stored open invoice owes nothing here: 'closed', without a read.
     *
     * Meant for a billable no running subscription grants any more (its subscription ended, end()
     * kept the invoice): its syncs never write the open invoice again, so this read cannot race one.
     * A granted billable's open invoice follows its subscription's syncs instead.
     *
     * @return 'open'|'closed'|'unclear'
     *
     * @throws AluntaApiException when Alunta cannot be read
     */
    public function recheckOpenInvoice(Billable $billable): string
    {
        $openInvoiceId = $billable->billingState()->openInvoiceId;

        if ($openInvoiceId === null) {
            return 'closed';
        }

        $openness = $this->invoiceStandings->invoiceOpenness($openInvoiceId);

        if ($openness !== 'closed') {
            return $openness;
        }

        DB::transaction(function () use ($billable, $openInvoiceId): void {
            $locked = $this->billables->lockForBilling($billable->getBillingKey());
            $storedId = $locked->billingState()->openInvoiceId;

            if ($storedId !== null && strcasecmp($storedId, $openInvoiceId) === 0) {
                $locked->applyBillingChange(BillingChange::make()
                    ->with(BillingField::OpenInvoiceId, null)
                    ->with(BillingField::OpenInvoiceDueOn, null)
                    ->with(BillingField::OpenInvoiceOutstanding, null)
                    ->with(BillingField::OpenInvoiceFirstSeenAt, null));
            }
        });

        return 'closed';
    }

    /**
     * Reads, then applies under the billable's row lock, unless the subscription lock expired
     * meanwhile: another sync may then have applied a newer read.
     *
     * @throws AluntaApiException
     */
    private function readAndApply(string $subscriptionUuid, Lock $lock): ?Billable
    {
        $subscription = $this->read($subscriptionUuid);
        $claimedReferences = $this->claimedReferences($subscription);
        $boundBillable = $this->billables->boundTo($subscriptionUuid)[0] ?? null;
        $endsBoundBillable = $boundBillable !== null && ($subscription['status'] ?? null) === 'cancelled';

        if (count($claimedReferences) > 1 && ! $endsBoundBillable) {
            $this->refuse('identity_mismatch', $subscriptionUuid, $boundBillable);

            return null;
        }

        $claimedReference = $claimedReferences[0] ?? null;
        $billableKey = $boundBillable?->getBillingKey()
            ?? ($claimedReference !== null ? $this->billables->findByReference($claimedReference)?->getBillingKey() : null);

        if ($billableKey === null) {
            Log::info('Alunta subscription belongs to no organization.', ['subscription_uuid' => $subscriptionUuid]);

            return null;
        }

        $unlockedState = $this->billables->find($billableKey)?->billingState();
        $mayBeGranted = in_array($subscription['status'] ?? null, self::GRANTABLE_STATUSES, true)
            && $this->mappedPlan($subscription) !== null
            && $this->mayGrantProvider($subscription, $subscriptionUuid, $unlockedState);
        $mayBeFirstGrant = $unlockedState !== null
            && $unlockedState->paidPlan === null
            && in_array($unlockedState->provider, [null, 'alunta'], true);
        $isPaidByInvoice = $mayBeGranted && $this->isPaidByInvoice($subscription);
        $firstPeriodSettlement = null;
        $invoiceStanding = null;

        if ($isPaidByInvoice) {
            $storedOpenInvoiceId = $unlockedState?->subscriptionId === $subscriptionUuid ? $unlockedState->openInvoiceId : null;
            $invoiceStanding = $this->invoiceStandings->read($subscription, $subscriptionUuid, $mayBeFirstGrant, $storedOpenInvoiceId);

            if (! $invoiceStanding->isKnown()) {
                self::warnUnknownStanding($subscriptionUuid, $billableKey, $invoiceStanding);

                return null;
            }
        } elseif ($mayBeGranted && $mayBeFirstGrant) {
            $firstPeriodSettlement = $this->firstBilledInvoiceSettlement($subscription, $subscriptionUuid);
        }

        $renewalPaidAt = $mayBeGranted && ! $isPaidByInvoice && ! $mayBeFirstGrant && $unlockedState?->subscriptionId === $subscriptionUuid && $unlockedState->paymentFailedAt !== null
            ? $this->renewalPaidAt($subscriptionUuid, self::lastFailureAt($unlockedState->paymentFailedAt, $unlockedState->lastPaymentEventAt))
            : null;

        $applied = DB::transaction(function () use ($subscription, $subscriptionUuid, $claimedReference, $billableKey, $firstPeriodSettlement, $renewalPaidAt, $invoiceStanding, $lock): ?Billable {
            $billable = $this->billables->lockForBilling($billableKey);

            if ($lock instanceof CacheLock && ! $lock->isOwnedByCurrentProcess()) {
                Log::warning('Alunta subscription sync lost its lock before applying; nothing applied.', ['subscription_uuid' => $subscriptionUuid]);

                return null;
            }

            if (! $this->mayApply($billable, $subscription, $subscriptionUuid, $claimedReference)) {
                return null;
            }

            return match ($subscription['status'] ?? null) {
                'active', 'under_cancellation' => $this->grant($billable, $subscription, $subscriptionUuid, $firstPeriodSettlement, $renewalPaidAt, $invoiceStanding),
                'cancelled' => $this->end($billable, $subscription, $subscriptionUuid),
                default => null,
            };
        });

        if ($applied !== null) {
            Cache::forget(self::heldOffKey($subscriptionUuid));
        }

        return $applied;
    }

    /**
     * Whether the subscription is paid by invoice: its SettlementPolicy asks for an issued first
     * invoice, so its access follows its invoices rather than counted payments.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function isPaidByInvoice(array $subscription): bool
    {
        return $this->settlement->firstGrantRequirement($subscription) === FirstGrantRequirement::IssuedFirstInvoice;
    }

    private static function warnUnknownStanding(string $subscriptionUuid, int|string $billableKey, InvoiceStanding $standing): void
    {
        Log::warning('Alunta invoice standing is unknown; nothing applied.', [
            'subscription_uuid' => $subscriptionUuid,
            'organization_id' => $billableKey,
            'reason' => $standing->unknownReason,
        ]);
    }

    /**
     * How far Alunta shows the subscription's first billed invoice
     * (InvoiceStandingReader::firstBilledInvoice()) settled (invoiceSettlement()). 'unpaid' also
     * covers a first period that is not invoiced yet: a card checkout pays it at once, so a later
     * sync can settle it. A payment event alone proves none of this, because a payment can be
     * partial, manual, or not linked to an invoice.
     *
     * @param  array<string, mixed>  $subscription
     * @return 'settled'|'unpaid'|'unsettled'
     *
     * @throws AluntaApiException
     */
    private function firstBilledInvoiceSettlement(array $subscription, string $subscriptionUuid): string
    {
        $first = InvoiceStandingReader::firstBilledInvoice($subscription, $this->client->listSubscriptionTransactions($subscriptionUuid));

        return match ($first['status']) {
            'none' => 'unpaid',
            'mismatch' => 'unsettled',
            'found' => $this->invoiceSettlement((string) $first['uuid'])['status'],
        };
    }

    /**
     * When Alunta shows the subscription's newest billed period settled by a counted payment made
     * after $failedAt: the time of the newest such payment, or null without that evidence.
     *
     * Renewals are billed in order, so the newest billed period started at or after the period
     * whose payment failed. Its invoice must be settled as invoiceSettlement() requires, and at
     * least one of the counted payments that settle it must be created after $failedAt.
     *
     * @throws AluntaApiException
     */
    private function renewalPaidAt(string $subscriptionUuid, CarbonImmutable $failedAt): ?CarbonImmutable
    {
        $billed = array_values(array_filter(
            $this->client->listSubscriptionTransactions($subscriptionUuid),
            fn (array $transaction): bool => is_array($transaction['invoice'] ?? null) && is_string($transaction['invoice']['uuid'] ?? null) && is_string($transaction['period_start'] ?? null),
        ));
        usort($billed, fn (array $a, array $b): int => strcmp($b['period_start'], $a['period_start']));
        $newest = $billed[0]['invoice'] ?? null;
        $invoicePaidAt = AluntaAccount::timestamp($newest['paid_at'] ?? null);

        if (! is_string($newest['uuid'] ?? null) || ! Str::isUuid($newest['uuid']) || $invoicePaidAt === null || $invoicePaidAt->lessThanOrEqualTo($failedAt)) {
            return null;
        }

        $settlement = $this->invoiceSettlement($newest['uuid']);

        return $settlement['status'] === 'settled' && $settlement['paid_at']?->greaterThan($failedAt) ? $settlement['paid_at'] : null;
    }

    /**
     * How far Alunta shows the invoice settled, and when its newest counted payment was made:
     *
     * - 'settled': the invoice is fully paid (status paid or overpaid, nothing outstanding, not
     *   cancelled), and the payments SettlementPolicy::countsPayment() counts cover its total with
     *   VAT. The policy is told whether the Alunta account is a test account
     *   (services.alunta.test_mode).
     * - 'unpaid': the invoice is a draft or issued with nothing paid.
     * - 'unsettled': anything else, for example paid by hand, partly paid, credited, or paid by
     *   payments the policy does not count.
     *
     * @return array{status: 'settled'|'unpaid'|'unsettled', paid_at: ?CarbonImmutable}
     *
     * @throws AluntaApiException
     */
    private function invoiceSettlement(string $invoiceUuid): array
    {
        $invoice = $this->client->getInvoice($invoiceUuid);
        $status = $invoice['status'] ?? null;
        $totalWithVat = $invoice['total_with_vat'] ?? null;

        if (in_array($status, ['draft', 'issued'], true) && ($invoice['is_cancelled'] ?? false) === false) {
            return ['status' => 'unpaid', 'paid_at' => null];
        }

        if (! in_array($status, ['paid', 'overpaid'], true) || ($invoice['is_cancelled'] ?? false) !== false
            || ($invoice['outstanding'] ?? null) !== 0 || ! is_int($totalWithVat) || $totalWithVat <= 0) {
            return ['status' => 'unsettled', 'paid_at' => null];
        }

        $paidByCountedPayments = 0;
        $paidAt = null;
        $isTestAccount = config('services.alunta.test_mode') === true;

        foreach ($this->client->listInvoicePayments($invoiceUuid) as $payment) {
            $paymentInvoiceUuid = is_array($payment['invoice'] ?? null) ? ($payment['invoice']['uuid'] ?? null) : null;

            if ($paymentInvoiceUuid === $invoiceUuid && $this->settlement->countsPayment($payment, $isTestAccount) && is_int($payment['price'] ?? null)) {
                $paidByCountedPayments += $payment['price'];
                $createdAt = AluntaAccount::timestamp($payment['created_at'] ?? null);
                $paidAt = $createdAt !== null && ($paidAt === null || $createdAt->greaterThan($paidAt)) ? $createdAt : $paidAt;
            }
        }

        return $paidByCountedPayments >= $totalWithVat
            ? ['status' => 'settled', 'paid_at' => $paidAt]
            : ['status' => 'unsettled', 'paid_at' => null];
    }

    /**
     * The newest payment event applied to the billable while its payment fails: the last failure
     * (BillingState::$lastPaymentEventAt), never before the first one (BillingState::$paymentFailedAt).
     */
    private static function lastFailureAt(CarbonImmutable $paymentFailedAt, ?CarbonImmutable $lastPaymentEventAt): CarbonImmutable
    {
        $firstFailureAt = $paymentFailedAt->utc();
        $lastEventAt = $lastPaymentEventAt?->utc();

        return $lastEventAt !== null && $lastEventAt->greaterThan($firstFailureAt) ? $lastEventAt : $firstFailureAt;
    }

    /**
     * The subscription as Alunta returns it now. A 404 for a bound subscription marks its
     * billables and is reported before it is rethrown; their plan stays as it is.
     *
     * @return array<string, mixed>
     *
     * @throws AluntaApiException
     */
    private function read(string $subscriptionUuid): array
    {
        try {
            return $this->client->getSubscription($subscriptionUuid);
        } catch (AluntaApiException $exception) {
            if ($exception->errorCode === AluntaApiException::NOT_FOUND) {
                foreach ($this->billables->boundTo($subscriptionUuid) as $boundBillable) {
                    try {
                        $billable = DB::transaction(function () use ($boundBillable): Billable {
                            $billable = $this->billables->lockForBilling($boundBillable->getBillingKey());
                            $billable->applyBillingChange(BillingChange::make()->with(BillingField::SubscriptionMissingAt, now()));

                            return $billable;
                        });
                    } catch (ModelNotFoundException) {
                        continue;
                    }

                    $this->refuse('subscription_not_found', $subscriptionUuid, $billable);
                }
            }

            throw $exception;
        }
    }

    /**
     * Whether the subscription may change the locked billable.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function mayApply(Billable $billable, array $subscription, string $subscriptionUuid, ?string $claimedReference): bool
    {
        $state = $billable->billingState();
        $isBound = $state->subscriptionId === $subscriptionUuid;

        if ($isBound && ($subscription['status'] ?? null) === 'cancelled') {
            return true;
        }

        if ($claimedReference !== null && $claimedReference !== $this->referenceOf($billable)) {
            $this->refuse('identity_mismatch', $subscriptionUuid, $billable);

            return false;
        }

        if ($isBound) {
            return true;
        }

        if (! in_array($subscription['status'] ?? null, self::GRANTABLE_STATUSES, true)) {
            return false;
        }

        $setByHand = ($state->provider !== null && $state->provider !== 'alunta')
            || ($state->subscriptionId === null && $state->paidPlan !== null);

        if ($setByHand) {
            Log::info('Alunta subscription not applied to an organization whose plan was set by hand.', [
                'subscription_uuid' => $subscriptionUuid,
                'organization_id' => $billable->getBillingKey(),
            ]);

            return false;
        }

        if ($state->subscriptionId !== null && $state->paidPlan !== null) {
            $this->refuse('already_bound', $subscriptionUuid, $billable);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  'settled'|'unpaid'|'unsettled'|null  $firstPeriodSettlement  null when it was not read
     * @param  CarbonImmutable|null  $renewalPaidAt  when a renewal was paid after the failed payment (renewalPaidAt()); null when it was not read or not shown
     * @param  InvoiceStanding|null  $invoiceStanding  the known standing of an invoice-paid subscription; null when it was not read
     */
    private function grant(Billable $billable, array $subscription, string $subscriptionUuid, ?string $firstPeriodSettlement, ?CarbonImmutable $renewalPaidAt, ?InvoiceStanding $invoiceStanding): ?Billable
    {
        $plan = $this->mappedPlan($subscription);

        if ($plan === null) {
            $this->refuse('unknown_plan', $subscriptionUuid, $billable);

            return null;
        }

        $state = $billable->billingState();

        if (! $this->mayGrantProvider($subscription, $subscriptionUuid, $state)) {
            $this->refuse('payment_provider_not_card', $subscriptionUuid, $billable);

            return null;
        }

        $isNewBinding = $state->subscriptionId !== $subscriptionUuid;
        $isFirstGrant = $isNewBinding || $state->paidPlan === null;

        if ($isNewBinding && ! $this->customerNamesBillable($subscription, $billable)) {
            $this->refuse('customer_unverified', $subscriptionUuid, $billable);

            return null;
        }

        $isPaidByInvoice = $this->isPaidByInvoice($subscription);

        if ($isPaidByInvoice && $invoiceStanding === null) {
            // The billable changed between the unlocked read and its lock, so its invoices were not read.
            self::warnUnknownStanding($subscriptionUuid, $billable->getBillingKey(), InvoiceStanding::unknown('not_read'));

            return null;
        }

        $firstPeriod = $isPaidByInvoice ? $invoiceStanding->firstInvoice : $firstPeriodSettlement;

        if ($isFirstGrant && $firstPeriod !== ($isPaidByInvoice ? 'issued' : 'settled')) {
            if (($subscription['defers_first_partial_period'] ?? false) === true) {
                // The Alunta team's renewal setting "charge the first partial period with the next renewal" must be off.
                $this->refuse('first_period_deferred', $subscriptionUuid, $billable);
            } elseif ($firstPeriod === 'unsettled') {
                $this->refuse('first_period_unsettled', $subscriptionUuid, $billable);
            } else {
                Log::info($isPaidByInvoice ? 'Alunta subscription not granted before its first invoice is issued.' : 'Alunta subscription not granted before its first invoice is paid.', [
                    'subscription_uuid' => $subscriptionUuid,
                    'organization_id' => $billable->getBillingKey(),
                ]);
            }

            return null;
        }

        $changeStatus = self::field($subscription, 'scheduled_plan_change', 'status');
        $scheduledPlan = in_array($changeStatus, ['scheduled', 'failed'], true)
            ? $this->plans->localKeyFor(self::field($subscription, 'scheduled_plan_change', 'target_plan', 'uuid'))
            : null;
        $scheduledOn = $scheduledPlan !== null ? self::date(self::field($subscription, 'scheduled_plan_change', 'effective_date')) : null;
        $changeFailed = $changeStatus === 'failed';

        if ($changeFailed && ! $state->scheduledPlanChangeFailed) {
            $this->refuse('scheduled_plan_change_failed', $subscriptionUuid, $billable);
        }

        $isRenewalPaid = ! $isNewBinding && $state->paymentFailedAt !== null
            && $renewalPaidAt?->greaterThan(self::lastFailureAt($state->paymentFailedAt, $state->lastPaymentEventAt)) === true;

        $change = $this->binding($billable, $subscription, $subscriptionUuid);

        if ($isNewBinding) {
            $change = $change->with(BillingField::PaymentFailedAt, null)->with(BillingField::LastPaymentEventAt, null);
        }

        if ($isRenewalPaid) {
            $change = $change->with(BillingField::PaymentFailedAt, null)->with(BillingField::LastPaymentEventAt, $renewalPaidAt);
        }

        $status = self::field($subscription, 'trial', 'is_active') === true ? 'trialing' : 'active';

        if ($invoiceStanding !== null && $isPaidByInvoice) {
            $change = $this->withOpenInvoice($change, $state, $invoiceStanding);

            if ($this->closesAccess($billable, $state, $subscriptionUuid, $change)) {
                $status = 'past_due';
            }
        } else {
            $change = $change
                ->with(BillingField::OpenInvoiceId, null)
                ->with(BillingField::OpenInvoiceDueOn, null)
                ->with(BillingField::OpenInvoiceOutstanding, null)
                ->with(BillingField::OpenInvoiceFirstSeenAt, null);
        }

        $paymentProvider = $subscription['payment_provider'] ?? null;

        $billable->applyBillingChange($change
            ->with(BillingField::PaidPlan, $plan)
            ->with(BillingField::Status, $status)
            ->with(BillingField::PaymentProvider, is_string($paymentProvider) && $paymentProvider !== '' ? $paymentProvider : null)
            ->with(BillingField::CurrentPeriodStartsAt, self::date($subscription['current_period_start'] ?? null)?->startOfDay())
            ->with(BillingField::CurrentPeriodEndsAt, self::endOfAluntaDay($subscription['current_period_end'] ?? null))
            ->with(BillingField::SubscriptionEndsAt, ($subscription['status'] ?? null) === 'under_cancellation' ? self::endOfAluntaDay($subscription['end_date'] ?? null) : null)
            ->with(BillingField::ScheduledPlan, $scheduledOn !== null ? $scheduledPlan : null)
            ->with(BillingField::ScheduledPlanChangeOn, $scheduledOn)
            ->with(BillingField::ScheduledPlanChangeFailed, $changeFailed));

        return $billable;
    }

    /**
     * The change with the open invoice of the standing, or with none. The first time the invoice is
     * seen is kept while the same invoice stays open; it is the due day when the invoice has no
     * date at all, so a missing date never keeps access open.
     */
    private function withOpenInvoice(BillingChange $change, BillingState $state, InvoiceStanding $standing): BillingChange
    {
        $openInvoiceId = $standing->openInvoiceId;

        if ($openInvoiceId === null) {
            return $change
                ->with(BillingField::OpenInvoiceId, null)
                ->with(BillingField::OpenInvoiceDueOn, null)
                ->with(BillingField::OpenInvoiceOutstanding, null)
                ->with(BillingField::OpenInvoiceFirstSeenAt, null);
        }

        $isSameInvoice = $state->openInvoiceId !== null && strcasecmp($state->openInvoiceId, $openInvoiceId) === 0;
        $firstSeenAt = $isSameInvoice && $state->openInvoiceFirstSeenAt !== null ? $state->openInvoiceFirstSeenAt : CarbonImmutable::now();
        $dueOn = $standing->openInvoiceDueOn
            ?? CarbonImmutable::parse($firstSeenAt->setTimezone(self::ALUNTA_TIMEZONE)->toDateString(), 'UTC');

        return $change
            ->with(BillingField::OpenInvoiceId, $openInvoiceId)
            ->with(BillingField::OpenInvoiceDueOn, $dueOn)
            ->with(BillingField::OpenInvoiceOutstanding, $standing->openInvoiceOutstanding)
            ->with(BillingField::OpenInvoiceFirstSeenAt, $firstSeenAt);
    }

    /**
     * Whether the open invoice the change writes closes access now (InvoiceAccessPolicy). A change
     * to past due is logged as a warning.
     */
    private function closesAccess(Billable $billable, BillingState $state, string $subscriptionUuid, BillingChange $change): bool
    {
        $dueOn = $change->value(BillingField::OpenInvoiceDueOn);

        if (! $dueOn instanceof CarbonImmutable) {
            return false;
        }

        $closesAt = $this->invoiceAccess->accessClosesAt($dueOn);

        if (CarbonImmutable::now()->lessThan($closesAt)) {
            return false;
        }

        if ($state->status !== 'past_due') {
            Log::warning('Alunta subscription access closed for an unpaid invoice.', [
                'subscription_uuid' => $subscriptionUuid,
                'organization_id' => $billable->getBillingKey(),
                'invoice_uuid' => $change->value(BillingField::OpenInvoiceId),
                'due_on' => $dueOn->toDateString(),
                'access_closes_at' => $closesAt->utc()->toIso8601String(),
            ]);
        }

        return true;
    }

    /**
     * Keeps the last known open invoice, which a later checkout may need.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function end(Billable $billable, array $subscription, string $subscriptionUuid): Billable
    {
        $billable->applyBillingChange($this->binding($billable, $subscription, $subscriptionUuid)
            ->with(BillingField::PaidPlan, null)
            ->with(BillingField::Status, 'active')
            ->with(BillingField::CurrentPeriodStartsAt, null)
            ->with(BillingField::CurrentPeriodEndsAt, null)
            ->with(BillingField::SubscriptionEndsAt, null)
            ->with(BillingField::PaymentFailedAt, null)
            ->with(BillingField::ScheduledPlan, null)
            ->with(BillingField::ScheduledPlanChangeOn, null)
            ->with(BillingField::ScheduledPlanChangeFailed, false));

        return $billable;
    }

    /**
     * The billing fields every applied subscription writes.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function binding(Billable $billable, array $subscription, string $subscriptionUuid): BillingChange
    {
        return BillingChange::make()
            ->with(BillingField::Provider, 'alunta')
            ->with(BillingField::SubscriptionId, $subscriptionUuid)
            ->with(BillingField::CustomerId, $this->verifiedCustomer($billable, $billable->billingState(), $subscription, $subscriptionUuid))
            ->with(BillingField::SyncedAt, now())
            ->with(BillingField::SubscriptionMissingAt, null);
    }

    /**
     * The billable's Alunta customer after this subscription: the subscription's customer only when
     * it names the billable (customerNamesBillable()), otherwise the customer it has. A different
     * customer on the subscription the billable is already bound to is reported, never applied.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function verifiedCustomer(Billable $billable, BillingState $state, array $subscription, string $subscriptionUuid): ?string
    {
        $currentCustomerUuid = $state->customerId;
        $customerUuid = self::field($subscription, 'customer', 'uuid');

        if (! is_string($customerUuid) || ! Str::isUuid($customerUuid) || strcasecmp($customerUuid, (string) $currentCustomerUuid) === 0) {
            return $currentCustomerUuid;
        }

        if ($currentCustomerUuid !== null && $state->subscriptionId === $subscriptionUuid) {
            $this->refuse('customer_changed', $subscriptionUuid, $billable);

            return $currentCustomerUuid;
        }

        if (! $this->customerNamesBillable($subscription, $billable)) {
            $this->refuse('customer_unverified', $subscriptionUuid, $billable);

            return $currentCustomerUuid;
        }

        return $customerUuid;
    }

    /**
     * Whether the subscription's customer is the billable's: its own external customer id is the
     * one the checkout sends for the billable (BillableIdentity::externalCustomerId()).
     *
     * @param  array<string, mixed>  $subscription
     */
    private function customerNamesBillable(array $subscription, Billable $billable): bool
    {
        $reference = $this->referenceOf($billable);

        return $reference !== null
            && $this->identity->referenceFromExternalCustomerId(self::field($subscription, 'customer', 'external_customer_id')) === $reference;
    }

    /**
     * The billable's reference normalized as the identity reads references from Alunta: its own
     * external customer id read back. Null when the identity cannot read it, so it never matches.
     */
    private function referenceOf(Billable $billable): ?string
    {
        return $this->identity->referenceFromExternalCustomerId($this->identity->externalCustomerId($billable));
    }

    /**
     * Logs and reports the refusal: with an unresolved checkout attempt, only the first time for
     * that attempt and reason; without one, once a day per subscription and reason. A refused grant
     * of the billable bound to the subscription also holds the subscription off the reconciliation
     * for that day.
     *
     * @param  'identity_mismatch'|'already_bound'|'unknown_plan'|'payment_provider_not_card'|'first_period_deferred'|'first_period_unsettled'|'scheduled_plan_change_failed'|'subscription_not_found'|'customer_changed'|'customer_unverified'  $reason
     */
    private function refuse(string $reason, string $subscriptionUuid, ?Billable $billable = null): void
    {
        $againAt = now()->addHours(self::REPORT_AGAIN_AFTER_HOURS);

        if ($billable?->billingState()->subscriptionId === $subscriptionUuid && in_array($reason, self::GRANT_REFUSALS, true)) {
            Cache::put(self::heldOffKey($subscriptionUuid), true, $againAt);
        }

        $isFirstReport = ($billable !== null ? $this->attempts::unresolvedFor($billable)?->recordAlert($reason) : null)
            ?? Cache::add('alunta-subscription-refused:'.strtolower($subscriptionUuid).':'.$reason, true, $againAt);

        if (! $isFirstReport) {
            return;
        }

        $exception = new AluntaSyncException($reason, $subscriptionUuid, $billable?->getBillingKey());

        Log::warning('Alunta subscription was not applied.', $exception->context());
        report($exception);
    }

    /**
     * The distinct billable references the subscription names: in its own or its customer's
     * external customer id, and in its metadata (BillableIdentity).
     *
     * @param  array<string, mixed>  $subscription
     * @return list<string>
     */
    private function claimedReferences(array $subscription): array
    {
        $claims = [
            $this->identity->referenceFromExternalCustomerId($subscription['external_customer_id'] ?? null),
            $this->identity->referenceFromExternalCustomerId(self::field($subscription, 'customer', 'external_customer_id')),
            $this->identity->referenceFromMetadata($subscription['metadata'] ?? null),
        ];

        return array_values(array_unique(array_filter($claims, is_string(...))));
    }

    /**
     * Whether the subscription's payment provider may be granted: the SettlementPolicy grants it,
     * or the billable was granted on this subscription with this same provider. The policy decides
     * new grants only, so switching a provider off never revokes or freezes a running grant, and
     * an invoice-paid one keeps closing and reopening on its invoices. A provider that is neither
     * a string nor missing is refused here, before the policy sees it.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function mayGrantProvider(array $subscription, string $subscriptionUuid, ?BillingState $state): bool
    {
        $paymentProvider = $subscription['payment_provider'] ?? null;

        if ($paymentProvider !== null && ! is_string($paymentProvider)) {
            return false;
        }

        if ($this->settlement->mayGrantProvider($paymentProvider)) {
            return true;
        }

        return $paymentProvider !== null
            && $state !== null
            && $state->subscriptionId === $subscriptionUuid
            && $state->paidPlan !== null
            && $state->paymentProvider === $paymentProvider;
    }

    /**
     * A field of a nested object of the subscription, following the path of keys, or null when any
     * object on the path is missing.
     *
     * @param  array<string, mixed>  $subscription
     */
    private static function field(array $subscription, string $object, string ...$path): mixed
    {
        $value = $subscription[$object] ?? null;

        foreach ($path as $key) {
            if (! is_array($value)) {
                return null;
            }

            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * The calendar date of an Alunta date or of a start date at 00:00:00 UTC, as midnight UTC.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) ? CarbonImmutable::parse(substr($value, 0, 10), 'UTC') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The local paid plan key the PlanMapper configures for the subscription's Alunta plan, or null.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function mappedPlan(array $subscription): ?string
    {
        return $this->plans->localKeyFor(self::field($subscription, 'plan', 'uuid'));
    }

    /**
     * The last moment, in UTC, of the calendar day in ALUNTA_TIMEZONE an Alunta end timestamp ends.
     * Alunta sends the end of the day in Danish time expressed in UTC (22:59:59 in winter, 21:59:59
     * in summer), so the period is stored to the end of that day in the app's UTC calendar.
     */
    private static function endOfAluntaDay(mixed $value): ?CarbonImmutable
    {
        try {
            if (! is_string($value)) {
                return null;
            }

            $danishDate = CarbonImmutable::parse($value)->setTimezone(self::ALUNTA_TIMEZONE)->toDateString();

            return CarbonImmutable::parse($danishDate, 'UTC')->endOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
