<?php

declare(strict_types=1);

namespace LogMonitor\Monitoring;

final class Sanitizer
{
    public function __construct(private readonly array $config) {}

    public function sanitize(mixed $value, int $depth = 0): mixed
    {
        $remaining = max(1, (int) ($this->config['max_total_items'] ?? 1000));

        return $this->walk($value, $depth, $remaining);
    }

    private function walk(mixed $value, int $depth, int &$remaining): mixed
    {
        if ($remaining-- <= 0 || $depth >= max(1, (int) ($this->config['max_depth'] ?? 8))) {
            return '[TRUNCATED]';
        }
        if (is_array($value)) {
            $result = [];
            $limit = max(1, (int) ($this->config['max_items'] ?? 100));
            foreach ($value as $key => $item) {
                if (count($result) >= $limit || $remaining <= 0) {
                    $result['_truncated'] = true;
                    break;
                }
                if ($this->sensitive((string) $key)) {
                    $remaining--;
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = $this->walk($item, $depth + 1, $remaining);
                }
            }

            return $result;
        }
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_object($value) || is_resource($value)) {
            return '[UNSERIALIZABLE]';
        }

        return $value;
    }

    public function text(string $value): string
    {
        $value = preg_replace('/(\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$).*?\1/s', '[REDACTED]', $value) ?? '';
        // Strip URL credentials and query strings; redact common secrets embedded in messages.
        $value = preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i', '$1[REDACTED]@', $value) ?? '';
        $value = preg_replace('~(https?://[^\s?]+)\?[^\s]+~i', '$1?[REDACTED]', $value) ?? '';
        $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value) ?? '';
        $value = preg_replace('/\bBasic\s+[A-Za-z0-9+\/=]+/i', 'Basic [REDACTED]', $value) ?? '';
        $value = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[REDACTED]', $value) ?? '';
        $fields = array_map(static fn (string $field): string => preg_quote($field, '/'), $this->config['masked_fields'] ?? []);
        if ($fields !== []) {
            $value = preg_replace('/((?:'.implode('|', $fields).')["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i', '$1[REDACTED]', $value) ?? '';
        }
        // QueryException embeds SQL with substituted bindings: remove string literals too.
        $value = preg_replace('/\'(?:\'\'|[^\'])*\'/', "'[REDACTED]'", $value) ?? '';

        return substr($value, 0, max(64, (int) ($this->config['max_string_length'] ?? 2048)));
    }

    public function sql(string $sql): string
    {
        $sql = preg_replace('/(\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$).*?\1/s', '?', $sql) ?? '';
        $sql = preg_replace('~/\*.*?\*/|--[^\r\n]*~s', '', $sql) ?? '';
        $sql = preg_replace('/\'(?:\'\'|\\\\.|[^\'\\\\])*\'|"(?:""|[^"])*"/', '?', $sql) ?? '';
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? '';

        return $this->text($sql);
    }

    public function bindings(array $bindings, bool $capture = false): array
    {
        if (! $capture) {
            return array_fill(0, min(count($bindings), (int) ($this->config['max_items'] ?? 100)), '[REDACTED]');
        }

        return $this->sanitize($bindings);
    }

    private function sensitive(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');
        foreach ($this->config['masked_fields'] ?? [] as $field) {
            $field = strtolower(preg_replace('/[^a-z0-9]/i', '', $field) ?? '');
            if ($field !== '' && str_contains($normalized, $field)) {
                return true;
            }
        }

        return false;
    }
}
