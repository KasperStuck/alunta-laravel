<?php

namespace KasperStuck\Alunta\Laravel\Contracts;

/**
 * Which Alunta plan is which of the app's paid plans. A plan is only ever identified by its Alunta
 * plan UUID, never by an interval, because Alunta's webhook examples send the interval as text
 * while the Subscription schema documents an integer.
 */
interface PlanMapper
{
    /**
     * The local paid plan key configured for an Alunta plan UUID, or null for anything else.
     */
    public function localKeyFor(mixed $aluntaPlanUuid): ?string;

    /**
     * The valid Alunta plan UUID configured for a local paid plan key; null for an unknown key or a
     * missing or invalid setting.
     */
    public function aluntaPlanUuid(string $localKey): ?string;

    /**
     * The renewal interval in months of a local paid plan, as PUT /subscriptions/{uuid}/plan needs
     * it; null for an unknown key.
     */
    public function interval(string $localKey): ?int;

    /**
     * The paid plan keys that must be configured before the app may sell subscriptions.
     *
     * @return list<string>
     */
    public function requiredKeys(): array;
}
