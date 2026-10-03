<?php

declare(strict_types=1);

namespace LogMonitor\Contracts;

use Throwable;

interface Monitor
{
    public function capture(Throwable $exception, bool $handled = true): void;

    public function debug(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    public function breadcrumb(string $message, array $context = []): void;
}
