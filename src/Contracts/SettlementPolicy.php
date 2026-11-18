<?php

namespace KasperStuck\Alunta\Laravel\Contracts;

use KasperStuck\Alunta\Laravel\Billing\FirstGrantRequirement;

/**
 * Which Alunta payments the app trusts before it grants access.
 */
interface SettlementPolicy
{
    /**
     * Whether a NEW grant of a subscription paid through the payment provider is allowed. A
     * billable already granted on the subscription with the same payment provider keeps following
     * it whatever this returns, so turning a provider off never revokes a running grant.
     */
    public function mayGrantProvider(?string $paymentProvider): bool;

    /**
     * Whether a recorded payment counts towards settling its invoice. $testMode is whether the
     * Alunta account is a test account (services.alunta.test_mode).
     *
     * @param  array<mixed>  $payment  a payment as Alunta returns it
     */
    public function countsPayment(array $payment, bool $testMode): bool;

    /**
     * What the subscription's first billed period must show before its first grant. It is asked on
     * every grant, not only the first: IssuedFirstInvoice marks a subscription paid by invoice,
     * whose access then follows its open invoice (InvoiceAccessPolicy) instead of counted payments.
     * Return it for such a subscription whether or not new ones may be granted.
     *
     * @param  array<string, mixed>  $subscription  the subscription as GET /subscriptions/{uuid} returns it
     */
    public function firstGrantRequirement(array $subscription): FirstGrantRequirement;
}
