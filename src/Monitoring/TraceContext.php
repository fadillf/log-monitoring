<?php

declare(strict_types=1);

namespace LogMonitor\Monitoring;

use Illuminate\Support\Str;
use Throwable;
use WeakMap;

final class TraceContext
{
    public ?string $traceId = null;

    public array $metadata = [];

    public int $breadcrumbs = 0;

    public int $queries = 0;

    public float $queryMs = 0;

    public array $fingerprints = [];

    /** @var WeakMap<Throwable, bool> */
    private WeakMap $reported;

    public function __construct()
    {
        $this->reset();
    }

    public function start(string $prefix = 'req_', array $metadata = []): string
    {
        $this->reset();
        $this->metadata = $metadata;

        return $this->traceId = $prefix.Str::ulid();
    }

    public function markReported(Throwable $exception): bool
    {
        if (isset($this->reported[$exception])) {
            return false;
        }
        $this->reported[$exception] = true;

        return true;
    }

    public function reset(): void
    {
        $this->traceId = null;
        $this->metadata = [];
        $this->breadcrumbs = $this->queries = 0;
        $this->queryMs = 0;
        $this->fingerprints = [];
        $this->reported = new WeakMap;
    }

    public function restore(self $snapshot): void
    {
        $this->traceId = $snapshot->traceId;
        $this->metadata = $snapshot->metadata;
        $this->breadcrumbs = $snapshot->breadcrumbs;
        $this->queries = $snapshot->queries;
        $this->queryMs = $snapshot->queryMs;
        $this->fingerprints = $snapshot->fingerprints;
        $this->reported = $snapshot->reported;
    }
}
