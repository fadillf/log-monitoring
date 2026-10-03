<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

use LogMonitor\Support\ErrorGrouper;

final class ErrorsCommand extends ReportCommand
{
    protected $signature = 'monitor:errors {--since= : Recent window, e.g. 1h} {--limit=20} {--handled} {--unhandled}';

    protected $description = 'Group repeated exceptions in a bounded recent log window';

    public function handle(ErrorGrouper $grouper): int
    {
        return $this->inspect(function () use ($grouper): void {
            if ($this->option('handled') && $this->option('unhandled')) {
                throw new \InvalidArgumentException('Choose either --handled or --unhandled.');
            }
            $limit = $this->limit();
            $events = $this->reader->recent($this->since(), fn (array $e): bool => $e['type'] === 'exception'
                && (! $this->option('handled') || ($e['handled'] ?? false))
                && (! $this->option('unhandled') || ! ($e['handled'] ?? false)));
            $groups = $grouper->group($events, max(1, (int) config('monitor.console.max_groups', 1000)));
            $this->info('ERRORS');
            $rows = [];
            foreach (array_slice($groups, 0, $limit) as $group) {
                $rows[] = [$group['count'], $group['exception'] ?? 'Error', substr($group['message'] ?? '', 0, 160), ($group['file'] ?? '').':'.($group['line'] ?? ''), $group['first_seen'], $group['last_seen'], $group['trace_id'] ?? '-'];
            }
            $this->rows(['Count', 'Exception', 'Message', 'Location', 'First seen', 'Last seen', 'Latest trace'], $rows);
            if ($grouper->truncated) {
                $this->warn('Group limit reached; some distinct errors were omitted.');
            }
        });
    }
}
