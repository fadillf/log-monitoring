<?php

declare(strict_types=1);

namespace LogMonitor\Listeners;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use LogMonitor\Monitoring\MonitorManager;
use LogMonitor\Monitoring\Sanitizer;
use LogMonitor\Monitoring\TraceContext;
use Throwable;

final class CaptureQuery
{
    public function __construct(private readonly Repository $config) {}

    public function handle(QueryExecuted $query): void
    {
        try {
            if (! $this->config->get('monitor.enabled', true)) {
                return;
            }
            $context = app(TraceContext::class);
            if ($context->traceId !== null) {
                $context->queries++;
                $context->queryMs += $query->time;
            }
            $all = $this->config->get('monitor.queries.enabled', false);
            $slow = $this->config->get('monitor.queries.slow_enabled', true) && $query->time >= (float) $this->config->get('monitor.queries.slow_ms', 500);
            $nplus = $this->config->get('monitor.n_plus_one.enabled', false) && $context->traceId !== null;
            if (! $all && ! $slow && ! $nplus) {
                return;
            }
            $sanitizer = app(Sanitizer::class);
            $sql = $sanitizer->sql($query->sql);
            $data = [
                'sql' => $sql,
                'bindings' => $sanitizer->bindings($query->bindings, (bool) $this->config->get('monitor.queries.capture_bindings', false)),
                'duration_ms' => $query->time,
                'connection' => $query->connectionName,
            ];
            $monitor = app(MonitorManager::class);
            if ($all || $slow) {
                $monitor->event($slow ? 'slow_query' : 'query', $data);
            }
            if ($nplus) {
                $key = hash('sha256', $query->connectionName.'|'.strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? $sql));
                if (isset($context->fingerprints[$key]) || count($context->fingerprints) < (int) $this->config->get('monitor.n_plus_one.max_fingerprints', 1000)) {
                    $count = $context->fingerprints[$key] = ($context->fingerprints[$key] ?? 0) + 1;
                    if ($count === max(2, (int) $this->config->get('monitor.n_plus_one.threshold', 20))) {
                        $monitor->event('n_plus_one', $data + ['message' => 'Possible N+1 detected', 'count' => $count]);
                    }
                }
            }
        } catch (Throwable) {
        }
    }
}
