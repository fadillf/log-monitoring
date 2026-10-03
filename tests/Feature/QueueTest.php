<?php

declare(strict_types=1);

namespace LogMonitor\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogMonitor\Facades\Monitor;
use LogMonitor\Monitoring\TraceContext;
use LogMonitor\Tests\TestCase;
use RuntimeException;

final class QueueTest extends TestCase
{
    public function test_sync_jobs_preserve_enclosing_request_correlation_and_breadcrumbs(): void
    {
        Route::get('/dispatch', function () {
            Monitor::breadcrumb('before dispatch');
            DB::select('select 1');
            app('queue')->connection('sync')->push(new MonitoredJob);
            Monitor::breadcrumb('after dispatch');

            return response('ok');
        });
        $response = $this->get('/dispatch')->assertOk();
        $trace = $response->headers->get('X-Trace-ID');
        self::assertNotNull($trace);
        foreach ($this->events() as $event) {
            self::assertSame($trace, $event['trace_id']);
        }
        self::assertCount(2, $this->events('breadcrumb'));
        self::assertSame(2, $this->events('response')[0]['queries']);
        self::assertNull(app(TraceContext::class)->traceId);
    }

    public function test_failed_job_is_recorded_without_payload_and_worker_state_is_cleared(): void
    {
        try {
            app('queue')->connection('sync')->push(new MonitoredJob(true, 'payload-secret'));
            self::fail('Job exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('job failed token=hidden', $exception->getMessage());
            report($exception);
        }
        $event = $this->events('queue_failed')[0];
        self::assertSame(MonitoredJob::class, $event['job']);
        self::assertSame(1, $event['attempts']);
        self::assertSame('sync', $event['connection']);
        self::assertStringStartsWith('job_', $event['trace_id']);
        self::assertSame($this->events('info')[0]['trace_id'], $event['trace_id']);
        self::assertStringNotContainsString('payload-secret', json_encode($this->events()));
        self::assertStringNotContainsString('hidden', json_encode($this->events()));
        self::assertNull(app(TraceContext::class)->traceId);
        self::assertCount(1, $this->events('exception'));
        app('queue')->connection('sync')->push(new MonitoredJob);
        self::assertNull(app(TraceContext::class)->traceId);
    }

    public function test_database_worker_preserves_dispatched_trace_and_clears_failed_job_state(): void
    {
        config([
            'queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.failed.driver' => null,
        ]);
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Route::get('/enqueue', function () {
            app('queue')->connection('database')->push(new MonitoredJob(true, 'payload-secret'));

            return response('queued', 202);
        });
        $response = $this->get('/enqueue')->assertStatus(202);
        $trace = $response->headers->get('X-Trace-ID');
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        self::assertSame($trace, $payload['monitor_trace_id']);
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));
        self::assertCount(1, $this->events('queue_failed'));
        self::assertSame($trace, $this->events('queue_failed')[0]['trace_id']);
        self::assertSame($trace, $this->events('info')[0]['trace_id']);
        self::assertCount(1, $this->events('exception'));
        self::assertNull(app(TraceContext::class)->traceId);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertStringNotContainsString('payload-secret', json_encode($this->events()));
    }
}

final class MonitoredJob implements ShouldQueue
{
    public function __construct(public bool $fail = false, public string $secret = '') {}

    public function handle(): void
    {
        Monitor::info('job running');
        DB::select('select 1');
        if ($this->fail) {
            throw new RuntimeException('job failed token=hidden');
        }
    }
}
