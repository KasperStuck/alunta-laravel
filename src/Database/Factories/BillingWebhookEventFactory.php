<?php

namespace KasperStuck\Alunta\Laravel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use KasperStuck\Alunta\Laravel\Webhooks\BillingWebhookEvent;

/**
 * Test data for stored webhook deliveries (BillingWebhookEvent::factory()). It needs Faker, a
 * development dependency of the app.
 *
 * @extends Factory<BillingWebhookEvent>
 */
class BillingWebhookEventFactory extends Factory
{
    /**
     * @var class-string<BillingWebhookEvent>
     */
    protected $model = BillingWebhookEvent::class;

    /**
     * Define the model's default state: an unprocessed Alunta subscription.created delivery.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'alunta',
            'dedup_key' => fake()->unique()->uuid(),
            'event' => 'subscription.created',
            'team_uuid' => fake()->uuid(),
            'subscription_uuid' => null,
            'payload' => ['event' => 'subscription.created', 'data' => []],
            'attempts' => 0,
        ];
    }
}
