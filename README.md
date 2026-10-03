# Laravel Log Monitoring

Lightweight, terminal-first application observability for Laravel. Correlate HTTP requests, exceptions, breadcrumbs, queries, and failed jobs without a dashboard or external service. Repeated errors are grouped so an incident can be investigated without searching thousands of lines in `laravel.log`.

The package adds a separate, structured daily log inside your application. Laravel's normal logging and exception handling continue to work as usual. No database, Redis, collector, or SaaS account is required.

## Compatibility

| Package | Laravel | Package PHP minimum | CI PHP versions | Testbench |
| --- | --- | --- | --- | --- |
| 1.x | 11 | 8.3 | 8.3, 8.4 | 9.x |
| 1.x | 12 | 8.3 | 8.3, 8.4, 8.5 | 10.x |
| 1.x | 13 | 8.3 | 8.3, 8.4, 8.5 | 11.x |

Runtime dependencies use individual Illuminate components with explicit Laravel 11–13 constraints. Testbench, PHPUnit, Pint, and PHPStan are development dependencies. Framework compatibility does not extend Laravel's own security support period.

## Installation

The Composer package name is `fadillf/log-monitoring`, with the `LogMonitor` namespace. Once a release is available in your Composer registry:

```bash
composer require fadillf/log-monitoring:^1.0
php artisan monitor:install
```

For this unreleased checkout, add a local path repository to the **host application's** `composer.json`:

```json
{
    "repositories": [
        {"type": "path", "url": "/absolute/path/to/log-monitoring", "options": {"symlink": true}}
    ]
}
```

Then install it with `composer require fadillf/log-monitoring:@dev` and run `php artisan monitor:install`. This checkout does not need to be a Git repository for a local path install.

Laravel discovers the provider and facade automatically. The middleware is registered globally; you do not need to edit `bootstrap/app.php`. If your application disables package discovery, register `LogMonitor\MonitorServiceProvider` in `bootstrap/providers.php`. Avoid registering the middleware again yourself.

`monitor:install` publishes `config/monitor.php`, creates the storage directory, and checks write/read access. It preserves existing configuration. Use `--force` to overwrite it. Configuration can also be published with:

```bash
php artisan vendor:publish --tag=monitor-config
```

After changing settings in an application with cached configuration, regenerate the cache with `php artisan config:cache` and restart long-running workers.

## Quick start

Make a request to your application, then inspect it:

```bash
php artisan monitor
php artisan monitor:tail
php artisan monitor:errors --since=1h
php artisan monitor:trace req_01JXYZ8D91AAAAAAAAAAAAAAAA
```

Each monitored request receives a new sortable ULID prefixed with `req_`. By default it is returned in `X-Trace-ID`; inbound trace headers are not trusted. Inside application code:

```php
use LogMonitor\Facades\Monitor;
use LogMonitor\Monitoring\TraceContext;

Monitor::breadcrumb('Starting checkout', ['order_id' => $order->id]);
Monitor::warning('Payment provider returned empty response', [
    'order_id' => $order->id,
    'provider' => 'midtrans',
]);

$traceId = app(TraceContext::class)->traceId;
```

The facade exposes `capture`, `debug`, `info`, `warning`, `error`, and `breadcrumb`. A container-injected `LogMonitor\Contracts\Monitor` provides the same API. Context arguments are arrays and pass through the sanitizer. `debug` is an `info` event with level `debug`; `error` is a grouped `exception` event with class `ApplicationError` and `handled=true`.

## Configuration

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `MONITOR_ENABLED` | `true` | Disable automatic collectors and all runtime writes |
| `MONITOR_RETENTION_DAYS` | `7` | Retain today and the previous six calendar dates |
| `MONITOR_REQUESTS` | `true` | HTTP tracing and request metrics |
| `MONITOR_CAPTURE_USER` | `true` | Capture only the authenticated user identifier |
| `MONITOR_CAPTURE_REQUEST_BODY` | `false` | Opt into sanitized request input; uploaded files are excluded |
| `MONITOR_CAPTURE_SOURCE` | `false` | Opt into the request IP address |
| `MONITOR_TRACE_HEADER` | `true` | Emit `X-Trace-ID` on responses |
| `MONITOR_SLOW_REQUEST_MS` | `1000` | Slow request threshold |
| `MONITOR_QUERIES` | `false` | Capture all queries |
| `MONITOR_SLOW_QUERIES` | `true` | Capture slow queries even when all-query capture is off |
| `MONITOR_SLOW_QUERY_MS` | `500` | Slow query threshold |
| `MONITOR_NPLUS1` | `false` | Enable heuristic repeated-query diagnostics |
| `MONITOR_NPLUS1_THRESHOLD` | `20` | Repetitions needed for one possible N+1 diagnostic |
| `MONITOR_QUEUE` | `true` | Failed job monitoring and trace propagation |
| `MONITOR_MAX_BREADCRUMBS` | `100` | Maximum breadcrumbs per request or job |

The published config also controls storage location, custom masked fields, context depth and size limits, stack frame count, query binding policy, inspection windows, and scan limits. To disable both all-query and slow-query capture, set `MONITOR_QUERIES=false` and `MONITOR_SLOW_QUERIES=false`. Query totals remain available for request diagnostics with constant-cost counters.

## Commands

| Command | Useful options | Result |
| --- | --- | --- |
| `monitor` / `monitor:status` | `--since=24h` | Requests, errors, HTTP 5xx rate, average/P95 duration, top errors |
| `monitor:tail` | `--errors --status=500 --route=/api/payment --trace=req_...` | Follow new events without re-reading the whole file |
| `monitor:errors` | `--since=1h --limit=20 --handled` or `--unhandled` | Grouped errors with counts, location, first/last seen, latest trace |
| `monitor:trace <trace-id>` | `--since=7d --limit=1000` | Chronological request/job timeline |
| `monitor:slow` | `--since=1h --threshold=2000 --limit=20` | Slow requests, query totals, database time |
| `monitor:queries` | `--slow --trace=req_... --since=1h --limit=20` | Sanitized recorded SQL and duration |
| `monitor:nplus1` | `--since=1h --trace=req_... --limit=20` | Possible N+1 diagnostics |
| `monitor:prune` | `--dry-run` | Remove expired package daily logs only |
| `monitor:install` | `--force` | Publish configuration and check storage |

Inspection commands default to the last 24 hours. Durations accept positive seconds, minutes, hours, or days, such as `30m`, `1h`, `24h`, or `7d`, up to 366 days. Limits accept 1–1000. Filters combine, and route filters match an exact recorded path or normalized route. `--no-ansi` is supported.

Tail starts at the end of today's file, waits for complete lines, and follows daily rotation, replacement, and truncation. Use `--from-start` to include existing events or `--once` to read today's available events and exit. Stop live tail with Ctrl+C. Missing logs are normal before the first event; malformed and oversized records are skipped.

The dashboard's request count counts completed responses. Its error count counts exception events, including explicitly captured handled exceptions and `Monitor::error`. Error rate is the fraction of responses with HTTP status 500 or higher, so it cannot exceed 100% due to multiple exceptions in one request. Statistics and groups cover the selected, bounded log window.

## Capturing handled exceptions

Laravel-reported exceptions are captured through the framework's public reportable callback. Ordinary unhandled HTTP exceptions appear with `handled=false`, with their original status codes and normal Laravel log behavior preserved.

An exception swallowed by an application `catch` block cannot be detected unless the application explicitly exposes it:

```php
try {
    $gateway->charge($order);
} catch (Throwable $exception) {
    Monitor::capture($exception); // handled=true
    return response()->json(['message' => 'Payment failed'], 502);
}
```

Alternatively, use Laravel's `report($exception)`. Report callbacks cannot distinguish a caught exception reported by the application from an unhandled exception; native reporting therefore records `handled=false`. Use `Monitor::capture` when the handled distinction matters. The same exception object is captured once in an active container scope; the first capture determines its handled flag.

Laravel's reporting exclusions, throttling, exception-specific `report()` methods, and callbacks that stop reporting can prevent the package's reportable callback from running. A custom exception handler that does not extend Laravel's standard `Handler` should call `Monitor::capture` explicitly. Fatal shutdown errors, process termination, and exceptions that occur before the provider boots are outside the supported V1 lifecycle.

Stack traces omit arguments and objects and include at most 15 frames by default. Exception events include sanitized messages, class, file, line, route/path/method, environment, enabled user identifier, previous exception summary, and a fingerprint that normalizes common numeric IDs, UUIDs, ULIDs, and timestamps.

## Breadcrumbs and jobs

Breadcrumbs are appended immediately and appear chronologically with the rest of the trace. The configured maximum prevents unlimited breadcrumbs in a single lifecycle. Calls outside an active request or job do not create breadcrumbs.

Failed jobs record queue, connection, job name, attempts, exception class, sanitized message, and trace. Job payloads are never logged. Queued jobs dispatched inside a monitored lifecycle receive a `monitor_trace_id` payload field; workers use it to correlate activity when available. Otherwise jobs get their own `job_` ULID. This correlates work inside one application; it is not distributed tracing. Synchronous jobs restore the enclosing request/job context on completion or failure. Scope resets and explicit lifecycle cleanup prevent state from leaking between requests and jobs.

## Query monitoring

Production defaults record slow queries, not every query:

```env
MONITOR_QUERIES=false
MONITOR_SLOW_QUERIES=true
MONITOR_SLOW_QUERY_MS=500
```

Events contain connection, duration, and SQL with string/numeric literals removed. All bindings are redacted by default because positional placeholders cannot reliably identify sensitive columns. `queries.capture_bindings=true` is an explicit config opt-in to sanitized bindings; use it only with data you have reviewed. Named sensitive fields are still masked, but arbitrary secret values in positional bindings cannot be identified reliably.

When all-query capture is enabled, a slow query emits one `slow_query` event rather than duplicate `query` and `slow_query` records. `monitor:queries` includes both event types unless filtered with `--slow`.

Optional N+1 detection counts normalized SQL fingerprints within a lifecycle. One `Possible N+1 detected` event is emitted when a fingerprint reaches the threshold, with at most 1000 tracked fingerprints by default. Repeated statements can be intentional; this is a heuristic diagnostic, not proof of an N+1 problem.

## Production, privacy, and storage

Files live at `storage/logs/monitor/monitor-YYYY-MM-DD.log` using the application's timezone. Each line is an independent JSON event with `timestamp`, `trace_id`, `type`, and `environment`. Multiple processes append under an exclusive file lock. New directories use mode `0700`; log files use `0600`. Run Artisan commands as an account with access to these private files, and keep the directory outside the public web root.

Bodies, query strings, headers, cookies, sessions, full user models, request objects, server/environment dumps, job payloads, and stack arguments are not recorded by default. Default sensitive field names include passwords, tokens, authorization, cookies, secrets, API/client keys, sessions, and payment card fields. Masking is recursive and case/separator tolerant. Add application-specific fields to `privacy.masked_fields`. Messages also redact common credential assignments, bearer tokens, URL credentials/query strings, and quoted values. Free-form messages and route segments can contain sensitive data that no generic sanitizer can identify; keep secrets out of log messages and URLs.

Keep body and binding capture disabled in production unless required. Set `MONITOR_CAPTURE_USER=false` if storing identifiers is inappropriate for your application. Choose thresholds based on your normal latency and query profile; leave N+1 detection and all-query capture disabled unless diagnosing a specific issue.

Schedule retention explicitly in the host application:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('monitor:prune')->daily();
```

Pruning uses dates in exact `monitor-YYYY-MM-DD.log` filenames, retains at least today, and skips symlinks, invalid dates, unrelated files, and Laravel's normal logs. There is no automatic scheduler registration.

Runtime monitoring catches internal failures so storage, sanitization, or metrics failures do not replace an application exception or change a response status. Missing runtime events can indicate disabled monitoring or storage failure; `monitor:install` checks storage explicitly. Diagnostic commands report access/write/delete failures with nonzero exit codes instead of silently claiming success.

## Performance and architecture

Request processing only appends events, records timings, and updates small lifecycle counters. It performs no log scans, error grouping, or percentile calculations. File append and locking are synchronous, so storage latency and volume still matter; monitor local disk usage and avoid high-volume all-query capture on slow filesystems.

HTTP duration measures middleware and response preparation. Sending streamed response content and work scheduled after the response finishes are outside that measurement.

Inspection reads files from the end in chunks, selecting only relevant daily files. It scans at most 100,000 records and groups at most 1000 distinct errors by default. Oversized lines are skipped above 256 KiB. A scan/group cap produces an explicit CLI warning, and trace truncation is reported. Increase bounds deliberately or narrow `--since`. Memory is bounded by these caps rather than total log-file size.

`MonitorServiceProvider` integrates public middleware, reportable callbacks, query events, and queue events shared by Laravel 11–13. `TraceContext`, `QueueContext`, and `MonitorManager` are scoped services; callbacks resolve the current container scope, and the facade does not cache a scoped instance. Tests cover scope resets and consecutive lifecycles; a real Octane server is not required by the test suite.

The `EventWriter` contract allows replacing daily storage without changing collectors. To register your own writer, bind `LogMonitor\Contracts\EventWriter` in an application provider. Keep custom writes lightweight and failure-tolerant. V1 implements only local daily storage; no speculative remote backend or framework-version adapter is included.

## Development and upgrade policy

```bash
composer install
composer validate --strict
composer test
composer analyse
composer format:check
```

CI resolves Laravel 11/Testbench 9, Laravel 12/Testbench 10, and Laravel 13/Testbench 11 independently, then runs the full tests, PHPStan, and Pint. Resolved lockfiles and JUnit results are retained as CI artifacts. Library lockfiles are not committed; a consuming Laravel application should commit its own lockfile for reproducible deployments.

Reproduce one framework locally without changing the package's version unions:

```bash
composer update --with='laravel/framework:^11.0' --with='orchestra/testbench:^9.0' --with-all-dependencies
composer test
```

Use framework/Testbench `12/10` or `13/11` for the other majors. Restore the latest compatible environment with `composer update` when finished.

Releases follow Semantic Versioning: patches fix bugs, minors add compatible features, and majors change the public package API or supported compatibility contract. A future Laravel major can be added in a minor release if no package API breaks: review official release/upgrade documentation, update dependency constraints, adapt only isolated integration seams if needed, add its CI matrix, run all previously supported versions, and update this table. Dropping a supported Laravel major must be intentional and documented.

Dependabot proposes Composer and GitHub Actions updates weekly. Framework major updates require review and the full compatibility matrix; there is no auto-merge workflow.

## License

MIT. See [LICENSE](LICENSE).
