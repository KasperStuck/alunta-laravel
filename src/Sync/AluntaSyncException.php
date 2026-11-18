<?php

namespace KasperStuck\Alunta\Laravel\Sync;

use RuntimeException;

/**
 * Reported, never thrown, when a read-back Alunta subscription is not applied to a billable
 * because applying it could give a plan that is not paid for or change the wrong billable.
 *
 * The context keeps the key names the first consumer's logs have always used: the billable's key
 * is logged as organization_id.
 */
final class AluntaSyncException extends RuntimeException
{
    /**
     * @param  'identity_mismatch'|'already_bound'|'unknown_plan'|'payment_provider_not_card'|'first_period_deferred'|'first_period_unsettled'|'scheduled_plan_change_failed'|'subscription_not_found'|'customer_changed'|'customer_unverified'  $reason
     * @param  int|string|null  $organizationId  the billable's key (Billable::getBillingKey())
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $subscriptionUuid,
        public readonly int|string|null $organizationId = null,
    ) {
        parent::__construct("Alunta subscription {$subscriptionUuid} was not applied: {$reason}.");
    }

    /**
     * @return array{reason: string, subscription_uuid: string, organization_id: int|string|null}
     */
    public function context(): array
    {
        return [
            'reason' => $this->reason,
            'subscription_uuid' => $this->subscriptionUuid,
            'organization_id' => $this->organizationId,
        ];
    }
}
