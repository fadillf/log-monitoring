<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

use DateTimeImmutable;
use RuntimeException;

final class PruneCommand extends ReportCommand
{
    protected $signature = 'monitor:prune {--dry-run}';

    protected $description = 'Delete only expired package-owned daily monitoring files';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $days = (int) config('monitor.storage.retention_days', 7);
            if ($days < 1) {
                throw new RuntimeException('Retention must be at least one day.');
            }
            // Retention includes today: seven days retains today and six previous dates.
            $cutoff = new DateTimeImmutable('today -'.($days - 1).' days');
            $count = 0;
            foreach ($this->reader->files() as $file) {
                $date = substr(basename($file), 8, 10);
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($parsed === false || $parsed->format('Y-m-d') !== $date || $parsed >= $cutoff) {
                    continue;
                }
                if (! $this->option('dry-run') && ! @unlink($file)) {
                    throw new RuntimeException('Cannot remove monitoring file: '.$file);
                }
                $this->line(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$this->formatter->text(basename($file)));
                $count++;
            }
            $this->info($count.' expired monitoring files '.($this->option('dry-run') ? 'found.' : 'deleted.'));
        });
    }
}
