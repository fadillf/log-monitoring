<?php

declare(strict_types=1);

namespace LogMonitor\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;

final class TerminalFormatter
{
    public function text(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            $value = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value) ?? '';

        return OutputFormatter::escape($value);
    }

    public function event(array $event): string
    {
        $time = substr((string) ($event['timestamp'] ?? ''), 11, 12);
        $detail = match ($event['type'] ?? '') {
            'request', 'response', 'slow_request' => sprintf('%s %s %s %sms', $event['method'] ?? '', $event['route'] ?? $event['path'] ?? '', $event['status'] ?? '', $event['duration_ms'] ?? ''),
            'query', 'slow_query', 'n_plus_one' => ($event['duration_ms'] ?? '').'ms '.($event['sql'] ?? '').' '.($event['message'] ?? ''),
            'exception', 'queue_failed' => ($event['exception'] ?? 'Error').' '.($event['message'] ?? '').' '.($event['file'] ?? '').':'.($event['line'] ?? ''),
            default => $event['message'] ?? '',
        };

        return $this->text($time.' '.strtoupper($event['type'] ?? '').' '.$detail.'  ['.($event['trace_id'] ?? '-').']');
    }
}
