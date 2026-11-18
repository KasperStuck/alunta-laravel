<?php

namespace KasperStuck\Alunta\Laravel\Contracts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Finds and locks the app's billables.
 */
interface BillableRepository
{
    public function find(int|string $key): ?Billable;

    /**
     * The billable with the reference, as BillableIdentity reads it from Alunta (normalized there).
     */
    public function findByReference(string $reference): ?Billable;

    /**
     * The billables bound to the Alunta subscription.
     *
     * @return list<Billable> lowest key first
     */
    public function boundTo(string $subscriptionUuid): array;

    /**
     * Locks the billable's row until the surrounding transaction ends and returns it fresh. Call it
     * inside DB::transaction(), as the transaction's first statement.
     *
     * @throws ModelNotFoundException when the billable no longer exists
     */
    public function lockForBilling(int|string $key): Billable;

    /**
     * The billable an operator names for a single reconciliation, or null.
     */
    public function findForReconcile(string $identifier): ?Billable;

    /**
     * Calls $callback with each billable whose Alunta subscription is due for a sync, lowest key
     * first: bound to a subscription on a paid plan, not marked missing since $missingRetryBefore,
     * and not synced since $staleBefore, with a period or end date before $now, or not past due
     * while its stored open invoice has passed its access threshold at $now
     * (Sync\InvoiceAccessThreshold, which applies the InvoiceAccessPolicy), whatever payment
     * provider new grants allow.
     *
     * The callback may sync each billable passed, so it may drop out of the selection: page by
     * key, never by offset, so every billable due is passed exactly once.
     *
     * @param  Closure(Billable): void  $callback
     */
    public function eachDueForSync(CarbonImmutable $now, CarbonImmutable $staleBefore, CarbonImmutable $missingRetryBefore, Closure $callback): void;

    /**
     * Calls $callback with each billable that has a stored open invoice (BillingState::openInvoiceId)
     * with no due day or a due day before $dueBefore's calendar day (in $dueBefore's own timezone),
     * lowest key first, whatever its plan, status or provider. The callback may clear the open
     * invoice: page by key, never by offset, so every billable is passed exactly once.
     *
     * @param  Closure(Billable): void  $callback
     */
    public function eachWithOpenInvoiceDueBefore(CarbonImmutable $dueBefore, Closure $callback): void;
}
