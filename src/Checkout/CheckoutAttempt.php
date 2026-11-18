<?php

namespace KasperStuck\Alunta\Laravel\Checkout;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use KasperStuck\Alunta\Laravel\Contracts\Billable;

/**
 * One purchase of a paid plan started from the app. Its idempotency key is the Idempotency-Key of
 * the Alunta checkout session and travels in the session metadata (BillableIdentity), so a repeated
 * click replays the same session and a completed checkout or the reconciliation can find the
 * attempt again. The exact request is stored with it, so a replay sends the same body even when
 * the billable's name or billing email changed since. A pending attempt is unresolved: a billable
 * has at most one, and no other checkout starts while it is pending. It becomes completed once its
 * subscription is bound, and expired only when no second charge can follow: Alunta says its
 * session is safe to restart or does not know it, or Alunta refused to create the session at all.
 * Age alone never expires it. The alerts reported for it are kept, so each is reported once.
 *
 * The app extends this model, names the column that holds the billable's key
 * (billableKeyName()) and the rest of the checkout metadata (checkoutMetadata()), and binds its
 * model to this class in the container, so the package reads and writes the app's attempts. The
 * table is billing_checkout_attempts; the plan column holds the local plan key, which the app may
 * cast to an enum.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property string|null $alunta_session_id
 * @property array{plan_id: string, external_customer_id: string, back_url: string, success_url: string, metadata: array<string, mixed>}|null $request_payload
 * @property list<string>|null $reported_alerts
 * @property string $status
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 */
abstract class CheckoutAttempt extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_EXPIRED = 'expired';

    /**
     * The sync refusals that mean the attempt's checkout bought a subscription the app cannot apply
     * on its own.
     */
    public const array UNAPPLICABLE_PURCHASE_ALERTS = ['first_period_deferred', 'payment_provider_not_card', 'unknown_plan', 'customer_unverified', 'first_period_unsettled'];

    protected $table = 'billing_checkout_attempts';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /**
     * The fillable columns besides the billable's key column, which getFillable() puts first.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan',
        'idempotency_key',
        'alunta_session_id',
        'request_payload',
        'reported_alerts',
        'status',
        'expires_at',
    ];

    /**
     * The column that holds the key of the attempt's billable (Billable::getBillingKey()).
     */
    abstract public function billableKeyName(): string;

    /**
     * The checkout metadata after the identity keys (BillableIdentity::checkoutMetadata()), for
     * example the customer details the checkout fills in. Null and empty values are left out.
     *
     * @return array<string, mixed>
     */
    abstract public function checkoutMetadata(Billable $billable): array;

    /**
     * A new instance of the app's attempt model, which the app binds to this class in the container.
     * The package reaches the app's attempts through it, for example
     * CheckoutAttempt::appModel()->newQuery().
     */
    public static function appModel(): self
    {
        return app(self::class);
    }

    /**
     * The billable's unresolved checkout attempt, if any. Call it on the app's model, for example
     * CheckoutAttempt::appModel()::unresolvedFor().
     */
    public static function unresolvedFor(Billable $billable): ?static
    {
        return static::query()->forBillable($billable)->pending()->latest('id')->first();
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        return [$this->billableKeyName(), ...$this->fillable];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'reported_alerts' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The local plan key the attempt buys, also when the app casts the plan column to a backed enum.
     */
    public function localPlanKey(): string
    {
        $plan = $this->getAttribute('plan');
        $plan = $plan instanceof BackedEnum ? $plan->value : $plan;

        return is_string($plan) || is_int($plan) ? (string) $plan : '';
    }

    /**
     * Records that the alert was reported for this attempt. Returns false when it already was, so
     * the caller reports each alert at most once per attempt.
     */
    public function recordAlert(string $alert): bool
    {
        return DB::transaction(function () use ($alert): bool {
            $reportedAlerts = static::query()->whereKey($this->id)->lockForUpdate()->first()->reported_alerts ?? [];

            if (in_array($alert, $reportedAlerts, true)) {
                return false;
            }

            $this->forceFill(['reported_alerts' => [...$reportedAlerts, $alert]])->save();

            return true;
        });
    }

    /**
     * The unresolved attempts.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * The billable's attempts.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBillable(Builder $query, Billable $billable): Builder
    {
        return $query->where($this->qualifyColumn($this->billableKeyName()), $billable->getBillingKey());
    }

    /**
     * Whether the checkout completed but its subscription cannot be applied without help.
     */
    public function purchaseCannotBeApplied(): bool
    {
        return array_intersect($this->reported_alerts ?? [], self::UNAPPLICABLE_PURCHASE_ALERTS) !== [];
    }
}
