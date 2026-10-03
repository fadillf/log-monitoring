<?php

declare(strict_types=1);

namespace LogMonitor\Monitoring;

use Illuminate\Queue\Events\JobProcessing;
use Throwable;
use WeakMap;

/** Preserve an enclosing HTTP / sync job context while a nested job runs. */
final class QueueContext
{
    /** @var WeakMap<object, TraceContext> */
    private WeakMap $parents;

    /** @var WeakMap<object, TraceContext> */
    private WeakMap $failures;

    public function __construct()
    {
        $this->parents = new WeakMap;
        $this->failures = new WeakMap;
    }

    public function start(JobProcessing $event): void
    {
        try {
            if (! config('monitor.enabled', true) || ! config('monitor.queue.enabled', true)) {
                return;
            }
            $context = app(TraceContext::class);
            $this->parents[$event->job] = clone $context;
            $context->start('job_', ['queue' => $event->job->getQueue(), 'connection' => $event->connectionName]);
            $trace = $event->job->payload()['monitor_trace_id'] ?? null;
            if (is_string($trace) && preg_match('/^(req_|job_)[0-9A-HJKMNP-TV-Z]{26}$/', $trace)) {
                $context->traceId = $trace;
            }
            $parent = $this->parents[$event->job];
            if ($parent->traceId !== null && $parent->traceId === $context->traceId) {
                $this->copyCounters($parent, $context);
            }
        } catch (Throwable) {
        }
    }

    public function finish(object $job): void
    {
        try {
            if (isset($this->parents[$job])) {
                $context = app(TraceContext::class);
                $parent = $this->parents[$job];
                if ($parent->traceId !== null && $parent->traceId === $context->traceId) {
                    $this->copyCounters($context, $parent);
                }
                $context->restore($parent);
                unset($this->parents[$job]);
            }
        } catch (Throwable) {
        }
    }

    public function failed(object $job): void
    {
        try {
            // SyncQueue emits JobExceptionOccurred before JobFailed; workers do the reverse.
            $this->failures[$job] = clone app(TraceContext::class);
            $this->finish($job);
        } catch (Throwable) {
        }
    }

    public function takeFailure(object $job): ?TraceContext
    {
        $snapshot = $this->failures[$job] ?? null;
        unset($this->failures[$job]);

        return $snapshot;
    }

    private function copyCounters(TraceContext $source, TraceContext $target): void
    {
        $target->queries = $source->queries;
        $target->queryMs = $source->queryMs;
        $target->breadcrumbs = $source->breadcrumbs;
        $target->fingerprints = $source->fingerprints;
    }
}
