<?php

declare(strict_types=1);

namespace LogMonitor\Commands;

use LogMonitor\Writers\DailyJsonWriter;
use RuntimeException;

final class InstallCommand extends ReportCommand
{
    protected $signature = 'monitor:install {--force : Overwrite existing configuration}';

    protected $description = 'Publish configuration and check local monitoring storage';

    public function handle(): int
    {
        return $this->inspect(function (): void {
            $target = config_path('monitor.php');
            if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('Cannot create configuration directory.');
            }
            if (is_link($target)) {
                throw new RuntimeException('Refusing to overwrite symlinked configuration.');
            }
            if (is_file($target) && ! $this->option('force')) {
                $this->line('Existing config/monitor.php preserved. Use --force to overwrite.');
            } elseif (! copy(__DIR__.'/../../config/monitor.php', $target)) {
                throw new RuntimeException('Cannot publish monitor configuration.');
            }
            $writer = new DailyJsonWriter(config('monitor.storage.path'));
            $writer->ensureDirectory();
            // Exercise write/read/delete without adding a fake monitoring event.
            $probe = config('monitor.storage.path').'/.health-'.bin2hex(random_bytes(8));
            try {
                if (@file_put_contents($probe, 'monitor-health', LOCK_EX) === false || file_get_contents($probe) !== 'monitor-health') {
                    throw new RuntimeException('Monitoring storage health check failed.');
                }
            } finally {
                if (is_file($probe)) {
                    unlink($probe);
                }
            }
            $this->info('Monitoring installed. Storage health check passed.');
            $this->line('MONITOR_ENABLED=true; MONITOR_RETENTION_DAYS=7; MONITOR_REQUESTS=true');
            $this->line('MONITOR_SLOW_REQUEST_MS=1000; MONITOR_SLOW_QUERY_MS=500; MONITOR_QUERIES=false');
            $this->line('MONITOR_CAPTURE_REQUEST_BODY=false; MONITOR_CAPTURE_USER=true; MONITOR_TRACE_HEADER=true');
            $this->line('Schedule monitor:prune daily. Run php artisan monitor to inspect recent activity.');
            $this->line('If configuration is cached, run php artisan config:cache after changing settings.');
        });
    }
}
