<?php

declare(strict_types=1);

namespace LogMonitor\Facades;

use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static void capture(\Throwable $exception, bool $handled = true)
 * @method static void debug(string $message, array $context = [])
 * @method static void info(string $message, array $context = [])
 * @method static void warning(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void breadcrumb(string $message, array $context = [])
 */
final class Monitor extends Facade
{
    // Resolve the scoped manager each time, including after Octane / worker scope resets.
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return \LogMonitor\Contracts\Monitor::class;
    }

    public static function __callStatic($method, $args): mixed
    {
        try {
            return parent::__callStatic($method, $args);
        } catch (Throwable) {
            return null;
        }
    }
}
