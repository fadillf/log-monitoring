<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

use LogMonitor\Support\TailFollower;

final class TailCommand extends ReportCommand
{
    protected $signature = 'monitor:tail {--errors} {--status=} {--route=} {--trace=} {--from-start : Read today from the beginning} {--once : Read available events and exit}';

    protected $description = 'Follow new daily monitoring events, including log rotation';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $status = $this->option('status');
            if ($status !== null && (! ctype_digit((string) $status) || (int) $status < 100 || (int) $status > 599)) {
                throw new \InvalidArgumentException('--status must be an HTTP status between 100 and 599.');
            }
            $follower = new TailFollower($this->reader, config('monitor.storage.path'), (bool) ($this->option('from-start') || $this->option('once')));
            $stop = false;
            if (extension_loaded('pcntl') && ! $this->option('once')) {
                $this->trap([SIGINT, SIGTERM], function () use (&$stop): void {
                    $stop = true;
                });
            }
            try {
                do {
                    foreach ($follower->poll() as $event) {
                        if ($this->matches($event)) {
                            $this->line($this->formatter->event($event));
                        }
                    }
                    if ($this->option('once') && $follower->caughtUp) {
                        break;
                    }
                    if ($follower->caughtUp) {
                        usleep(200000);
                    }
                } while (! $stop);
            } finally {
                $follower->close();
            }
        });
    }

    private function matches(array $event): bool
    {
        return (! $this->option('errors') || in_array($event['type'], ['exception', 'queue_failed'], true) || ($event['status'] ?? 0) >= 500)
            && ($this->option('status') === null || (string) ($event['status'] ?? '') === (string) $this->option('status'))
            && (! $this->option('route') || in_array($this->option('route'), [$event['route'] ?? null, $event['path'] ?? null], true))
            && (! $this->option('trace') || ($event['trace_id'] ?? null) === $this->option('trace'));
    }
}
