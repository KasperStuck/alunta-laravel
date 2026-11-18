<?php

namespace KasperStuck\Alunta\Laravel\Checkout;

use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KasperStuck\Alunta\Laravel\AluntaAccount;
use KasperStuck\Alunta\Laravel\AluntaApiClient;
use KasperStuck\Alunta\Laravel\Contracts\Billable;
use KasperStuck\Alunta\Laravel\Contracts\BillableIdentity;
use KasperStuck\Alunta\Laravel\Contracts\BillableRepository;
use KasperStuck\Alunta\Laravel\Contracts\PlanMapper;
use KasperStuck\Alunta\Laravel\Sync\AluntaSubscriptionSync;
use KasperStuck\Alunta\Sdk\AluntaApiException;

/**
 * Starts the purchase of a paid plan in an Alunta checkout session. Every completed session creates
 * a subscription and a charge, so a billable has at most one unresolved (pending) checkout attempt
 * (CheckoutAttempt), whatever the plan:
 *
 * - A new attempt opens only while the billable is purchasable, checked again under its row lock
 *   (BillableRepository::lockForBilling()), so a stale page cannot buy a second subscription.
 * - While an invoice is stored unpaid past its due date (owesInvoicePastDue()), no attempt opens
 *   and none is sent: the invoice is read again first (AluntaSubscriptionSync::recheckOpenInvoice()),
 *   and only Alunta showing it closed clears it and lets the checkout go on. Still owed, unclear,
 *   or an Alunta error refuses (UNPAID_INVOICE, or the AluntaApiException), so an unclear answer
 *   never counts as no debt.
 * - While the session lives, a request for the same plan replays it with the same Idempotency-Key
 *   and the request body stored on the attempt. A request for another plan is refused.
 * - One request at a time sends an attempt (sendLockKey()) and reads it again under that lock.
 * - A failed create expires the attempt only when Alunta definitely refused it (a 4xx other than
 *   409 and 429) and the attempt as stored still has no session. After an unknown outcome
 *   the attempt stays, and the next request replays the same POST to learn its session.
 * - Once the session has expired, a running subscription Alunta holds for the billable is bound
 *   instead, and a new session starts only when Alunta says the old one is safe to restart
 *   (can_restart), does not know it, or completed it with a subscription that has since ended.
 *   Expiry alone is not enough.
 *
 * The session names the billable in external_customer_id and in its metadata (BillableIdentity),
 * which Alunta stores on the subscription; the app's attempt model adds the rest of the metadata
 * (CheckoutAttempt::checkoutMetadata()). Plans are the PlanMapper's local paid plan keys. The
 * checkout never grants anything: the plan changes when the subscription is synced. Refusals are
 * DomainExceptions with a Danish message for the owner; the messages are those of the first
 * consumer and stay unchanged.
 */
class AluntaCheckoutService
{
    public const string IN_PROGRESS = 'Du har allerede et påbegyndt køb. Gennemfør eller vent, til det udløber.';

    public const string ALREADY_COMPLETED = 'Købet er allerede gennemført. Siden opdateres, når Alunta har bekræftet betalingen.';

    public const string EXPIRED = 'Det påbegyndte køb er udløbet. Prøv igen.';

    public const string NOT_APPLIED = 'Vi har modtaget dit køb, men kunne ikke aktivere planen automatisk. Kontakt os, så hjælper vi.';

    public const string NOT_PURCHASABLE = 'Organisationen har allerede et betalt abonnement.';

    public const string UNPAID_INVOICE = 'Der er en ubetalt faktura. Betal den, før du køber et nyt abonnement.';

    /**
     * The timezone of Alunta's calendar days, in which an invoice's due date is a day (see
     * AluntaSubscriptionSync::ALUNTA_TIMEZONE).
     */
    private const string ALUNTA_TIMEZONE = 'Europe/Copenhagen';

    /**
     * The subscription statuses that mean a checkout already bought something.
     */
    private const array RUNNING_STATUSES = ['pending', 'active', 'under_cancellation'];

    /**
     * How long one request may hold an attempt's send lock: the client's slowest possible call plus
     * a margin for the database work around it.
     */
    private const int SEND_LOCK_SECONDS = AluntaApiClient::WORST_CASE_REQUEST_SECONDS + 30;

    /**
     * How long a request waits for another request sending the same attempt before it gives up.
     */
    private const int SEND_LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private AluntaApiClient $client,
        private AluntaSubscriptionSync $sync,
        private BillableRepository $billables,
        private BillableIdentity $identity,
        private PlanMapper $plans,
    ) {}

    /**
     * The Alunta checkout URL to send the billable's owner to. The URL is a capability: redirect to
     * it and never log or store it.
     *
     * @param  string|BackedEnum  $plan  a local paid plan key (PlanMapper), or a backed enum whose value is one
     *
     * @throws InvalidArgumentException for a plan that is not a paid plan, such as the free plan
     * @throws AluntaApiException when the account or plan is not configured, Alunta refuses the session, or a stored unpaid invoice cannot be read
     * @throws DomainException when the billable is not purchasable, owes an invoice past its due date, another checkout is unresolved, or the checkout already completed
     */
    public function checkoutUrl(Billable $billable, string|BackedEnum $plan, string $backUrl, string $successUrl): string
    {
        $plan = $plan instanceof BackedEnum ? (string) $plan->value : $plan;

        if ($this->plans->interval($plan) === null) {
            throw new InvalidArgumentException('The free plan is not bought through Alunta.');
        }

        if (! AluntaAccount::isReady()) {
            throw new AluntaApiException(AluntaApiException::NOT_CONFIGURED, null, 'Betaling via Alunta er ikke sat op.');
        }

        $planUuid = AluntaAccount::planUuid($plan)
            ?? throw new AluntaApiException(AluntaApiException::NOT_CONFIGURED, null, 'Betaling via Alunta er ikke sat op for denne plan.');

        if (! self::holdsNoPaidPlan($billable)) {
            throw new DomainException(self::NOT_PURCHASABLE);
        }

        if (self::owesInvoicePastDue($billable) && $this->sync->recheckOpenInvoice($billable) !== 'closed') {
            throw new DomainException(self::UNPAID_INVOICE);
        }

        $unresolvedAttempt = self::unresolvedAttempt($billable);

        if ($unresolvedAttempt !== null && $unresolvedAttempt->alunta_session_id !== null && ! self::sessionLives($unresolvedAttempt)) {
            $this->resolveExpiredAttempt($billable, $unresolvedAttempt);
        }

        $attempt = $this->openAttempt($billable, $plan, $planUuid, $backUrl, $successUrl);
        $lock = Cache::lock(self::sendLockKey($attempt), self::SEND_LOCK_SECONDS);

        try {
            $lock->block(self::SEND_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw new DomainException(self::IN_PROGRESS);
        }

        try {
            return $this->send($billable, $attempt->refresh(), $planUuid, $backUrl, $successUrl);
        } finally {
            $lock->release();
        }
    }

    /**
     * The cache lock that lets one request at a time send the attempt.
     */
    public static function sendLockKey(CheckoutAttempt $attempt): string
    {
        return 'alunta-checkout-attempt:'.$attempt->id;
    }

    /**
     * Sends the attempt's checkout request and returns the session's URL. Runs under the attempt's
     * send lock with the attempt as stored.
     *
     * @throws AluntaApiException
     * @throws DomainException
     */
    private function send(Billable $billable, CheckoutAttempt $attempt, string $planUuid, string $backUrl, string $successUrl): string
    {
        if ($attempt->status !== CheckoutAttempt::STATUS_PENDING) {
            throw new DomainException($attempt->status === CheckoutAttempt::STATUS_COMPLETED ? self::ALREADY_COMPLETED : self::EXPIRED);
        }

        $payload = $attempt->request_payload ?? $this->requestPayload($billable, $attempt, $planUuid, $attempt->idempotency_key, $backUrl, $successUrl);

        try {
            $session = $this->client->createSubscriptionCheckout(
                planUuid: $payload['plan_id'],
                externalCustomerId: $payload['external_customer_id'],
                backUrl: $payload['back_url'],
                successUrl: $payload['success_url'],
                metadata: $payload['metadata'],
                idempotencyKey: $attempt->idempotency_key,
            );
        } catch (AluntaApiException $exception) {
            if ($exception->errorCode === AluntaApiException::CONFLICT) {
                throw new DomainException($this->retireIfRestartable($attempt) ? self::EXPIRED : self::IN_PROGRESS);
            }

            if (self::definitelyRefused($exception)) {
                self::expireUnsent($attempt);
            }

            throw $exception;
        }

        $attempt->forceFill([
            'alunta_session_id' => $session['id'],
            'request_payload' => $payload,
            'expires_at' => AluntaAccount::timestamp($session['expires_at']) ?? $attempt->expires_at,
        ])->save();

        if ($session['checkout_url'] === null || ! self::sessionLives($attempt)) {
            // A replay found a session that can no longer be paid: settle it like any expired one.
            $this->resolveExpiredAttempt($billable, $attempt);

            throw new DomainException(self::EXPIRED);
        }

        return $session['checkout_url'];
    }

    /**
     * Whether the billable may start buying a paid plan: it has no paid plan, no other billing
     * provider bills it, and it owes no invoice past its due date as stored (owesInvoicePastDue()).
     * A bound Alunta subscription that is active or under cancellation always holds a paid plan
     * here, and a pending one is never bound, so no paid plan rules those out. The billable's
     * unresolved checkout attempt, including a completed purchase that cannot be applied, blocks a
     * new one separately (unresolvedAttempt()). This reads only the stored fields, so a page can
     * call it on every render; checkoutUrl() reads a stored unpaid invoice again before it refuses.
     */
    public static function isPurchasable(Billable $billable): bool
    {
        return self::holdsNoPaidPlan($billable) && ! self::owesInvoicePastDue($billable);
    }

    /**
     * Whether, as stored, the billable has an open invoice past its due date: the day after its due
     * date has begun on Alunta's (Danish) calendar. A stored open invoice without a due day counts
     * as past due. Only an ended subscription's open invoice can block a purchase this way: a billable
     * granted on a subscription holds a paid plan, which blocks a purchase anyway.
     *
     * Known residual: an ended subscription's open invoice that is not yet past due blocks nothing,
     * and the grant of a new subscription then replaces it, so that debt is no longer stored.
     */
    public static function owesInvoicePastDue(Billable $billable): bool
    {
        $state = $billable->billingState();

        if ($state->openInvoiceId === null) {
            return false;
        }

        return $state->openInvoiceDueOn === null
            || $state->openInvoiceDueOn->toDateString() < CarbonImmutable::now(self::ALUNTA_TIMEZONE)->toDateString();
    }

    /**
     * Whether the billable holds no paid plan and no other billing provider bills it: the part of
     * isPurchasable() without the unpaid-invoice block, for a page that must still show what
     * happened to a purchase made before that invoice blocked a new one.
     */
    public static function holdsNoPaidPlan(Billable $billable): bool
    {
        $state = $billable->billingState();

        return $state->paidPlan === null
            && in_array($state->provider, [null, 'alunta'], true);
    }

    /**
     * The billable's unresolved checkout attempt, if any.
     */
    public static function unresolvedAttempt(Billable $billable): ?CheckoutAttempt
    {
        return CheckoutAttempt::appModel()::unresolvedFor($billable);
    }

    /**
     * Whether the attempt's checkout session can still be paid.
     */
    public static function sessionLives(CheckoutAttempt $attempt): bool
    {
        return $attempt->expires_at?->isFuture() ?? false;
    }

    /**
     * Binds the billable to a running subscription Alunta holds for its external customer id, and
     * completes its pending checkout attempts once it is bound. Returns 'bound', 'unbound' when a
     * running subscription exists that sync did not apply (reported by the sync), or 'none'.
     *
     * @return 'bound'|'unbound'|'none'
     *
     * @throws AluntaApiException
     */
    public function bindRunningSubscription(Billable $billable): string
    {
        $runningSubscriptions = array_filter(
            $this->client->listSubscriptionsByExternalCustomer($this->identity->externalCustomerId($billable)),
            fn (array $subscription): bool => in_array($subscription['status'] ?? null, self::RUNNING_STATUSES, true),
        );

        foreach ($runningSubscriptions as $subscription) {
            $subscriptionUuid = $subscription['uuid'] ?? null;

            if (is_string($subscriptionUuid) && $this->sync->sync($subscriptionUuid)?->billingState()->subscriptionId === $subscriptionUuid) {
                CheckoutAttempt::appModel()->newQuery()->forBillable($billable)->pending()->update(['status' => CheckoutAttempt::STATUS_COMPLETED]);

                return 'bound';
            }
        }

        return $runningSubscriptions === [] ? 'none' : 'unbound';
    }

    /**
     * Resolves the pending attempt when Alunta shows that its session can no longer charge: Alunta
     * says it is safe to restart, no longer knows it (both expire the attempt), or completed it with
     * a subscription that has since been cancelled or that Alunta no longer knows (completes the
     * attempt; a completed session is never charged again). Any other state keeps the attempt
     * pending. Returns whether it resolved.
     *
     * @throws AluntaApiException
     */
    public function retireIfRestartable(CheckoutAttempt $attempt): bool
    {
        if ($attempt->alunta_session_id === null) {
            return false;
        }

        try {
            $session = $this->client->getCheckoutSession($attempt->alunta_session_id);
        } catch (AluntaApiException $exception) {
            if ($exception->errorCode !== AluntaApiException::NOT_FOUND) {
                throw $exception;
            }

            return self::resolve($attempt, CheckoutAttempt::STATUS_EXPIRED);
        }

        if ($session['can_restart']) {
            return self::resolve($attempt, CheckoutAttempt::STATUS_EXPIRED);
        }

        if ($session['status'] === 'completed' && $session['subscription_uuid'] !== null && $this->subscriptionEnded($session['subscription_uuid'])) {
            return self::resolve($attempt, CheckoutAttempt::STATUS_COMPLETED);
        }

        return false;
    }

    /**
     * Whether the subscription is cancelled, or Alunta no longer knows it (404).
     *
     * @throws AluntaApiException
     */
    private function subscriptionEnded(string $subscriptionUuid): bool
    {
        try {
            return ($this->client->getSubscription($subscriptionUuid)['status'] ?? null) === 'cancelled';
        } catch (AluntaApiException $exception) {
            if ($exception->errorCode !== AluntaApiException::NOT_FOUND) {
                throw $exception;
            }

            return true;
        }
    }

    /**
     * Gives the attempt the status while it is still pending. Returns true: the attempt is resolved.
     */
    private static function resolve(CheckoutAttempt $attempt, string $status): bool
    {
        $attempt->newQuery()->whereKey($attempt->id)->pending()->update(['status' => $status]);

        return true;
    }

    /**
     * Expires the attempt only while it is pending and, as stored now, holds no session: an attempt
     * that holds a session is settled through Alunta's state of that session instead.
     */
    private static function expireUnsent(CheckoutAttempt $attempt): void
    {
        $attempt->newQuery()
            ->whereKey($attempt->id)
            ->pending()
            ->whereNull('alunta_session_id')
            ->update(['status' => CheckoutAttempt::STATUS_EXPIRED]);
    }

    /**
     * Whether Alunta answered and refused the request, so it created nothing: a 4xx other than 409
     * (the key already holds a request) and 429 (retry later).
     */
    private static function definitelyRefused(AluntaApiException $exception): bool
    {
        return $exception->status !== null && $exception->status >= 400 && $exception->status < 500
            && ! in_array($exception->status, [409, 429], true);
    }

    /**
     * Settles an attempt whose session has expired, so a new checkout may start, or refuses.
     *
     * @throws AluntaApiException
     * @throws DomainException
     */
    private function resolveExpiredAttempt(Billable $billable, CheckoutAttempt $attempt): void
    {
        $binding = $this->bindRunningSubscription($billable);

        if ($binding !== 'none') {
            throw new DomainException($binding === 'bound' ? self::ALREADY_COMPLETED : self::NOT_APPLIED);
        }

        if (! $this->retireIfRestartable($attempt)) {
            throw new DomainException(self::IN_PROGRESS);
        }
    }

    /**
     * The billable's attempt to send for the plan: its live attempt, its attempt whose session is
     * not known yet (to replay), or a new one when it has no unresolved attempt. Eligibility is
     * checked again under the billable's row lock, which also makes concurrent clicks see each
     * other's attempt.
     *
     * @throws DomainException when the billable is no longer purchasable or another attempt is unresolved
     */
    private function openAttempt(Billable $billable, string $plan, string $planUuid, string $backUrl, string $successUrl): CheckoutAttempt
    {
        return DB::transaction(function () use ($billable, $plan, $planUuid, $backUrl, $successUrl): CheckoutAttempt {
            $lockedBillable = $this->billables->lockForBilling($billable->getBillingKey());

            if (! self::holdsNoPaidPlan($lockedBillable)) {
                throw new DomainException(self::NOT_PURCHASABLE);
            }

            if (self::owesInvoicePastDue($lockedBillable)) {
                throw new DomainException(self::UNPAID_INVOICE);
            }

            $unresolvedAttempt = self::unresolvedAttempt($lockedBillable);

            if ($unresolvedAttempt !== null) {
                if ($unresolvedAttempt->purchaseCannotBeApplied()) {
                    throw new DomainException(self::NOT_APPLIED);
                }

                $mayResend = self::sessionLives($unresolvedAttempt) || $unresolvedAttempt->alunta_session_id === null;

                if ($unresolvedAttempt->localPlanKey() !== $plan || ! $mayResend) {
                    throw new DomainException(self::IN_PROGRESS);
                }

                return $unresolvedAttempt;
            }

            $idempotencyKey = (string) Str::uuid();
            $model = CheckoutAttempt::appModel();

            return $model->newQuery()->create([
                $model->billableKeyName() => $lockedBillable->getBillingKey(),
                'plan' => $plan,
                'idempotency_key' => $idempotencyKey,
                'request_payload' => $this->requestPayload($lockedBillable, $model, $planUuid, $idempotencyKey, $backUrl, $successUrl),
                'status' => CheckoutAttempt::STATUS_PENDING,
                'expires_at' => now()->addDay(),
            ]);
        });
    }

    /**
     * The checkout request an attempt sends, every time: the billable's identity metadata
     * (BillableIdentity::checkoutMetadata()) first, then the app's (CheckoutAttempt::checkoutMetadata()).
     * The identity metadata wins a shared key, so the app's metadata can never replace the reference
     * or attempt key the sync and the webhook read back.
     *
     * @return array{plan_id: string, external_customer_id: string, back_url: string, success_url: string, metadata: array<string, mixed>}
     */
    private function requestPayload(Billable $billable, CheckoutAttempt $attempt, string $planUuid, string $idempotencyKey, string $backUrl, string $successUrl): array
    {
        return [
            'plan_id' => $planUuid,
            'external_customer_id' => $this->identity->externalCustomerId($billable),
            'back_url' => $backUrl,
            'success_url' => $successUrl,
            'metadata' => array_filter(
                $this->identity->checkoutMetadata($billable, $idempotencyKey) + $attempt->checkoutMetadata($billable),
                fn (mixed $value): bool => $value !== null && $value !== '',
            ),
        ];
    }
}
