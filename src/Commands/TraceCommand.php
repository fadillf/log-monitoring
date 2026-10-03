<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

final class TraceCommand extends ReportCommand
{
    protected $signature = 'monitor:trace {trace-id} {--since= : Recent window; increase to find older traces} {--limit=1000}';

    protected $description = 'Show chronological activity for a trace';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $limit = $this->limit(1000);
            $events = iterator_to_array($this->reader->recent($this->since(), fn (array $e): bool => ($e['trace_id'] ?? null) === $this->argument('trace-id'), $limit + 1));
            if (count($events) > $limit) {
                $this->warn('Trace event limit reached; showing the most recent activity. Increase --limit up to 1000.');
                array_pop($events);
            }
            usort($events, static fn (array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp']));
            $this->info('TRACE '.$this->formatter->text($this->argument('trace-id')));
            if ($events === []) {
                $this->line('No matching events in this window.');
            }
            foreach ($events as $event) {
                $this->line($this->formatter->event($event));
                if (isset($event['context']) && $event['context'] !== []) {
                    $this->line('  '.$this->formatter->text($event['context']));
                }
            }
        });
    }
}
