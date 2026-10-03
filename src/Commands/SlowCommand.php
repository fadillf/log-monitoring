<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

final class SlowCommand extends ReportCommand
{
    protected $signature = 'monitor:slow {--since=} {--threshold= : Minimum duration in milliseconds} {--limit=20}';

    protected $description = 'Inspect slow requests with query totals';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $threshold = $this->option('threshold') ?? config('monitor.request.slow_ms', 1000);
            if (! is_numeric($threshold) || (float) $threshold < 0) {
                throw new \InvalidArgumentException('--threshold must be a non-negative number.');
            }
            $events = $this->reader->recent($this->since(), static fn (array $e): bool => $e['type'] === 'response' && ($e['duration_ms'] ?? 0) >= (float) $threshold, $this->limit());
            $this->info('SLOW REQUESTS');
            $rows = [];
            foreach ($events as $e) {
                $rows[] = [$e['duration_ms'].' ms', $e['method'] ?? '', $e['route'] ?? $e['path'] ?? '', $e['status'] ?? '', $e['queries'] ?? '-', ($e['query_duration_ms'] ?? 0).' ms', $e['trace_id'] ?? '-'];
            }
            $this->rows(['Duration', 'Method', 'Route', 'Status', 'Queries', 'DB time', 'Trace'], $rows);
        });
    }
}
