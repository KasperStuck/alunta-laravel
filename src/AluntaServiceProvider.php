<?php

namespace KasperStuck\Alunta\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use KasperStuck\Alunta\Laravel\Console\ReconcileAluntaSubscriptionsCommand;
use KasperStuck\Alunta\Laravel\Http\LaravelHttpTransport;
use KasperStuck\Alunta\Sdk\AluntaClient;
use KasperStuck\Alunta\Sdk\RequestPacer;

/**
 * Registers the Alunta services and, in the console, the billing:reconcile-alunta command; the app
 * schedules the command itself. Auto-discovered through the package's extra.laravel.providers.
 *
 * The SDK client and its transport are built anew for each resolution; the pacer is one instance
 * per application, so pacing covers every client of the process.
 */
class AluntaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestPacer::class);
        $this->app->bind(LaravelHttpTransport::class);
        $this->app->bind(AluntaClient::class, fn (Application $app): AluntaClient => new AluntaClient(
            $app->make(LaravelHttpTransport::class),
            new ConfigCredentialsProvider,
            new LaravelSleeper,
            $app->make(RequestPacer::class),
            new LaravelLogger,
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileAluntaSubscriptionsCommand::class,
            ]);
        }
    }
}
