<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

final class QueriesCommand extends ReportCommand
{
    protected $signature = 'monitor:queries {--slow} {--trace=} {--since=} {--limit=20}';

    protected $description = 'Inspect recorded queries or slow queries';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $events = $this->reader->recent($this->since(), fn (array $e): bool => in_array($e['type'], $this->option('slow') ? ['slow_query'] : ['query', 'slow_query'], true) && (! $this->option('trace') || ($e['trace_id'] ?? null) === $this->option('trace')), $this->limit());
            $this->info('QUERIES');
            foreach ($events as $event) {
                $this->line($this->formatter->event($event));
            }
        });
    }
}
