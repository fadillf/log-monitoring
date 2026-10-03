<?php

declare(strict_types=1);

namespace LogMonitor\Monitoring;

use Illuminate\Contracts\Config\Repository;
use LogMonitor\Contracts\EventWriter;
use LogMonitor\Contracts\Monitor;
use Throwable;
use WeakMap;

final class MonitorManager implements Monitor
{
    /** @var WeakMap<Throwable, bool> */
    private WeakMap $captured;

    public function __construct(
        private readonly EventWriter $writer,
        private readonly TraceContext $context,
        private readonly Sanitizer $sanitizer,
        private readonly Fingerprint $fingerprint,
        private readonly Repository $config,
    ) {
        $this->captured = new WeakMap;
    }

    public function event(string $type, array $data = []): void
    {
        try {
            if (! $this->config->get('monitor.enabled', true)) {
                return;
            }
            $event = $this->sanitizer->sanitize(array_merge($this->context->metadata, $data));
            // Correlation and schema keys cannot be overwritten by application context.
            $event['timestamp'] = now()->format('Y-m-d\TH:i:s.uP');
            $event['trace_id'] = $this->context->traceId;
            $event['type'] = $type;
            $event['environment'] = $this->config->get('app.env', 'production');
            $this->writer->write($event);
        } catch (Throwable) {
            // Observability is best effort. Never change the host application's behavior.
        }
    }

    public function capture(Throwable $exception, bool $handled = true): void
    {
        try {
            if (! $this->config->get('monitor.enabled', true) || isset($this->captured[$exception]) || ! $this->context->markReported($exception)) {
                return;
            }
            $this->captured[$exception] = true;
            $message = $this->sanitizer->text($exception->getMessage());
            $frames = [];
            foreach (array_slice($exception->getTrace(), 0, max(0, (int) $this->config->get('monitor.exceptions.stack_frames', 15))) as $frame) {
                // Never record stack arguments or objects.
                $frames[] = array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function', 'type']));
            }
            $previous = $exception->getPrevious();
            if (isset($this->context->metadata['method']) && app()->bound('request')) {
                try {
                    $request = app('request');
                    $route = $request->route();
                    if (is_object($route) && method_exists($route, 'uri')) {
                        $this->context->metadata['route'] = '/'.ltrim($route->uri(), '/');
                    }
                    if ($this->config->get('monitor.request.capture_user', true) && ($user = $request->user()) !== null) {
                        $this->context->metadata['user_id'] = $user->getAuthIdentifier();
                    }
                } catch (Throwable) {
                }
            }
            $this->event('exception', [
                'level' => 'error',
                'exception' => $exception::class,
                'message' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'handled' => $handled,
                'fingerprint' => $this->fingerprint->make($exception::class, $message, $exception->getFile(), $exception->getLine()),
                'stack' => $frames,
                'previous' => $previous ? ['exception' => $previous::class, 'message' => $this->sanitizer->text($previous->getMessage())] : null,
            ]);
        } catch (Throwable) {
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function breadcrumb(string $message, array $context = []): void
    {
        try {
            if ($this->context->traceId === null || $this->context->breadcrumbs >= (int) $this->config->get('monitor.breadcrumbs.max', 100)) {
                return;
            }
            $this->context->breadcrumbs++;
            $this->event('breadcrumb', ['message' => $message, 'context' => $context]);
        } catch (Throwable) {
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        $type = in_array($level, ['warning', 'info'], true) ? $level : ($level === 'error' ? 'exception' : 'info');
        $data = ['level' => $level, 'message' => $message, 'context' => $context];
        if ($level === 'error') {
            $data += ['exception' => 'ApplicationError', 'handled' => true];
            try {
                $data['fingerprint'] = $this->fingerprint->make('ApplicationError', $this->sanitizer->text($message), '', 0);
            } catch (Throwable) {
                return;
            }
        }
        $this->event($type, $data);
    }
}
