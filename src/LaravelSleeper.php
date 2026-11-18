<?php

namespace KasperStuck\Alunta\Laravel;

use Illuminate\Support\Sleep;
use KasperStuck\Alunta\Sdk\Sleeper;

/**
 * Waits through Laravel's Sleep, so Sleep::fake() records the waits.
 */
final class LaravelSleeper implements Sleeper
{
    public function sleepSeconds(int $seconds): void
    {
        Sleep::for($seconds)->seconds();
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        Sleep::for($milliseconds)->milliseconds();
    }
}
