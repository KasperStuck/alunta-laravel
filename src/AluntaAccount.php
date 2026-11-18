<?php

namespace KasperStuck\Alunta\Laravel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use KasperStuck\Alunta\Laravel\Contracts\PlanMapper;
use Throwable;

/**
 * The app's side of the configured Alunta account (services.alunta): whether it is complete, and
 * which Alunta plan is which of the app's paid plans through the bound PlanMapper. A plan is only
 * ever identified by its plan UUID, never by an interval.
 */
final class AluntaAccount
{
    /**
     * The local paid plan key configured for the Alunta plan UUID, or null for an unknown plan.
     */
    public static function planFor(mixed $planUuid): ?string
    {
        return self::plans()->localKeyFor($planUuid);
    }

    /**
     * The Alunta plan UUID configured for a local paid plan key; null for an unknown key, such as
     * the free plan, or a missing setting.
     */
    public static function planUuid(string $localKey): ?string
    {
        return self::plans()->aluntaPlanUuid($localKey);
    }

    /**
     * The renewal interval in months of a local paid plan, as PUT /subscriptions/{uuid}/plan needs it.
     */
    public static function planInterval(string $localKey): ?int
    {
        return self::plans()->interval($localKey);
    }

    /**
     * An Alunta timestamp in UTC, to the microsecond, or null when it is missing or invalid.
     */
    public static function timestamp(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the app may sell and manage Alunta subscriptions: the account is complete, with the
     * token and API URL to call Alunta, the webhook secret and team UUID to hear back from it, and
     * every paid plan the PlanMapper requires. Every purchase or management action checks this
     * first and, when it fails, leaves the customer to the sales contact without calling Alunta.
     */
    public static function isReady(): bool
    {
        $token = config('services.alunta.token');

        if (! self::acceptsWebhooks() || ! is_string($token) || $token === '' || AluntaApiClient::configuredBaseUrl() === null) {
            return false;
        }

        foreach (self::plans()->requiredKeys() as $localKey) {
            if (self::planUuid($localKey) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether webhooks can be verified and routed: Alunta is enabled and the webhook secret and
     * team UUID are set.
     */
    public static function acceptsWebhooks(): bool
    {
        $secret = config('services.alunta.webhook_secret');
        $teamUuid = config('services.alunta.team_uuid');

        return config('services.alunta.enabled') === true
            && is_string($secret) && $secret !== ''
            && is_string($teamUuid) && Str::isUuid($teamUuid);
    }

    /**
     * Whether new subscriptions paid by invoice may be granted (services.alunta.invoice_payments,
     * off by default). It decides only new grants: a billable already granted on an invoice
     * subscription keeps following its invoices when it is switched off.
     */
    public static function acceptsInvoicePayments(): bool
    {
        return config('services.alunta.invoice_payments') === true;
    }

    /**
     * The bound plan mapper, resolved on every call so configuration is never frozen.
     */
    private static function plans(): PlanMapper
    {
        return app(PlanMapper::class);
    }
}
