<?php

namespace KasperStuck\Alunta\Laravel\Sync;

use Carbon\CarbonImmutable;

/**
 * What a fresh read shows about an invoice-paid subscription's invoices (InvoiceStandingReader):
 * whether its first invoice is issued, and the open invoice its access follows. An unknown
 * standing changes nothing.
 */
final readonly class InvoiceStanding
{
    /**
     * @param  'request_budget_spent'|'history_incomplete'|'history_unreadable'|'invoice_unclear'|'not_read'|null  $unknownReason  null when the standing is known
     * @param  'issued'|'unpaid'|'unsettled'|null  $firstInvoice  null when it was not read
     * @param  string|null  $openInvoiceId  the Alunta UUID of the open invoice, or null when none is open
     * @param  CarbonImmutable|null  $openInvoiceDueOn  the open invoice's due day as midnight UTC, or null when the invoice has no date
     * @param  int|null  $openInvoiceOutstanding  the open invoice's outstanding amount including VAT
     */
    private function __construct(
        public ?string $unknownReason,
        public ?string $firstInvoice,
        public ?string $openInvoiceId,
        public ?CarbonImmutable $openInvoiceDueOn,
        public ?int $openInvoiceOutstanding,
    ) {}

    /**
     * @param  'request_budget_spent'|'history_incomplete'|'history_unreadable'|'invoice_unclear'|'not_read'  $reason
     */
    public static function unknown(string $reason): self
    {
        return new self($reason, null, null, null, null);
    }

    /**
     * @param  'issued'|'unpaid'|'unsettled'|null  $firstInvoice
     */
    public static function known(?string $firstInvoice, ?string $openInvoiceId = null, ?CarbonImmutable $openInvoiceDueOn = null, ?int $openInvoiceOutstanding = null): self
    {
        return new self(null, $firstInvoice, $openInvoiceId, $openInvoiceDueOn, $openInvoiceOutstanding);
    }

    public function isKnown(): bool
    {
        return $this->unknownReason === null;
    }
}
