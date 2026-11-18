<?php

namespace KasperStuck\Alunta\Laravel;

use Illuminate\Support\Facades\Log;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Logs through the Log facade at call time, with the level method of each record, so Log::spy()
 * and Log::fake() see it as Log::warning() and so on.
 */
final class LaravelLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param  mixed  $level
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        match ($level) {
            LogLevel::EMERGENCY => Log::emergency($message, $context),
            LogLevel::ALERT => Log::alert($message, $context),
            LogLevel::CRITICAL => Log::critical($message, $context),
            LogLevel::ERROR => Log::error($message, $context),
            LogLevel::WARNING => Log::warning($message, $context),
            LogLevel::NOTICE => Log::notice($message, $context),
            LogLevel::INFO => Log::info($message, $context),
            LogLevel::DEBUG => Log::debug($message, $context),
            default => throw new InvalidArgumentException('Unknown log level.'),
        };
    }
}
