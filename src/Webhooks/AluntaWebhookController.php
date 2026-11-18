<?php

namespace KasperStuck\Alunta\Laravel\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use KasperStuck\Alunta\Laravel\AluntaAccount;
use KasperStuck\Alunta\Sdk\WebhookEnvelope;
use KasperStuck\Alunta\Sdk\WebhookSignature;

/**
 * Receives Alunta webhooks (AluntaRoutes::webhook()). A delivery is accepted only with a Signature
 * header that is the hex HMAC-SHA256 of the raw body under the webhook secret, compared in constant
 * time before the body is trusted (WebhookSignature). A delivery for another team or test mode, and
 * a synthetic test, is acknowledged with 200 and ignored, so Alunta stops retrying it. Every other
 * delivery is stored once per dedup key (WebhookEnvelope::dedupKey()) and only a newly stored one
 * is queued, so the answer comes well within Alunta's 3-second limit and a repeated delivery is
 * processed once. Nothing is read from Alunta here: the job reads the state back.
 *
 * The dedup key comes only from the signed body: data.event_id when it is a UUID, else the SHA-256
 * of the raw body.
 * The unsigned X-Alunta-Webhook-Id header is never used, because a captured signed body resent
 * under a new header would otherwise be stored and processed again (Alunta's spec: authenticate
 * the raw body with Signature before trusting any delivery ID for deduplication). A delivery
 * without data.event_id whose bytes change between attempts, such as a replay that re-encodes the
 * JSON, is therefore stored again; processing is idempotent (the job reads Alunta back, and payment
 * events are ordered by last_payment_event_at), so that costs reads, not wrong state. Rows stored
 * before this rule may carry a header UUID as dedup_key; a redelivery of such an event gets a body
 * key and is processed once more, with the same idempotent result.
 *
 * Receipt depends only on services.alunta (enabled, webhook_secret, team_uuid, test_mode), never on
 * which billing provider the app uses by default, so subscriptions made earlier keep reporting.
 * The queued job is the class the container resolves for ProcessAluntaWebhookJob, so an app can
 * queue its own subclass.
 */
class AluntaWebhookController
{
    private const int MAX_BODY_BYTES = 256 * 1024;

    public function __invoke(Request $request): Response
    {
        if (! AluntaAccount::acceptsWebhooks()) {
            Log::warning('Alunta webhook refused because Alunta is not configured.');

            return response('', 503);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response('', 413);
        }

        $signature = $request->header('Signature');

        if (! WebhookSignature::isValid($body, is_string($signature) ? $signature : null, config()->string('services.alunta.webhook_secret'))) {
            Log::warning('Alunta webhook refused because its signature is missing or wrong.');

            return response('', 401);
        }

        $envelope = WebhookEnvelope::parse($body);

        if ($envelope === null) {
            return response('', 400);
        }

        $testMode = config('services.alunta.test_mode');

        if (! is_bool($testMode) || ! $envelope->belongsTo(config()->string('services.alunta.team_uuid'), $testMode)) {
            Log::info('Alunta webhook ignored because it belongs to another team or test mode.', ['event' => $envelope->event]);

            return response('', 200);
        }

        if ($envelope->isTest()) {
            Log::info('Alunta test webhook acknowledged.', ['event' => $envelope->event]);

            return response('', 200);
        }

        $dedupKey = $envelope->dedupKey();

        $stored = BillingWebhookEvent::query()->insertOrIgnore([
            'provider' => 'alunta',
            'dedup_key' => $dedupKey,
            'event' => $envelope->event,
            'team_uuid' => strtolower((string) $envelope->teamUuid()),
            'subscription_uuid' => $envelope->subscriptionUuid(),
            'payload' => $body,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($stored === 1) {
            $webhookEvent = BillingWebhookEvent::query()->where('provider', 'alunta')->where('dedup_key', $dedupKey)->select('id')->firstOrFail();

            dispatch(app()->make(ProcessAluntaWebhookJob::class, ['webhookEventId' => $webhookEvent->id]));
        }

        return response()->noContent();
    }
}
