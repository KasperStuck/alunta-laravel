<?php

namespace KasperStuck\Alunta\Laravel\Billing;

use Illuminate\Contracts\Cache\LockTimeoutException;
use KasperStuck\Alunta\Laravel\AluntaAccount;
use KasperStuck\Alunta\Laravel\AluntaApiClient;
use KasperStuck\Alunta\Laravel\Contracts\Billable;
use KasperStuck\Alunta\Laravel\Contracts\BillableRepository;
use KasperStuck\Alunta\Laravel\Sync\AluntaSubscriptionSync;
use KasperStuck\Alunta\Laravel\Sync\InvoiceStandingReader;
use KasperStuck\Alunta\Sdk\AluntaApiException;
use SensitiveParameter;

/**
 * Resolves only the database-bound open invoice. URLs live only in the returned result: never
 * log, persist or include them in a notification. The app authorizes the billable's owner.
 *
 * An unclear answer or a failed read never changes the stored invoice or the billable's access by
 * itself. A running subscription is read through AluntaSubscriptionSync, and what that sync applies
 * from its own complete read stands, exactly as on every other sync: a subscription Alunta shows
 * cancelled ends, and one Alunta no longer knows is marked missing. When a later read in the same
 * check then fails, the result is unclear and the stored invoice is kept.
 */
final class AluntaInvoicePayLink
{
    public function __construct(
        private BillableRepository $billables,
        private AluntaSubscriptionSync $sync,
        private AluntaApiClient $client,
    ) {}

    /**
     * Rechecks with the same classification as recheckOpenInvoice(). A closed invoice syncs a
     * running subscription so a registered payment also reopens access; an ended subscription only
     * needs its retained invoice cleared. A newer invoice selected by sync is resolved once;
     * unreadable or concurrently replaced bindings return unclear.
     *
     * @return array{status: 'url'|'no_link'|'cleared'|'unclear', url: ?string}
     */
    public function forBillable(Billable $billable): array
    {
        $unclear = ['status' => 'unclear', 'url' => null];
        $fresh = $this->billables->find($billable->getBillingKey());
        $state = $fresh?->billingState();

        if (! AluntaAccount::isReady()
            || $state === null || $state->openInvoiceId === null || $state->provider !== 'alunta') {
            return $unclear;
        }

        try {
            $invoice = $this->client->getInvoice($state->openInvoiceId);
            $openness = InvoiceStandingReader::classify($invoice);
            if ($openness === 'unclear') {
                return $unclear;
            }

            if ($openness === 'open') {
                return $this->openLink($fresh, $state->openInvoiceId, $state->subscriptionId, $invoice);
            }

            if ($state->paidPlan !== null && $state->subscriptionId !== null) {
                $synced = $this->sync->sync($state->subscriptionId);

                if ($synced === null || $synced->getBillingKey() !== $fresh->getBillingKey()) {
                    return $unclear;
                }

                $fresh = $this->billables->find($fresh->getBillingKey());
                if ($fresh === null) {
                    return $unclear;
                }
                $endedState = $fresh->billingState();

                if ($endedState->paidPlan === null
                    && $endedState->subscriptionId === $state->subscriptionId
                    && $endedState->openInvoiceId === $state->openInvoiceId
                    && $this->sync->recheckOpenInvoice($fresh) === 'unclear') {
                    return $unclear;
                }
            } elseif ($this->sync->recheckOpenInvoice($fresh) === 'unclear') {
                return $unclear;
            }

            $after = $this->billables->find($fresh->getBillingKey())?->billingState();

            if ($after !== null && $after->paidPlan !== null && $after->subscriptionId === $state->subscriptionId
                && $after->openInvoiceId !== null && $after->openInvoiceId !== $state->openInvoiceId) {
                $invoice = $this->client->getInvoice($after->openInvoiceId);

                return InvoiceStandingReader::classify($invoice) === 'open'
                    ? $this->openLink($fresh, $after->openInvoiceId, $after->subscriptionId, $invoice)
                    : $unclear;
            }

            return $after !== null && $after->openInvoiceId === null
                ? ['status' => 'cleared', 'url' => null]
                : $unclear;
        } catch (AluntaApiException|LockTimeoutException) {
            return $unclear;
        }
    }

    /**
     * Rechecks the binding after the read so a concurrent change cannot redirect to an old invoice.
     * The invoice carries the pay link, so it is a sensitive parameter: an exception's backtrace
     * never shows it to the error tracker.
     *
     * @param  array<string, mixed>  $invoice
     * @return array{status: 'url'|'no_link'|'unclear', url: ?string}
     */
    private function openLink(Billable $billable, string $invoiceId, ?string $subscriptionId, #[SensitiveParameter] array $invoice): array
    {
        $state = $this->billables->find($billable->getBillingKey())?->billingState();

        if ($state?->openInvoiceId !== $invoiceId || $state->subscriptionId !== $subscriptionId) {
            return ['status' => 'unclear', 'url' => null];
        }

        $url = $invoice['pay_url'] ?? null;

        return is_string($url) ? ['status' => 'url', 'url' => $url] : ['status' => 'no_link', 'url' => null];
    }
}
