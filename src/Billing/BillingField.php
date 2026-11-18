<?php

namespace KasperStuck\Alunta\Laravel\Billing;

use DateTimeInterface;

/**
 * A billing field the package writes through Billable::applyBillingChange(). The billable maps
 * each field to its own storage.
 */
enum BillingField: string
{
    /** The local paid plan key (PlanMapper), or null for no paid plan (the app's free plan). */
    case PaidPlan = 'paid_plan';

    /**
     * The subscription status: 'active', 'trialing', or 'past_due' when an unpaid invoice of an
     * invoice-paid subscription has closed access (InvoiceAccessPolicy).
     */
    case Status = 'status';

    /** The billing provider, for example 'alunta', or null. */
    case Provider = 'provider';

    /** The Alunta customer UUID, or null. */
    case CustomerId = 'customer_id';

    /** The Alunta subscription UUID, or null. */
    case SubscriptionId = 'subscription_id';

    case CurrentPeriodStartsAt = 'current_period_starts_at';

    case CurrentPeriodEndsAt = 'current_period_ends_at';

    case SubscriptionEndsAt = 'subscription_ends_at';

    case PaymentFailedAt = 'payment_failed_at';

    /** When the last applied payment event happened, to the microsecond. */
    case LastPaymentEventAt = 'last_payment_event_at';

    /** The local paid plan key a scheduled plan change moves to, or null. */
    case ScheduledPlan = 'scheduled_plan';

    /** The day a scheduled plan change takes effect, as midnight UTC, or null. */
    case ScheduledPlanChangeOn = 'scheduled_plan_change_on';

    case ScheduledPlanChangeFailed = 'scheduled_plan_change_failed';

    case SyncedAt = 'synced_at';

    case SubscriptionMissingAt = 'subscription_missing_at';

    /** The payment provider of the granted subscription as Alunta names it, for example 'invoice', or null. */
    case PaymentProvider = 'payment_provider';

    /** The Alunta UUID of the open invoice access follows, or null when no invoice is open. */
    case OpenInvoiceId = 'open_invoice_id';

    /** The day the open invoice is due, as midnight UTC, or null. */
    case OpenInvoiceDueOn = 'open_invoice_due_on';

    /** The open invoice's outstanding amount in the currency's smallest unit, including VAT, or null. */
    case OpenInvoiceOutstanding = 'open_invoice_outstanding';

    /** When the sync first saw the open invoice; it never moves while the same invoice stays open. */
    case OpenInvoiceFirstSeenAt = 'open_invoice_first_seen_at';

    /**
     * Whether the field takes the value.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Status => in_array($value, ['active', 'trialing', 'past_due'], true),
            self::ScheduledPlanChangeFailed => is_bool($value),
            self::OpenInvoiceOutstanding => $value === null || (is_int($value) && $value >= 0),
            self::PaidPlan, self::Provider, self::CustomerId, self::SubscriptionId, self::ScheduledPlan, self::PaymentProvider, self::OpenInvoiceId => $value === null || (is_string($value) && $value !== ''),
            default => $value === null || $value instanceof DateTimeInterface,
        };
    }
}
