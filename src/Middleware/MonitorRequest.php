<?php

declare(strict_types=1);

namespace LogMonitor\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use LogMonitor\Monitoring\MonitorManager;
use LogMonitor\Monitoring\TraceContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MonitorRequest
{
    public const STATE = '_log_monitor_state';

    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->get('monitor.enabled', true) || ! $this->config->get('monitor.request.enabled', true)) {
            return $next($request);
        }
        // Resolve in the active scope, never cache request services in the middleware.
        $context = null;
        $started = hrtime(true);
        try {
            $context = app(TraceContext::class);
            $monitor = app(MonitorManager::class);
            $context->start('req_', ['method' => $request->method(), 'path' => '/'.$request->path()]);
            $request->attributes->set(self::STATE, ['started' => $started, 'completed' => false, 'context' => $context]);
            $data = [];
            if ($this->config->get('monitor.request.capture_body', false)) {
                $data['body'] = $request->except(array_keys($request->allFiles()));
            }
            if ($this->config->get('monitor.request.capture_source', false)) {
                $data['source'] = $request->ip();
            }
            $monitor->event('request', $data);
        } catch (Throwable) {
            $context?->reset();

            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            try {
                $monitor->capture($exception, false);
                // Preserve a snapshot for the kernel's report callback and final rendered response.
                $state = $request->attributes->get(self::STATE);
                $state['context'] = clone $context;
                $state['escaped'] = true;
                $request->attributes->set(self::STATE, $state);
            } catch (Throwable) {
            } finally {
                $context->reset();
            }
            throw $exception;
        }

        $this->complete($request, $response);

        return $response;
    }

    public function complete(Request $request, Response $response): void
    {
        $active = null;
        try {
            $active = app(TraceContext::class);
            $state = $request->attributes->get(self::STATE);
            if (! is_array($state) || $state['completed']) {
                return;
            }
            $snapshot = $state['context'];
            // A thrown middleware exception is rendered by the kernel outside handle().
            $active->traceId = $snapshot->traceId;
            $active->metadata = $snapshot->metadata;
            $active->queries = $snapshot->queries;
            $active->queryMs = $snapshot->queryMs;
            $route = $request->route();
            $data = [
                'route' => is_object($route) && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : $active->metadata['path'],
                'status' => $response->getStatusCode(),
                'duration_ms' => round((hrtime(true) - $state['started']) / 1e6, 3),
                'memory_bytes' => memory_get_usage(true),
                'queries' => $active->queries,
                'query_duration_ms' => round($active->queryMs, 3),
            ];
            if ($this->config->get('monitor.request.capture_user', true)) {
                try {
                    $user = $request->user();
                    if ($user !== null) {
                        $data['user_id'] = $user->getAuthIdentifier();
                    }
                } catch (Throwable) {
                }
            }
            $monitor = app(MonitorManager::class);
            $monitor->event('response', $data);
            if ($data['duration_ms'] >= (float) $this->config->get('monitor.request.slow_ms', 1000)) {
                $monitor->event('slow_request', $data);
            }
            if ($this->config->get('monitor.request.trace_header', true)) {
                $response->headers->set('X-Trace-ID', $active->traceId);
            }
            $state['completed'] = true;
            unset($state['context']);
            $request->attributes->set(self::STATE, $state);
        } catch (Throwable) {
        } finally {
            $active?->reset();
        }
    }
}
