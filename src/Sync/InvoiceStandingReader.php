<?php

namespace KasperStuck\Alunta\Laravel\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use KasperStuck\Alunta\Laravel\AluntaApiClient;
use KasperStuck\Alunta\Sdk\AluntaApiException;
use KasperStuck\Alunta\Sdk\AluntaClient;
use SensitiveParameter;
use Throwable;

/**
 * Reads an invoice-paid subscription's invoices for the subscription sync, within a fixed request
 * budget: at most MAX_TRANSACTION_PAGES pages of its invoiced periods and MAX_INVOICE_READS
 * invoices. The invoice balance decides, whatever provider recorded a payment.
 *
 * The open invoice is the oldest billed period's invoice that is open: issued, partially paid or
 * overdue with an outstanding amount above zero, and neither cancelled, credited nor a credit
 * note. A draft is not open: it is not issued yet. An invoice paid in full (paid or overpaid,
 * nothing outstanding) is not open either. Any other invoice is unclear. Every invoice read is
 * also checked against its periods: a period showing paid_at for an invoice that is open, or no
 * paid_at for an invoice paid in full, contradicts it, and it is unclear too.
 *
 * The periods come newest first. Only invoices whose period shows no paid_at are read, oldest
 * first. A further page is read only while the last one was full and no open invoice was found on
 * it, so the oldest open invoice is the oldest on the first page that has one. An open invoice
 * the billable has stored (the open invoice of an earlier sync) that is older than that one, or
 * that the pages did not show, is read again: while it is still open it stays the open invoice.
 *
 * Closed invoices that later syncs need not read again are remembered (remember()). Cancelled
 * invoices and credit notes cannot become open again (the specification documents no way back
 * from a cancellation, and a credit note is its own document), so they are remembered for a long
 * time (isClosedForGood()). A credited invoice can reopen, because a payment that covered its
 * rest can be reversed, so it is remembered for 24 hours only: a reopened one is seen within a
 * day, and credited invoices before the open one do not spend the budget of every sync.
 *
 * The standing is unknown, and the sync then changes nothing and warns, whenever the reads cannot
 * show it for certain: a period that cannot be read (history_unreadable), three full pages without
 * an answer or a first grant without the whole history (history_incomplete), an invoice read
 * beyond the budget (request_budget_spent), or an unclear invoice (invoice_unclear). The next sync
 * tries again.
 */
final class InvoiceStandingReader
{
    public const int MAX_TRANSACTION_PAGES = 3;

    public const int MAX_INVOICE_READS = 4;

    /**
     * How long an invoice that cannot become open again is remembered. Losing it early only costs
     * one more read.
     */
    private const int REMEMBER_CLOSED_FOR_DAYS = 400;

    /**
     * How long a credited invoice is remembered. A payment reversal can reopen it, which a sync
     * then sees within this time; it is short enough for that, and long enough that credited
     * invoices before the open one do not spend every sync's budget.
     */
    private const int REMEMBER_CREDITED_FOR_HOURS = 24;

    /**
     * The timezone of Alunta's calendar days (see AluntaSubscriptionSync::ALUNTA_TIMEZONE): the day
     * an invoice was created on is read in it.
     */
    private const string ALUNTA_TIMEZONE = 'Europe/Copenhagen';

    public function __construct(private AluntaApiClient $client) {}

    /**
     * The subscription's invoice standing as Alunta shows it now. $readsFirstInvoice also reads
     * how far its first invoice is issued (firstInvoiceState()), which needs its whole history.
     * $storedOpenInvoiceId is the open invoice the billable bound to this subscription has stored.
     *
     * @param  array<string, mixed>  $subscription
     *
     * @throws AluntaApiException
     */
    public function read(array $subscription, string $subscriptionUuid, bool $readsFirstInvoice, ?string $storedOpenInvoiceId = null): InvoiceStanding
    {
        /** @var array<string, array<string, mixed>> $invoices the invoices read, by UUID */
        $invoices = [];
        /** @var list<array<string, mixed>> $history */
        $history = [];
        $isComplete = false;
        $openInvoiceUuid = null;

        for ($page = 1; $page <= self::MAX_TRANSACTION_PAGES && $openInvoiceUuid === null && ! $isComplete; $page++) {
            $periods = $this->client->listSubscriptionTransactions($subscriptionUuid, invoiced: true, page: $page);

            if (! self::isReadable($periods)) {
                return InvoiceStanding::unknown('history_unreadable');
            }

            $history = [...$history, ...$periods];
            $isComplete = count($periods) < AluntaClient::LIST_PAGE_SIZE;

            foreach (self::unpaidInvoiceUuids($periods) as $invoiceUuid) {
                $openness = $this->openness($invoiceUuid, $history, $invoices);

                if ($openness !== 'closed') {
                    if ($openness !== 'open') {
                        return InvoiceStanding::unknown($openness);
                    }

                    $openInvoiceUuid = $invoiceUuid;

                    break;
                }
            }
        }

        if (($openInvoiceUuid === null || $readsFirstInvoice) && ! $isComplete) {
            return InvoiceStanding::unknown('history_incomplete');
        }

        if ($storedOpenInvoiceId !== null && self::isOlderOrUnseen($storedOpenInvoiceId, $openInvoiceUuid, $history)) {
            $openness = $this->openness($storedOpenInvoiceId, $history, $invoices);

            if ($openness === 'open') {
                $openInvoiceUuid = $storedOpenInvoiceId;
            } elseif ($openness !== 'closed') {
                return InvoiceStanding::unknown($openness);
            }
        }

        $firstInvoice = null;

        if ($readsFirstInvoice) {
            $first = self::firstBilledInvoice($subscription, $history);

            if ($first['status'] === 'found' && $first['uuid'] !== null) {
                $openness = $this->openness($first['uuid'], $history, $invoices, remembered: false);

                if ($openness !== 'open' && $openness !== 'closed') {
                    return InvoiceStanding::unknown($openness);
                }

                // The first invoice is the oldest, so when it is open it is the open invoice.
                $openInvoiceUuid = $openness === 'open' ? $first['uuid'] : $openInvoiceUuid;
            }

            $firstInvoice = match ($first['status']) {
                'none' => 'unpaid',
                'mismatch' => 'unsettled',
                'found' => self::firstInvoiceState($invoices[(string) $first['uuid']] ?? []),
            };
        }

        if ($openInvoiceUuid === null) {
            return InvoiceStanding::known($firstInvoice);
        }

        $open = $invoices[$openInvoiceUuid] ?? [];
        $outstanding = $open['outstanding'] ?? null;

        if (! is_int($outstanding)) {
            return InvoiceStanding::unknown('invoice_unclear');
        }

        return InvoiceStanding::known($firstInvoice, $openInvoiceUuid, self::dueOn($open), $outstanding);
    }

    /**
     * Whether one invoice is open, closed or unclear as Alunta shows it now, read on its own with
     * the standing's classification (classify()). No period is read, so none can contradict it.
     * It is never remembered and never answers from memory: the caller acts on this fresh read.
     *
     * @return 'open'|'closed'|'unclear'
     *
     * @throws AluntaApiException when Alunta cannot be read
     */
    public function invoiceOpenness(string $invoiceUuid): string
    {
        return self::classify(self::withoutPayLink($this->client->getInvoice($invoiceUuid)), null);
    }

    /**
     * The invoice without its pay link. The reader decides standings only, so it never holds the
     * link: no argument of a later frame (a cache or database call that throws) can carry it into
     * an error report.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private static function withoutPayLink(#[SensitiveParameter] array $invoice): array
    {
        unset($invoice['pay_url']);

        return $invoice;
    }

    /**
     * The subscription's first billed invoice among its billed periods: 'none' when the earliest
     * period names no invoice yet, 'mismatch' when it is not the invoice of the subscription's
     * first period, else 'found' with its UUID.
     *
     * The first billed invoice is the invoice of the earliest period, which starts on the
     * subscription's start date. A deferred first partial period (defers_first_partial_period)
     * carries bill_from and is billed on that day together with the period starting then; both
     * must name the same invoice.
     *
     * @param  array<string, mixed>  $subscription
     * @param  list<array<string, mixed>>  $transactions
     * @return array{status: 'none'|'mismatch'|'found', uuid: ?string}
     */
    public static function firstBilledInvoice(array $subscription, array $transactions): array
    {
        $periodStart = fn (array $transaction): string => is_string($transaction['period_start'] ?? null) ? substr($transaction['period_start'], 0, 10) : '';
        $invoiceUuid = fn (?array $transaction): ?string => is_array($transaction['invoice'] ?? null) && is_string($transaction['invoice']['uuid'] ?? null) ? $transaction['invoice']['uuid'] : null;

        usort($transactions, fn (array $a, array $b): int => strcmp($periodStart($a), $periodStart($b)));
        $first = $transactions[0] ?? null;
        $firstInvoiceUuid = $invoiceUuid($first);

        if ($first === null || $firstInvoiceUuid === null) {
            return ['status' => 'none', 'uuid' => null];
        }

        $isDeferred = ($subscription['defers_first_partial_period'] ?? false) === true;
        $startDate = is_string($subscription['start_date'] ?? null) ? substr($subscription['start_date'], 0, 10) : null;
        $billFrom = is_string($first['bill_from'] ?? null) ? substr($first['bill_from'], 0, 10) : null;
        $billedWith = $billFrom !== null
            ? array_values(array_filter($transactions, fn (array $transaction): bool => $periodStart($transaction) === $billFrom))[0] ?? null
            : $first;

        if (! Str::isUuid($firstInvoiceUuid) || $invoiceUuid($billedWith) !== $firstInvoiceUuid
            || (! $isDeferred && $startDate !== null && $periodStart($first) !== $startDate)) {
            return ['status' => 'mismatch', 'uuid' => null];
        }

        return ['status' => 'found', 'uuid' => $firstInvoiceUuid];
    }

    /**
     * Whether the invoice is open, closed or unclear now (classify()), checked against the
     * periods that name it: 'request_budget_spent' when it would need a read beyond the budget.
     * A remembered invoice (remember()) is closed without a read, unless $remembered is false.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  array<string, array<string, mixed>>  $invoices
     * @return 'open'|'closed'|'invoice_unclear'|'request_budget_spent'
     *
     * @throws AluntaApiException
     */
    private function openness(string $invoiceUuid, array $history, array &$invoices, bool $remembered = true): string
    {
        if ($remembered && ! isset($invoices[$invoiceUuid])
            && (Cache::has(self::closedForGoodKey($invoiceUuid)) || Cache::has(self::creditedKey($invoiceUuid)))) {
            return 'closed';
        }

        $isRead = false;

        if (! isset($invoices[$invoiceUuid])) {
            if (count($invoices) >= self::MAX_INVOICE_READS) {
                return 'request_budget_spent';
            }

            $invoices[$invoiceUuid] = self::withoutPayLink($this->client->getInvoice($invoiceUuid));
            $isRead = true;
        }

        $openness = self::classify($invoices[$invoiceUuid], self::listShowsPaid($invoiceUuid, $history));

        if ($isRead && $openness === 'closed') {
            self::remember($invoiceUuid, $invoices[$invoiceUuid]);
        }

        return $openness === 'unclear' ? 'invoice_unclear' : $openness;
    }

    /**
     * Remembers a closed invoice that later syncs need not read: a cancelled invoice or a credit
     * note for REMEMBER_CLOSED_FOR_DAYS, a credited invoice for REMEMBER_CREDITED_FOR_HOURS.
     *
     * @param  array<string, mixed>  $invoice  an invoice classify() found closed
     */
    private static function remember(string $invoiceUuid, array $invoice): void
    {
        if (self::isClosedForGood($invoice)) {
            Cache::put(self::closedForGoodKey($invoiceUuid), true, now()->addDays(self::REMEMBER_CLOSED_FOR_DAYS));
        } elseif (($invoice['status'] ?? null) === 'credited') {
            Cache::put(self::creditedKey($invoiceUuid), true, now()->addHours(self::REMEMBER_CREDITED_FOR_HOURS));
        }
    }

    /**
     * Whether the invoice is open (owes money now), closed (cancelled, credited, a credit note, a
     * draft, or paid in full) or unclear. $listShowsPaid is whether its periods show a paid_at
     * (null when no period read names it): an open invoice they show paid, or an invoice paid in
     * full they show unpaid, is unclear. An invoice that does not state its cancellation decision
     * as a boolean (is_cancelled, required by the specification) is unclear too, and so is a credited
     * invoice whose balance is not zero, because a payment reversal can reopen it.
     *
     * @param  array<string, mixed>  $invoice
     * @return 'open'|'closed'|'unclear'
     */
    public static function classify(#[SensitiveParameter] array $invoice, ?bool $listShowsPaid = null): string
    {
        $status = $invoice['status'] ?? null;
        $outstanding = $invoice['outstanding'] ?? null;
        $isCancelled = $invoice['is_cancelled'] ?? null;
        $isPaidInFull = in_array($status, ['paid', 'overpaid'], true) && $outstanding === 0;

        return match (true) {
            ! is_bool($isCancelled) => 'unclear',
            $isCancelled => 'closed',
            $status === 'credited' && $outstanding !== 0 => 'unclear',
            in_array($status, ['credited', 'creditnote', 'draft'], true) => 'closed',
            $isPaidInFull => $listShowsPaid === false ? 'unclear' : 'closed',
            in_array($status, ['issued', 'partially_paid', 'overdue'], true) && is_int($outstanding) && $outstanding > 0 => $listShowsPaid === true ? 'unclear' : 'open',
            default => 'unclear',
        };
    }

    /**
     * Whether the invoice can never become open again: it is cancelled, or it is a credit note.
     *
     * @param  array<string, mixed>  $invoice
     */
    private static function isClosedForGood(array $invoice): bool
    {
        return ($invoice['is_cancelled'] ?? null) === true || ($invoice['status'] ?? null) === 'creditnote';
    }

    private static function closedForGoodKey(string $invoiceUuid): string
    {
        return 'alunta-invoice-closed-for-good:'.strtolower($invoiceUuid);
    }

    private static function creditedKey(string $invoiceUuid): string
    {
        return 'alunta-invoice-credited:'.strtolower($invoiceUuid);
    }

    /**
     * Whether the stored open invoice must be read again: it is not the open invoice found, and
     * the periods read either do not name it, or name it only in a period older than the open
     * invoice's. Without an open invoice, a stored invoice the whole history shows is decided by
     * its periods like any other.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private static function isOlderOrUnseen(string $storedInvoiceUuid, ?string $openInvoiceUuid, array $history): bool
    {
        if ($openInvoiceUuid !== null && strcasecmp($storedInvoiceUuid, $openInvoiceUuid) === 0) {
            return false;
        }

        $storedStart = self::oldestPeriodStart($storedInvoiceUuid, $history);

        if ($storedStart === null) {
            return true;
        }

        $openStart = $openInvoiceUuid !== null ? self::oldestPeriodStart($openInvoiceUuid, $history) : null;

        return $openStart !== null && $storedStart < $openStart;
    }

    /**
     * The start, as a Unix timestamp, of the oldest period read that names the invoice, or null
     * when none does. The periods are readable (isReadable()).
     *
     * @param  list<array<string, mixed>>  $history
     */
    private static function oldestPeriodStart(string $invoiceUuid, array $history): ?int
    {
        $starts = array_map(
            fn (array $period): int => (int) self::periodStart($period)?->getTimestamp(),
            self::periodsNaming($invoiceUuid, $history),
        );

        return $starts === [] ? null : min($starts);
    }

    /**
     * Whether the periods read show the invoice paid (a period naming it has a paid_at); null when
     * none of them names it.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private static function listShowsPaid(string $invoiceUuid, array $history): ?bool
    {
        $naming = self::periodsNaming($invoiceUuid, $history);

        if ($naming === []) {
            return null;
        }

        return array_filter($naming, fn (array $period): bool => (self::invoiceReference($period)['paid_at'] ?? null) !== null) !== [];
    }

    /**
     * The periods read that name the invoice.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    private static function periodsNaming(string $invoiceUuid, array $history): array
    {
        return array_values(array_filter($history, fn (array $period): bool => strcasecmp(self::invoiceReference($period)['uuid'] ?? '', $invoiceUuid) === 0));
    }

    /**
     * Whether every invoiced period can be read in full: its start is an absolute timestamp and it
     * names its invoice readably (invoiceReference()). A period that cannot be read could hide an
     * open invoice.
     *
     * @param  list<array<string, mixed>>  $periods
     */
    private static function isReadable(array $periods): bool
    {
        foreach ($periods as $period) {
            if (self::periodStart($period) === null || self::invoiceReference($period) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The period's start, when it is an absolute timestamp.
     *
     * @param  array<string, mixed>  $period
     */
    private static function periodStart(array $period): ?CarbonImmutable
    {
        return self::timestamp($period['period_start'] ?? null);
    }

    /**
     * The invoice the period names, when it names it readably: by a valid UUID, with a paid_at that
     * is present and either null or an absolute timestamp. Null otherwise.
     *
     * @param  array<string, mixed>  $period
     * @return array{uuid: string, paid_at: ?string}|null
     */
    private static function invoiceReference(array $period): ?array
    {
        $invoice = $period['invoice'] ?? null;

        if (! is_array($invoice) || ! is_string($invoice['uuid'] ?? null) || ! Str::isUuid($invoice['uuid']) || ! array_key_exists('paid_at', $invoice)) {
            return null;
        }

        $paidAt = $invoice['paid_at'];

        if ($paidAt !== null && (! is_string($paidAt) || self::timestamp($paidAt) === null)) {
            return null;
        }

        return ['uuid' => $invoice['uuid'], 'paid_at' => $paidAt];
    }

    /**
     * The UUIDs of the invoices the periods name without a paid_at, oldest period first, each once.
     * The periods are readable (isReadable()).
     *
     * @param  list<array<string, mixed>>  $periods
     * @return list<string>
     */
    private static function unpaidInvoiceUuids(array $periods): array
    {
        $unpaid = array_values(array_filter($periods, fn (array $period): bool => self::invoiceReference($period) !== null && self::invoiceReference($period)['paid_at'] === null));
        usort($unpaid, fn (array $a, array $b): int => self::periodStart($a)?->getTimestamp() <=> self::periodStart($b)?->getTimestamp());

        return array_values(array_unique(array_map(fn (array $period): string => self::invoiceReference($period)['uuid'] ?? '', $unpaid)));
    }

    /**
     * How far the first invoice is issued: 'issued' once it is issued, partly or fully paid, or
     * overdue; 'unsettled' when it is cancelled, credited or a credit note; 'unpaid' while it is a
     * draft or anything else. classify() has already found the invoice clear, so it states
     * is_cancelled as a boolean; a missing one is never read as not cancelled.
     *
     * @param  array<string, mixed>  $invoice
     * @return 'issued'|'unpaid'|'unsettled'
     */
    private static function firstInvoiceState(array $invoice): string
    {
        $status = $invoice['status'] ?? null;
        $isCancelled = $invoice['is_cancelled'] ?? null;

        return match (true) {
            $isCancelled === true, in_array($status, ['credited', 'creditnote'], true) => 'unsettled',
            $isCancelled === false && in_array($status, ['issued', 'partially_paid', 'overdue', 'paid', 'overpaid'], true) => 'issued',
            default => 'unpaid',
        };
    }

    /**
     * The invoice's due day as midnight UTC: its due date, else its issue date, else the Alunta
     * calendar day it was created on; null when it has none of them. Only a strict date
     * (YYYY-MM-DD) or an absolute ISO 8601 timestamp counts: a blank, relative or malformed value
     * falls through to the next one and never reads as today.
     *
     * @param  array<string, mixed>  $invoice
     */
    private static function dueOn(array $invoice): ?CarbonImmutable
    {
        foreach (['due_date', 'date'] as $field) {
            $value = $invoice[$field] ?? null;
            $day = self::date($value) ?? self::date(self::timestamp($value)?->toDateString());

            if ($day !== null) {
                return $day;
            }
        }

        return self::date(self::timestamp($invoice['created_at'] ?? null)?->setTimezone(self::ALUNTA_TIMEZONE)->toDateString());
    }

    /**
     * A strict calendar date (YYYY-MM-DD) as midnight UTC, or null for anything else.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * An absolute ISO 8601 timestamp (date, time, optional fraction and a Z or ±hh:mm offset) in
     * UTC, or null for anything else, such as a blank, relative or zoneless value.
     */
    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $timestamp->format('Y-m-d\TH:i:s') === $matches[1] ? $timestamp->utc() : null;
    }
}
