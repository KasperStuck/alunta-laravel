<?php

namespace KasperStuck\Alunta\Laravel\Billing;

/**
 * What the first billed period of a subscription must show before its first grant
 * (SettlementPolicy::firstGrantRequirement()).
 */
enum FirstGrantRequirement: string
{
    /**
     * The first billed invoice is paid in full by counted payments; a deferred first partial
     * period is refused.
     */
    case SettledFirstInvoice = 'settled_first_invoice';

    /**
     * The subscription is paid by invoice: the first invoice is issued (also partly or fully paid,
     * or overdue) and neither credited nor cancelled. Access then follows the oldest open invoice:
     * it closes while that invoice is unpaid at InvoiceAccessPolicy::accessClosesAt() of its due
     * day, and reopens once it is paid. A deferred first period is refused until it is issued.
     */
    case IssuedFirstInvoice = 'issued_first_invoice';
}
