<?php

namespace KasperStuck\Alunta\Laravel;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use KasperStuck\Alunta\Laravel\Webhooks\AluntaWebhookController;

/**
 * The Alunta routes. The package registers none by itself: the app calls these from its route
 * files, inside the domain or prefix group they belong to.
 */
final class AluntaRoutes
{
    /**
     * Registers POST /webhooks/alunta, named webhooks.alunta, for Alunta's webhook deliveries. Alunta
     * signs its webhooks instead of sending a session or CSRF token, so the route runs without the
     * cookie, session and CSRF middleware of the web group.
     */
    public static function webhook(): RouteDefinition
    {
        return Route::post('/webhooks/alunta', AluntaWebhookController::class)
            ->withoutMiddleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
            ])
            ->name('webhooks.alunta');
    }
}
