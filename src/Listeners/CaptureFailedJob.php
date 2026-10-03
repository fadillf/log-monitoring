<?php

declare(strict_types=1);

namespace LogMonitor\Listeners;

use Illuminate\Queue\Events\JobFailed;
use LogMonitor\Monitoring\MonitorManager;
use LogMonitor\Monitoring\QueueContext;
use LogMonitor\Monitoring\TraceContext;
use Throwable;

final class CaptureFailedJob
{
    public function handle(JobFailed $event): void
    {
        $createdContext = false;
        $parent = null;
        try {
            if (! config('monitor.enabled', true) || ! config('monitor.queue.enabled', true)) {
                return;
            }
            $context = app(TraceContext::class);
            $snapshot = app(QueueContext::class)->takeFailure($event->job);
            if ($snapshot !== null) {
                $parent = clone $context;
                $context->restore($snapshot);
            }
            if ($context->traceId === null) {
                $context->start('job_');
                $createdContext = true;
                $trace = $event->job->payload()['monitor_trace_id'] ?? null;
                if (is_string($trace) && preg_match('/^(req_|job_)[0-9A-HJKMNP-TV-Z]{26}$/', $trace)) {
                    $context->traceId = $trace;
                }
            }
            $monitor = app(MonitorManager::class);
            $monitor->event('queue_failed', [
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'job' => $event->job->resolveName(),
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
            $monitor->capture($event->exception, false);
        } catch (Throwable) {
        } finally {
            if ($parent !== null) {
                app(TraceContext::class)->restore($parent);
            } elseif ($createdContext) {
                app(TraceContext::class)->reset();
            }
        }
    }
}
