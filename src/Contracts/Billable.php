<?php

namespace KasperStuck\Alunta\Laravel\Contracts;

use KasperStuck\Alunta\Laravel\Billing\BillingChange;
use KasperStuck\Alunta\Laravel\Billing\BillingState;

/**
 * The app record an Alunta subscription bills: a customer account, or an agreement record when one
 * customer can hold several subscriptions. The package reads and writes its billing state only
 * through this contract.
 */
interface Billable
{
    /**
     * The record's local key, as BillableRepository::find() and lockForBilling() take it.
     */
    public function getBillingKey(): int|string;

    /**
     * The record's stable public reference, as BillableRepository::findByReference() takes it and
     * BillableIdentity names it towards Alunta; null when the record has none yet.
     */
    public function getBillingReference(): ?string;

    public function billingState(): BillingState;

    /**
     * Writes the fields the change sets, and only those, then saves. Called only on a billable from
     * BillableRepository::lockForBilling() inside DB::transaction().
     */
    public function applyBillingChange(BillingChange $change): void;
}
