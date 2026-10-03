<?php

declare(strict_types=1);

namespace LogMonitor;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;
use LogMonitor\Contracts\EventWriter;
use LogMonitor\Contracts\Monitor;
use LogMonitor\Listeners\CaptureFailedJob;
use LogMonitor\Listeners\CaptureQuery;
use LogMonitor\Middleware\MonitorRequest;
use LogMonitor\Monitoring\Fingerprint;
use LogMonitor\Monitoring\MonitorManager;
use LogMonitor\Monitoring\QueueContext;
use LogMonitor\Monitoring\Sanitizer;
use LogMonitor\Monitoring\TraceContext;
use LogMonitor\Writers\DailyJsonWriter;
use Throwable;

final class MonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');
        $this->app->scoped(TraceContext::class);
        $this->app->scoped(MonitorManager::class);
        $this->app->scoped(QueueContext::class);
        $this->app->alias(MonitorManager::class, Monitor::class);
        $this->app->singleton(Fingerprint::class);
        $this->app->bind(Sanitizer::class, fn ($app) => new Sanitizer($app['config']->get('monitor.privacy', [])));
        $this->app->bind(EventWriter::class, fn ($app) => new DailyJsonWriter($app['config']->get('monitor.storage.path')));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/monitor.php' => config_path('monitor.php')], 'monitor-config');
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\MonitorCommand::class, Commands\TailCommand::class, Commands\ErrorsCommand::class,
                Commands\TraceCommand::class, Commands\SlowCommand::class, Commands\QueriesCommand::class,
                Commands\NPlusOneCommand::class, Commands\PruneCommand::class, Commands\InstallCommand::class,
            ]);
        }
        if (! $this->app['config']->get('monitor.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Kernel::class, static function ($kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(MonitorRequest::class);
            }
        });
        $this->callAfterResolving(ExceptionHandler::class, static function ($handler): void {
            // Public shared API in Laravel 11–13; a custom handler may opt into Monitor::capture.
            if ($handler instanceof Handler) {
                $handler->reportable(static function (Throwable $exception): void {
                    try {
                        $request = app()->bound('request') ? app('request') : null;
                        $state = $request?->attributes->get(MonitorRequest::STATE);
                        if (is_array($state) && ($state['escaped'] ?? false) && isset($state['context']) && ! $state['context']->markReported($exception)) {
                            return;
                        }
                        app(MonitorManager::class)->capture($exception, false);
                    } catch (Throwable) {
                    }
                });
            }
        });
        $events = $this->app['events'];
        $events->listen(RequestHandled::class, static fn (RequestHandled $event) => app(MonitorRequest::class)->complete($event->request, $event->response));
        $events->listen(QueryExecuted::class, CaptureQuery::class);
        $events->listen(JobFailed::class, CaptureFailedJob::class);
        $events->listen(JobProcessing::class, static fn (JobProcessing $event) => app(QueueContext::class)->start($event));
        $events->listen(JobProcessed::class, static fn (JobProcessed $event) => app(QueueContext::class)->finish($event->job));
        $events->listen(JobExceptionOccurred::class, static fn (JobExceptionOccurred $event) => app(QueueContext::class)->failed($event->job));
        Queue::createPayloadUsing(static function (): array {
            try {
                $trace = config('monitor.enabled') && config('monitor.queue.enabled') ? app(TraceContext::class)->traceId : null;

                return $trace !== null ? ['monitor_trace_id' => $trace] : [];
            } catch (Throwable) {
                return [];
            }
        });
    }
}
