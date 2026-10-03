<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

use LogMonitor\Support\ErrorGrouper;

final class MonitorCommand extends ReportCommand
{
    protected $signature = 'monitor {--since= : Recent window, e.g. 24h}';

    protected $aliases = ['monitor:status'];

    protected $description = 'Show application request statistics and top errors';

    public function handle(ErrorGrouper $grouper): int
    {
        return $this->inspect(function () use ($grouper): void {
            $durations = [];
            $failed = $errors = 0;
            $groups = $grouper->group((function () use (&$durations, &$failed, &$errors): \Generator {
                foreach ($this->reader->recent($this->since()) as $event) {
                    if ($event['type'] === 'response') {
                        $durations[] = (float) ($event['duration_ms'] ?? 0);
                        $failed += (int) (($event['status'] ?? 200) >= 500);
                    }
                    if ($event['type'] === 'exception') {
                        $errors++;
                        yield $event;
                    }
                }
            })(), max(1, (int) config('monitor.console.max_groups', 1000)));
            sort($durations);
            $count = count($durations);
            $this->info('APPLICATION MONITOR');
            $this->rows(['Metric', 'Value'], [
                ['Environment', config('app.env')], ['Monitoring', config('monitor.enabled') ? 'enabled' : 'disabled'],
                ['Window', $this->option('since') ?: config('monitor.console.window')],
                ['Requests', $count], ['Errors', $errors], ['Error Rate (HTTP 5xx)', $count ? round(100 * $failed / $count, 2).'%' : '0%'],
                ['Avg Response', $count ? round(array_sum($durations) / $count, 2).' ms' : '-'],
                ['P95 Response', $count ? $durations[(int) ceil($count * .95) - 1].' ms' : '-'],
            ]);
            $this->info('TOP ERRORS');
            $this->rows(['Count', 'Exception', 'Location'], array_map(static fn (array $e): array => [$e['count'], $e['exception'] ?? 'Error', ($e['file'] ?? '').':'.($e['line'] ?? '')], array_slice($groups, 0, 5)));
            if ($grouper->truncated) {
                $this->warn('Distinct error group limit reached.');
            }
        });
    }
}
