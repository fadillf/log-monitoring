<?php

declare(strict_types=1);

namespace LogMonitor\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use LogMonitor\Support\LogReader;
use LogMonitor\Support\TailFollower;
use LogMonitor\Tests\TestCase;
use LogMonitor\Writers\DailyJsonWriter;

final class ConsoleTest extends TestCase
{
    private function writeEvent(string $type, array $data = []): void
    {
        (new DailyJsonWriter($this->logPath.'/logs'))->write($data + [
            'timestamp' => now()->format('Y-m-d\TH:i:s.uP'), 'type' => $type, 'trace_id' => 'req_test',
        ]);
    }

    public function test_dashboard_and_errors_group_dynamic_ids(): void
    {
        $this->writeEvent('response', ['duration_ms' => 100, 'status' => 200]);
        $this->writeEvent('response', ['duration_ms' => 900, 'status' => 500]);
        $this->writeEvent('exception', ['exception' => 'OrderError', 'message' => 'Order 123 failed', 'file' => 'app/Order.php', 'line' => 84, 'handled' => false]);
        $this->writeEvent('exception', ['exception' => 'OrderError', 'message' => 'Order 456 failed', 'file' => 'app/Order.php', 'line' => 84, 'handled' => false]);
        $this->writeEvent('exception', ['exception' => 'HandledError', 'message' => 'caught', 'handled' => true]);
        $this->artisan('monitor')->expectsOutputToContain('APPLICATION MONITOR')->expectsOutputToContain('50%')->expectsOutputToContain('500 ms')->assertSuccessful();
        $this->artisan('monitor:status')->expectsOutputToContain('APPLICATION MONITOR')->assertSuccessful();
        $this->artisan('monitor:errors', ['--unhandled' => true])->expectsOutputToContain('OrderError')->doesntExpectOutputToContain('HandledError')->assertSuccessful();
        self::assertSame(0, Artisan::call('monitor:errors', ['--handled' => true]));
        self::assertStringContainsString('HandledError', Artisan::output());
        self::assertStringNotContainsString('OrderError', Artisan::output());
        self::assertSame(1, Artisan::call('monitor:errors', ['--handled' => true, '--unhandled' => true]));
        self::assertSame(1, Artisan::call('monitor:errors', ['--since' => 'invalid']));
        self::assertSame(1, Artisan::call('monitor:errors', ['--limit' => '-1']));
    }

    public function test_trace_slow_queries_and_tail_filters(): void
    {
        $this->writeEvent('request', ['method' => 'POST', 'path' => '/orders']);
        $this->writeEvent('breadcrumb', ['message' => 'checkout']);
        $this->writeEvent('query', ['sql' => 'select users', 'duration_ms' => 10]);
        $this->writeEvent('slow_query', ['sql' => 'select orders', 'duration_ms' => 900]);
        $this->writeEvent('response', ['method' => 'POST', 'path' => '/orders', 'route' => '/orders', 'status' => 500, 'duration_ms' => 2100, 'queries' => 2, 'query_duration_ms' => 910]);
        $this->writeEvent('response', ['trace_id' => 'req_other', 'method' => 'GET', 'path' => '/fast', 'status' => 200, 'duration_ms' => 10]);
        $this->writeEvent('n_plus_one', ['sql' => 'select users', 'message' => 'Possible N+1 detected']);
        $this->artisan('monitor:trace', ['trace-id' => 'req_test'])->expectsOutputToContain('checkout')->doesntExpectOutputToContain('/fast')->assertSuccessful();
        $this->artisan('monitor:slow', ['--threshold' => 2000])->expectsOutputToContain('/orders')->doesntExpectOutputToContain('/fast')->assertSuccessful();
        $this->artisan('monitor:queries', ['--slow' => true, '--trace' => 'req_test'])->expectsOutputToContain('select orders')->doesntExpectOutputToContain('select users')->assertSuccessful();
        $this->artisan('monitor:nplus1')->expectsOutputToContain('Possible N+1 detected')->assertSuccessful();
        $this->artisan('monitor:tail', ['--once' => true, '--errors' => true, '--status' => 500, '--route' => '/orders', '--trace' => 'req_test'])->expectsOutputToContain('POST /orders 500')->doesntExpectOutputToContain('/fast')->assertSuccessful();
    }

    public function test_reader_skips_corrupt_records_and_honors_scan_bound(): void
    {
        $this->writeEvent('info', ['message' => 'first']);
        $file = $this->logPath.'/logs/monitor-'.date('Y-m-d').'.log';
        file_put_contents($file, "invalid json\n".str_repeat('x', 300000)."\n", FILE_APPEND);
        $this->writeEvent('info', ['message' => 'last']);
        $reader = app(LogReader::class);
        $events = iterator_to_array($reader->recent(new DateTimeImmutable('-1 hour')));
        self::assertSame(['last', 'first'], array_column($events, 'message'));
        self::assertNull($reader->parse('broken'));
        config(['monitor.console.max_events' => 1]);
        self::assertCount(1, iterator_to_array($reader->recent(new DateTimeImmutable('-1 hour'))));
        self::assertTrue($reader->truncated);
        $this->artisan('monitor')->expectsOutputToContain('Scan limit reached')->assertSuccessful();
    }

    public function test_live_follower_handles_partial_appends_truncation_and_rotation(): void
    {
        $this->writeEvent('info', ['message' => 'old']);
        $follower = new TailFollower(app(LogReader::class), $this->logPath.'/logs');
        self::assertSame([], $follower->poll());
        $file = $this->logPath.'/logs/monitor-'.date('Y-m-d').'.log';
        $line = json_encode(['timestamp' => now()->toIso8601String(), 'type' => 'info', 'message' => 'new']);
        file_put_contents($file, substr($line, 0, 15), FILE_APPEND);
        self::assertSame([], $follower->poll());
        file_put_contents($file, substr($line, 15)."\n", FILE_APPEND);
        self::assertSame('new', $follower->poll()[0]['message']);
        file_put_contents($file, $line."\n");
        self::assertSame('new', $follower->poll()[0]['message']);
        (new DailyJsonWriter($this->logPath.'/logs'))->write(['timestamp' => '2030-01-01', 'type' => 'info', 'message' => 'rotated']);
        self::assertSame('rotated', $follower->poll('2030-01-01')[0]['message']);
        $follower->close();
    }

    public function test_prune_retains_recent_files_and_never_removes_unrelated_or_symlinked_files(): void
    {
        mkdir($this->logPath.'/logs', 0700, true);
        $path = $this->logPath.'/logs/';
        foreach (['monitor-'.date('Y-m-d').'.log', 'monitor-'.date('Y-m-d', strtotime('-6 days')).'.log', 'monitor-'.date('Y-m-d', strtotime('-7 days')).'.log', 'monitor-2026-99-99.log', 'laravel.log', 'errors.log'] as $file) {
            file_put_contents($path.$file, 'keep');
        }
        symlink($path.'laravel.log', $path.'monitor-2000-01-01.log');
        $old = $path.'monitor-'.date('Y-m-d', strtotime('-7 days')).'.log';
        $this->artisan('monitor:prune', ['--dry-run' => true])->assertSuccessful();
        self::assertFileExists($old);
        $this->artisan('monitor:prune')->assertSuccessful();
        self::assertFileDoesNotExist($old);
        self::assertFileExists($path.'laravel.log');
        self::assertFileExists($path.'errors.log');
        self::assertFileExists($path.'monitor-2026-99-99.log');
        self::assertTrue(is_link($path.'monitor-2000-01-01.log'));
        self::assertFileExists($path.'monitor-'.date('Y-m-d', strtotime('-6 days')).'.log');
    }

    public function test_install_is_idempotent_and_only_force_overwrites_config(): void
    {
        $this->artisan('monitor:install')->expectsOutputToContain('Storage health check passed')->assertSuccessful();
        self::assertFileExists($this->configPath.'/monitor.php');
        self::assertDirectoryExists($this->logPath.'/logs');
        file_put_contents($this->configPath.'/monitor.php', '<?php // keep');
        $this->artisan('monitor:install')->expectsOutputToContain('preserved')->assertSuccessful();
        self::assertSame('<?php // keep', file_get_contents($this->configPath.'/monitor.php'));
        $this->artisan('monitor:install', ['--force' => true])->assertSuccessful();
        self::assertStringContainsString('MONITOR_ENABLED', file_get_contents($this->configPath.'/monitor.php'));
        self::assertSame([], glob($this->logPath.'/logs/.health-*'));
    }

    public function test_cli_reports_storage_failures_and_escapes_terminal_controls(): void
    {
        $this->writeEvent('breadcrumb', ['message' => "<error>literal</error>\x1b[31m"]);
        self::assertSame(0, Artisan::call('monitor:trace', ['trace-id' => 'req_test', '--no-ansi' => true]));
        self::assertStringContainsString('<error>literal</error>', Artisan::output());
        self::assertStringNotContainsString("\x1b", Artisan::output());
        config(['monitor.storage.path' => '/dev/null/impossible']);
        $this->artisan('monitor:install')->expectsOutputToContain('Cannot create monitoring directory')->assertFailed();
    }
}
