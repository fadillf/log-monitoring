<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use LogMonitor\Support\DurationParser;
use LogMonitor\Support\LogReader;
use LogMonitor\Support\TerminalFormatter;
use Throwable;

abstract class ReportCommand extends Command
{
    public function __construct(protected readonly LogReader $reader, protected readonly TerminalFormatter $formatter)
    {
        parent::__construct();
    }

    protected function inspect(callable $report): int
    {
        try {
            $report();
            if ($this->reader->truncated) {
                $this->warn('Scan limit reached; results cover only the most recent scanned events. Increase monitor.console.max_events or narrow --since.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($this->formatter->text($exception->getMessage()));

            return self::FAILURE;
        }
    }

    protected function since(): DateTimeImmutable
    {
        return (new DurationParser)->since((string) ($this->option('since') ?: config('monitor.console.window', '24h')));
    }

    protected function limit(int $default = 20): int
    {
        $value = $this->option('limit') ?? $default;
        if (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 1000) {
            throw new \InvalidArgumentException('--limit must be an integer between 1 and 1000.');
        }

        return (int) $value;
    }

    protected function rows(array $headers, iterable $rows): void
    {
        $safe = [];
        foreach ($rows as $row) {
            $safe[] = array_map($this->formatter->text(...), $row);
        }
        $this->table($headers, $safe);
    }
}
