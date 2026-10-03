<?php

declare(strict_types=1);

namespace LogMonitor\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogMonitor\Contracts\EventWriter;
use LogMonitor\Facades\Monitor;
use LogMonitor\Middleware\MonitorRequest;
use LogMonitor\Monitoring\TraceContext;
use LogMonitor\Tests\TestCase;
use RuntimeException;

final class MonitoringTest extends TestCase
{
    public function test_http_events_share_unique_trace_header_and_clear_state(): void
    {
        config(['monitor.queries.enabled' => true, 'monitor.request.slow_ms' => 0, 'monitor.breadcrumbs.max' => 2]);
        Route::post('/orders/{id}', function () {
            Monitor::breadcrumb('checkout', ['password' => 'hidden']);
            Monitor::breadcrumb('payment');
            Monitor::breadcrumb('over limit');
            Monitor::warning('warning', ['api_key' => 'hidden']);
            DB::select('select 1');

            return response('created', 201);
        });
        $response = $this->post('/orders/123?token=hidden', ['password' => 'hidden']);
        $response->assertStatus(201)->assertHeader('X-Trace-ID');
        $trace = $response->headers->get('X-Trace-ID');
        self::assertMatchesRegularExpression('/^req_[0-9A-HJKMNP-TV-Z]{26}$/', $trace);
        foreach ($this->events() as $event) {
            self::assertSame($trace, $event['trace_id']);
        }
        self::assertCount(2, $this->events('breadcrumb'));
        self::assertCount(1, $this->events('slow_request'));
        $completion = $this->events('response')[0];
        self::assertSame('/orders/{id}', $completion['route']);
        self::assertSame(1, $completion['queries']);
        self::assertNull(app(TraceContext::class)->traceId);
        self::assertSame([], app(TraceContext::class)->metadata);
        self::assertStringNotContainsString('hidden', json_encode($this->events()));
        $next = $this->post('/orders/456');
        self::assertNotSame($trace, $next->headers->get('X-Trace-ID'));
        self::assertSame(1, $this->events('response')[1]['queries']);
    }

    public function test_headers_body_and_user_capture_are_configurable(): void
    {
        config(['monitor.request.trace_header' => false, 'monitor.request.capture_body' => true, 'monitor.request.capture_user' => false]);
        Route::post('/body', fn () => response('ok'));
        $this->post('/body', ['password' => 'hidden', 'nested' => ['token' => 'hidden'], 'name' => 'Jane'])->assertHeaderMissing('X-Trace-ID');
        self::assertSame('[REDACTED]', $this->events('request')[0]['body']['password']);
        self::assertSame('Jane', $this->events('request')[0]['body']['name']);
        self::assertArrayNotHasKey('user_id', $this->events('response')[0]);
    }

    public function test_automatic_and_manual_exception_reporting_preserves_status(): void
    {
        Route::get('/crash', function () {
            throw new RuntimeException('Order 123 failed token=hidden');
        });
        $response = $this->getJson('/crash')->assertStatus(500);
        $events = $this->events('exception');
        self::assertCount(1, $events);
        self::assertFalse($events[0]['handled']);
        self::assertSame($response->headers->get('X-Trace-ID'), $events[0]['trace_id']);
        self::assertStringNotContainsString('hidden', $events[0]['message']);
        self::assertNull(app(TraceContext::class)->traceId);
        Monitor::capture(new RuntimeException('manually captured'));
        self::assertTrue($this->events('exception')[1]['handled']);
        app(ExceptionHandler::class)->report(new RuntimeException('reported through Laravel'));
        self::assertFalse($this->events('exception')[2]['handled']);
    }

    public function test_reporting_inside_a_request_and_swallowed_exceptions(): void
    {
        Route::get('/caught', function () {
            try {
                throw new RuntimeException('reported caught');
            } catch (RuntimeException $exception) {
                report($exception);
            }
            try {
                throw new RuntimeException('silently swallowed');
            } catch (RuntimeException) {
            }

            return response('ok');
        });
        $response = $this->get('/caught')->assertOk();
        self::assertCount(1, $this->events('exception'));
        self::assertSame($response->headers->get('X-Trace-ID'), $this->events('exception')[0]['trace_id']);
    }

    public function test_monitoring_failure_preserves_response_and_original_exception(): void
    {
        app()->bind(EventWriter::class, fn () => new class implements EventWriter
        {
            public function write(array $event): void
            {
                throw new RuntimeException('monitor storage broke');
            }
        });
        Route::get('/ok', fn () => response('ok', 202));
        $this->get('/ok')->assertStatus(202)->assertSee('ok');
        $original = new RuntimeException('original host failure');
        $request = Request::create('/outside');
        try {
            app(MonitorRequest::class)->handle($request, function () use ($original) {
                throw $original;
            });
            self::fail('Host exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($original, $exception);
        }
        self::assertNull(app(TraceContext::class)->traceId);
    }

    public function test_monitor_dependency_resolution_failure_does_not_break_requests_or_facade_calls(): void
    {
        app()->bind(EventWriter::class, function () {
            throw new RuntimeException('writer constructor failed');
        });
        Route::get('/ok', fn () => response('host response', 203));
        $this->get('/ok')->assertStatus(203)->assertSee('host response');
        Monitor::capture(new RuntimeException('application exception'));
        Monitor::warning('dependency failure');
        self::assertSame([], $this->events());
        self::assertNull(app(TraceContext::class)->traceId);
    }

    public function test_user_identifier_and_exception_metadata_omit_model_and_stack_arguments(): void
    {
        Route::get('/user/{id}', function (Request $request) {
            $request->setUserResolver(fn () => new GenericUser(['id' => 42, 'password' => 'hidden']));
            Monitor::capture(new RuntimeException('captured', 0, new RuntimeException('previous token=hidden')));

            return response('ok');
        });
        $this->get('/user/42')->assertOk();
        $event = $this->events('exception')[0];
        self::assertSame(42, $event['user_id']);
        self::assertSame('/user/{id}', $event['route']);
        self::assertSame('GET', $event['method']);
        foreach ($event['stack'] as $frame) {
            self::assertArrayNotHasKey('args', $frame);
            self::assertArrayNotHasKey('object', $frame);
        }
        self::assertStringNotContainsString('hidden', json_encode($this->events()));
    }

    public function test_slow_threshold_and_disabled_monitoring(): void
    {
        config(['monitor.request.slow_ms' => 100000]);
        Route::get('/fast', fn () => 'ok');
        $this->get('/fast')->assertOk();
        self::assertCount(0, $this->events('slow_request'));
        config(['monitor.enabled' => false]);
        $this->get('/fast')->assertOk()->assertHeaderMissing('X-Trace-ID');
        Monitor::info('disabled');
        Monitor::capture(new RuntimeException('disabled'));
        self::assertCount(2, $this->events());
    }

    public function test_query_modes_redaction_and_bounded_nplusone(): void
    {
        config(['monitor.queries.enabled' => false, 'monitor.queries.slow_ms' => 500, 'monitor.n_plus_one.enabled' => true, 'monitor.n_plus_one.threshold' => 3]);
        $context = app(TraceContext::class);
        $context->start();
        $connection = DB::connection();
        $dispatch = fn (float $time) => event(new QueryExecuted("select * from users where password = 'hidden' and id = ?", ['hidden'], $time, $connection));
        $dispatch(10);
        self::assertSame([], $this->events());
        $dispatch(600);
        self::assertCount(1, $this->events('slow_query'));
        self::assertSame(['[REDACTED]'], $this->events('slow_query')[0]['bindings']);
        $dispatch(20);
        $dispatch(20);
        self::assertCount(1, $this->events('n_plus_one'));
        self::assertSame(4, $context->queries);
        self::assertSame(650.0, $context->queryMs);
        self::assertStringNotContainsString('hidden', json_encode($this->events()));
        config(['monitor.n_plus_one.enabled' => false, 'monitor.queries.slow_enabled' => false]);
        $before = count($this->events());
        $dispatch(1000);
        self::assertCount($before, $this->events());
        config(['monitor.queries.enabled' => true]);
        $dispatch(10);
        self::assertCount(1, $this->events('query'));
    }

    public function test_scoped_manager_and_facade_do_not_reuse_previous_request_context(): void
    {
        $first = app(TraceContext::class);
        $first->start();
        Monitor::info('first');
        app()->forgetScopedInstances();
        $second = app(TraceContext::class);
        self::assertNotSame($first, $second);
        self::assertNull($second->traceId);
        Monitor::info('second');
        self::assertNull($this->events('info')[1]['trace_id']);
        self::assertNotNull($this->events('info')[0]['trace_id']);
    }
}
