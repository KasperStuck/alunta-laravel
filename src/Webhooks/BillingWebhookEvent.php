<?php

namespace KasperStuck\Alunta\Laravel\Webhooks;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use KasperStuck\Alunta\Laravel\Database\Factories\BillingWebhookEventFactory;

/**
 * One verified billing webhook delivery, stored once per provider and dedup key before it is
 * processed, so a repeated delivery is never processed twice and a failed one can be retried.
 *
 * The table is billing_webhook_events. The app owns its migration; the package ships none.
 *
 * @property int $id
 * @property string $provider
 * @property string $dedup_key
 * @property string $event
 * @property string|null $team_uuid
 * @property string|null $subscription_uuid
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $error
 * @property int $attempts
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BillingWebhookEvent extends Model
{
    /** @use HasFactory<BillingWebhookEventFactory> */
    use HasFactory;

    protected $table = 'billing_webhook_events';

    protected $fillable = [
        'provider',
        'dedup_key',
        'event',
        'team_uuid',
        'subscription_uuid',
        'payload',
        'processed_at',
        'failed_at',
        'error',
        'attempts',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function newFactory(): BillingWebhookEventFactory
    {
        return BillingWebhookEventFactory::new();
    }
}
