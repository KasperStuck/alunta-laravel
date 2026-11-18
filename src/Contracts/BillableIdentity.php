<?php

namespace KasperStuck\Alunta\Laravel\Contracts;

/**
 * How a billable is named towards Alunta and recognized again in what Alunta returns.
 *
 * Which identity wins for a FIRST binding (the subscription is not bound to any billable yet):
 * none of them. The sync collects every reference the subscription claims, namely
 * referenceFromExternalCustomerId() of the subscription's own external_customer_id and of its
 * customer's external_customer_id, and referenceFromMetadata() of its metadata. It binds only
 * when every claim present names the same billable; different claims are refused as
 * identity_mismatch. A new binding also needs the customer's external_customer_id to name that
 * billable, else it is refused as customer_unverified.
 *
 * So no two claims may name different billables, and the customer's claim must be present and name
 * the billable. A mapping where the customer names one record (for example a shop) and the
 * metadata another (an agreement under it) is refused by this rule.
 *
 * The checkout attempt key is the attempt's idempotency key, the UUID string that is also sent as
 * the Idempotency-Key header of the checkout request. It is never the attempt's local database id.
 */
interface BillableIdentity
{
    /**
     * The external_customer_id the checkout sends for the billable. Alunta stores it on the
     * customer and on the subscription.
     */
    public function externalCustomerId(Billable $billable): string;

    /**
     * The billable reference in an external customer id made by externalCustomerId(), normalized
     * as BillableRepository::findByReference() takes it; null for any other value.
     */
    public function referenceFromExternalCustomerId(mixed $value): ?string;

    /**
     * The billable reference that subscription or checkout metadata made by checkoutMetadata()
     * names, normalized as BillableRepository::findByReference() takes it; null when it names none.
     */
    public function referenceFromMetadata(mixed $metadata): ?string;

    /**
     * The identity part of the checkout metadata, which Alunta stores on the subscription: the
     * billable's reference and the checkout attempt key.
     *
     * @return array<string, string>
     */
    public function checkoutMetadata(Billable $billable, string $checkoutAttemptKey): array;

    /**
     * The checkout attempt key in metadata made by checkoutMetadata(), or null.
     */
    public function checkoutAttemptKeyFrom(mixed $metadata): ?string;
}
