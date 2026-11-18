<?php

namespace KasperStuck\Alunta\Laravel\Billing;

use Carbon\CarbonImmutable;

/**
 * A billable's billing fields as stored now (Billable::billingState()). Each property matches the
 * BillingField of the same name; lastPaymentEventAt keeps its microseconds.
 */
final readonly class BillingState
{
    /**
     * The invoice fields come last with null defaults, so a billable that stores no invoice data
     * builds its state as before.
     *
     * @param  string|null  $paidPlan  the local paid plan key, or null on the app's free plan
     * @param  string  $status  the stored subscription status, for example 'active', 'trialing' or 'past_due'
     * @param  string|null  $paymentProvider  the payment provider of the granted subscription, for example 'invoice'
     * @param  string|null  $openInvoiceId  the Alunta UUID of the open invoice, or null
     * @param  CarbonImmutable|null  $openInvoiceDueOn  the open invoice's due day as midnight UTC
     * @param  int|null  $openInvoiceOutstanding  the open invoice's outstanding amount including VAT
     * @param  CarbonImmutable|null  $openInvoiceFirstSeenAt  when the sync first saw the open invoice
     */
    public function __construct(
        public ?string $paidPlan,
        public string $status,
        public ?string $provider,
        public ?string $customerId,
        public ?string $subscriptionId,
        public ?CarbonImmutable $currentPeriodStartsAt,
        public ?CarbonImmutable $currentPeriodEndsAt,
        public ?CarbonImmutable $subscriptionEndsAt,
        public ?CarbonImmutable $paymentFailedAt,
        public ?CarbonImmutable $lastPaymentEventAt,
        public ?string $scheduledPlan,
        public ?CarbonImmutable $scheduledPlanChangeOn,
        public bool $scheduledPlanChangeFailed,
        public ?CarbonImmutable $syncedAt,
        public ?CarbonImmutable $subscriptionMissingAt,
        public ?string $paymentProvider = null,
        public ?string $openInvoiceId = null,
        public ?CarbonImmutable $openInvoiceDueOn = null,
        public ?int $openInvoiceOutstanding = null,
        public ?CarbonImmutable $openInvoiceFirstSeenAt = null,
    ) {}

    /**
     * Whether an Alunta subscription bills the billable: its plan, status and billing ids then
     * change only through the Alunta subscription sync.
     */
    public function isAluntaLinked(): bool
    {
        return $this->provider === 'alunta' && $this->subscriptionId !== null;
    }
}
