<?php

declare(strict_types=1);

namespace LogMonitor\Support;

use LogMonitor\Monitoring\Fingerprint;

final class ErrorGrouper
{
    public bool $truncated = false;

    public function __construct(private readonly Fingerprint $fingerprint) {}

    public function group(iterable $events, int $maxGroups = 1000): array
    {
        $groups = [];
        $this->truncated = false;
        foreach ($events as $event) {
            $key = $event['fingerprint'] ?? $this->fingerprint->make($event['exception'] ?? 'Error', $event['message'] ?? '', $event['file'] ?? '', (int) ($event['line'] ?? 0));
            if (! isset($groups[$key])) {
                if (count($groups) >= $maxGroups) {
                    $this->truncated = true;

                    continue;
                }
                $groups[$key] = $event + ['count' => 0, 'first_seen' => $event['timestamp'], 'last_seen' => $event['timestamp']];
            }
            $groups[$key]['count']++;
            $groups[$key]['first_seen'] = min($groups[$key]['first_seen'], $event['timestamp']);
            if ($event['timestamp'] >= $groups[$key]['last_seen']) {
                $groups[$key]['last_seen'] = $event['timestamp'];
                $groups[$key]['trace_id'] = $event['trace_id'] ?? null;
            }
        }
        uasort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_values($groups);
    }
}
