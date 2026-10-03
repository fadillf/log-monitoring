<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

final class NPlusOneCommand extends ReportCommand
{
    protected $signature = 'monitor:nplus1 {--since=} {--trace=} {--limit=20}';

    protected $description = 'Inspect heuristic possible N+1 diagnostics';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $this->info('POSSIBLE N+1 DIAGNOSTICS');
            foreach ($this->reader->recent($this->since(), fn (array $e): bool => $e['type'] === 'n_plus_one' && (! $this->option('trace') || ($e['trace_id'] ?? null) === $this->option('trace')), $this->limit()) as $event) {
                $this->line($this->formatter->event($event));
            }
        });
    }
}
