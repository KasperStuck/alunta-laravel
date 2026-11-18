<?php

namespace KasperStuck\Alunta\Laravel\Contracts;

use Carbon\CarbonImmutable;

/**
 * When an unpaid invoice of an invoice-paid subscription closes access. The subscription sync
 * sets the billable past due once a fresh read shows the open invoice still outstanding at or
 * after this moment, and active again once the invoice is paid.
 */
interface InvoiceAccessPolicy
{
    /**
     * The moment, in UTC, access closes for an invoice still unpaid that is due on $dueOn. Only
     * the calendar day of $dueOn counts: the sync passes it as midnight UTC.
     */
    public function accessClosesAt(CarbonImmutable $dueOn): CarbonImmutable;
}
