<?php

namespace KasperStuck\Alunta\Laravel;

use KasperStuck\Alunta\Sdk\AluntaApiException;
use KasperStuck\Alunta\Sdk\AluntaClient;
use KasperStuck\Alunta\Sdk\RequestPacer;

/**
 * Client for the Alunta public API (app.alunta.com/docs/v1/openapi.yaml), configured by
 * services.alunta. Resolve it from the container; it hands every call to the SDK's AluntaClient,
 * which sends through Laravel's HTTP client (LaravelHttpTransport).
 *
 * The token goes only in the Authorization header, and redirects are never followed, so it never
 * reaches another host. HTTP client exceptions become an AluntaApiException without a previous
 * exception, and only the operation, status, error code and Alunta's request id are logged; never
 * the token, a response body, a checkout URL or a portal link. A 429 is retried at most twice after
 * its Retry-After delay, capped at 10 seconds.
 *
 * The configured timeout is clamped to MAX_TIMEOUT_SECONDS, so one call takes at most
 * WORST_CASE_REQUEST_SECONDS, which callers that hold a lock size its lease from. A batch caller
 * can pace every request of the process (paceRequests()).
 */
class AluntaApiClient
{
    public const int MAX_TIMEOUT_SECONDS = AluntaClient::MAX_TIMEOUT_SECONDS;

    /**
     * The longest one call can take: every attempt with its connect and request timeout, plus the
     * capped Retry-After waits between them (3 × (5 + 20) + 2 × 10 = 95 seconds).
     */
    public const int WORST_CASE_REQUEST_SECONDS = AluntaClient::WORST_CASE_REQUEST_SECONDS;

    public function __construct(private readonly AluntaClient $client) {}

    /**
     * Creates a subscription checkout session for the plan. Never sends quantity or
     * payment_providers: Alunta rejects both on subscription sessions of a fixed-price plan, and the
     * payment methods come from the plan. The same Idempotency-Key with the same body replays the
     * original session, whose checkout_url is null once it can no longer be paid.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{id: string, checkout_url: ?string, expires_at: ?string}
     *
     * @throws AluntaApiException
     */
    public function createSubscriptionCheckout(
        string $planUuid,
        string $externalCustomerId,
        string $backUrl,
        string $successUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        return $this->client->createSubscriptionCheckout($planUuid, $externalCustomerId, $backUrl, $successUrl, $metadata, $idempotencyKey);
    }

    /**
     * The recovery state of a checkout session. Alunta allows a restart only when can_restart is
     * true: the session expired and no provider operation is unresolved. Expiry alone is not
     * enough. The checkout URL is never returned.
     *
     * @return array{status: string, can_restart: bool, subscription_uuid: ?string}
     *
     * @throws AluntaApiException
     */
    public function getCheckoutSession(string $sessionUuid): array
    {
        return $this->client->getCheckoutSession($sessionUuid);
    }

    /**
     * @return array<string, mixed> the Subscription object
     *
     * @throws AluntaApiException
     */
    public function getSubscription(string $subscriptionUuid): array
    {
        return $this->client->getSubscription($subscriptionUuid);
    }

    /**
     * The newest 100 subscriptions created with the external customer id.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    public function listSubscriptionsByExternalCustomer(string $externalCustomerId): array
    {
        return $this->client->listSubscriptionsByExternalCustomer($externalCustomerId);
    }

    /**
     * The billing periods (transactions) of the subscription, newest period first, at most 100. A
     * period is invoiced once `invoice` names its invoice; a deferred first partial period carries
     * `bill_from`, the day it is billed with the next period. $invoiced and $page filter and page
     * the list as AluntaClient::listSubscriptionTransactions() describes.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    public function listSubscriptionTransactions(string $subscriptionUuid, ?bool $invoiced = null, ?int $page = null): array
    {
        return $this->client->listSubscriptionTransactions($subscriptionUuid, $invoiced, $page);
    }

    /**
     * The invoice: its status, total with VAT and outstanding balance.
     *
     * @return array<string, mixed>
     *
     * @throws AluntaApiException
     */
    public function getInvoice(string $invoiceUuid): array
    {
        return $this->client->getInvoice($invoiceUuid);
    }

    /**
     * The payments recorded against the invoice, at most 100: each with its amount including VAT
     * and the provider that collected it.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    public function listInvoicePayments(string $invoiceUuid): array
    {
        return $this->client->listInvoicePayments($invoiceUuid);
    }

    /**
     * The end date a cancellation at the end of the period would store, without cancelling.
     *
     * @return array{end_date: string, notice_applied: bool}
     *
     * @throws AluntaApiException
     */
    public function cancelPreview(string $subscriptionUuid): array
    {
        return $this->client->cancelPreview($subscriptionUuid);
    }

    /**
     * Cancels at the end of the current billing period.
     *
     * @return array<string, mixed> the updated Subscription object
     *
     * @throws AluntaApiException
     */
    public function cancel(string $subscriptionUuid): array
    {
        return $this->client->cancel($subscriptionUuid);
    }

    /**
     * @return array<string, mixed> the updated Subscription object
     *
     * @throws AluntaApiException
     */
    public function resume(string $subscriptionUuid): array
    {
        return $this->client->resume($subscriptionUuid);
    }

    /**
     * Schedules a move to another plan at a renewal: the next unlocked one, or $effectiveDate
     * (Y-m-d) from the subscription's available_plan_change_dates. The change is scheduled, never
     * immediate.
     *
     * @return array{subscription: array<string, mixed>, scheduled_change_uuid: ?string, effective_date: ?string}
     *
     * @throws AluntaApiException
     */
    public function changePlan(string $subscriptionUuid, string $planUuid, int $interval, ?string $effectiveDate = null): array
    {
        return $this->client->changePlan($subscriptionUuid, $planUuid, $interval, $effectiveDate);
    }

    /**
     * Deletes a pending scheduled change, such as a plan change.
     *
     * @return array<string, mixed> the updated Subscription object
     *
     * @throws AluntaApiException
     */
    public function deleteScheduledChange(string $subscriptionUuid, string $scheduledChangeUuid): array
    {
        return $this->client->deleteScheduledChange($subscriptionUuid, $scheduledChangeUuid);
    }

    /**
     * A signed auto-login link to the customer portal, valid for 15 minutes. The link is a
     * credential: mint it when the customer clicks, and never log or store it.
     *
     * @return array{url: string, expires_at: ?string}
     *
     * @throws AluntaApiException
     */
    public function portalLink(string $customerUuid): array
    {
        return $this->client->portalLink($customerUuid);
    }

    /**
     * The team the token belongs to.
     *
     * @return array{team_uuid: string, team_name: string}
     *
     * @throws AluntaApiException
     */
    public function me(): array
    {
        return $this->client->me();
    }

    /**
     * Waits $milliseconds before every request after the first, including a retry, until called
     * again with null. Pacing covers every client of the application.
     */
    public static function paceRequests(?int $milliseconds): void
    {
        app(RequestPacer::class)->pace($milliseconds);
    }

    /**
     * The configured request timeout (services.alunta.timeout, default 15), clamped to 1 through
     * MAX_TIMEOUT_SECONDS.
     */
    public static function timeoutSeconds(): int
    {
        return AluntaClient::timeoutSecondsFrom(config('services.alunta.timeout'));
    }

    /**
     * The configured API URL (services.alunta.base_url) without a trailing slash, or null when it
     * is not an http(s) URL.
     */
    public static function configuredBaseUrl(): ?string
    {
        return AluntaClient::baseUrlFrom(config('services.alunta.base_url'));
    }
}
