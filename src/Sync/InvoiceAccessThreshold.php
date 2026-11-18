<?php

namespace KasperStuck\Alunta\Laravel\Sync;

use Carbon\CarbonImmutable;
use KasperStuck\Alunta\Laravel\Billing\BillingState;
use KasperStuck\Alunta\Laravel\Contracts\InvoiceAccessPolicy;

/**
 * Answers, from the app's InvoiceAccessPolicy alone, which stored open invoices have passed their
 * access threshold, so the rule of when access closes lives in the policy and nowhere else. The
 * reconciliation uses it to sync, and to warn about, a billable whose access should have closed.
 *
 * The policy is assumed to close access no earlier than the start of the due day in UTC, and
 * never earlier for a later due day.
 */
final readonly class InvoiceAccessThreshold
{
    /**
     * How many due days lastClosedDueOn() looks back for a policy that closes access late: ten
     * years, far beyond any grace period.
     */
    private const int MAX_DAYS_BACK = 3660;

    public function __construct(private InvoiceAccessPolicy $policy) {}

    /**
     * The latest due day, as midnight UTC, whose unpaid invoice has closed access at $now, so a
     * stored due day on or before it has passed its threshold; null when no due day in
     * MAX_DAYS_BACK has. The days are tried from the day after $now's UTC day backwards: the
     * policy's threshold only depends on the calendar day, and grows with it.
     */
    public function lastClosedDueOn(CarbonImmutable $now): ?CarbonImmutable
    {
        $dueOn = CarbonImmutable::parse($now->utc()->toDateString(), 'UTC')->addDay();

        for ($daysBack = 0; $daysBack <= self::MAX_DAYS_BACK; $daysBack++) {
            if ($this->policy->accessClosesAt($dueOn)->lessThanOrEqualTo($now)) {
                return $dueOn;
            }

            $dueOn = $dueOn->subDay();
        }

        return null;
    }

    /**
     * Whether the billable holds a paid plan that is not past due while its stored open invoice
     * has passed its access threshold at $now: a fresh read that shows the invoice still unpaid
     * closes its access.
     */
    public function isPastWhileOpen(BillingState $state, CarbonImmutable $now): bool
    {
        return $state->paidPlan !== null
            && $state->status !== 'past_due'
            && $state->openInvoiceId !== null
            && $state->openInvoiceDueOn !== null
            && $this->policy->accessClosesAt($state->openInvoiceDueOn)->lessThanOrEqualTo($now);
    }
}
