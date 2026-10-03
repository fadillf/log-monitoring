<?php

declare(strict_types=1);

namespace LogMonitor\Monitoring;

final class Fingerprint
{
    public function make(string $class, string $message, string $file, int $line): string
    {
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '?', $message) ?? '';
        $message = preg_replace('/\b[0-9A-HJKMNP-TV-Z]{26}\b/i', '?', $message) ?? '';
        $message = preg_replace('/\b\d{4}-\d{2}-\d{2}(?:[T ][\d:.+Z-]+)?\b/', '?', $message) ?? '';
        $message = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $message) ?? '';

        return hash('sha256', implode('|', [$class, $message, $file, $line]));
    }
}
