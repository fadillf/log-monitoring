# Project Mission

Build a reusable Laravel package for lightweight, production-friendly, terminal-first application observability.

The package must help developers diagnose backend problems significantly faster than reading `storage/logs/laravel.log`.

This package is NOT intended to replace Laravel logging.

It must sit on top of Laravel's existing facilities and provide:

- structured monitoring
- request correlation
- exception tracking
- error grouping
- request tracing
- slow request detection
- query diagnostics
- optional N+1 detection
- queue failure monitoring
- terminal-based monitoring commands
- daily rotating monitoring logs

The package must NOT require:

- a web dashboard
- a SaaS backend
- an external database
- Redis
- an external monitoring service
- an AI API
- Elasticsearch
- ClickHouse

The package should work completely inside the Laravel application where it is installed.

---

# Framework Compatibility

The package MUST support:

- Laravel 11
- Laravel 12
- Laravel 13

Minimum supported PHP version:

PHP 8.3

Composer dependencies must be designed so the package can be installed on all supported Laravel versions.

Prefer individual Illuminate packages instead of unnecessarily requiring the entire `laravel/framework`.

Use version constraints compatible with all supported versions, for example where appropriate:

```json
"illuminate/support": "^11.0 || ^12.0 || ^13.0"
```

Only require Illuminate components actually needed by the package.

Do not use Laravel APIs that exist only in Laravel 13 unless a compatibility adapter or version guard is provided.

Avoid framework internals and unstable APIs whenever possible.

Prefer:

- contracts
- service providers
- events
- middleware
- logging facilities
- public framework APIs

Avoid relying on undocumented implementation details.

---

# Future Laravel Compatibility

Architecture must make adding future Laravel major versions easy.

Do NOT scatter version checks throughout the codebase.

If framework-version-specific behavior is required, isolate it behind compatibility classes, adapters, or dedicated integration layers.

Example:

```text
src/
└── Compatibility/
    ├── Laravel11Adapter.php
    ├── Laravel12Adapter.php
    └── Laravel13Adapter.php
```

Only create these adapters when genuinely necessary.

Prefer shared framework APIs when they work consistently across supported versions.

When Laravel 14 or another future version is released, expected upgrade work should ideally be limited to:

1. Composer constraints
2. compatibility tests
3. isolated adapter changes if required

Do not design package internals tightly coupled to one Laravel major version.

---

# Package Philosophy

The package should be:

- lightweight
- safe for production
- terminal-first
- easy to install
- low-overhead
- framework-native
- easy to disable
- privacy-conscious
- modular
- extensible

Developers should be able to install it and immediately gain useful diagnostics.

Target installation experience:

```bash
composer require vendor/package-name
```

Then:

```bash
php artisan monitor:install
```

Then normal usage:

```bash
php artisan monitor
php artisan monitor:tail
php artisan monitor:errors
php artisan monitor:trace <trace-id>
php artisan monitor:slow
php artisan monitor:queries
```

The package should rely on Laravel package auto-discovery.

---

# Storage Strategy

Monitoring logs MUST be stored inside the host Laravel application.

Use:

```text
storage/logs/monitor/
```

Use DAILY log rotation.

Expected structure:

```text
storage/logs/monitor/
├── monitor-2026-10-01.log
├── monitor-2026-10-02.log
├── monitor-2026-10-03.log
└── ...
```

Do NOT create one log file per request.

Do NOT create separate error/query/request files in V1 unless a strong architectural reason exists.

Use one structured daily log and distinguish events using an event type.

Recommended storage format:

JSON Lines / NDJSON.

One JSON object per line.

Example:

```json
{"timestamp":"2026-10-02T00:10:21+07:00","trace_id":"req_01KXYZ","type":"request","method":"POST","path":"/api/orders","status":201,"duration_ms":182}
{"timestamp":"2026-10-02T00:10:22+07:00","trace_id":"req_01KXYZ","type":"exception","level":"error","exception":"Illuminate\\Database\\QueryException","file":"app/Services/OrderService.php","line":84,"handled":false}
```

Logs must be easy to stream and parse without loading the entire file into memory.

---

# Log Retention

Provide configurable retention.

Example:

```env
MONITOR_ENABLED=true
MONITOR_RETENTION_DAYS=7
```

The package must provide:

```bash
php artisan monitor:prune
```

It should delete monitoring files older than configured retention.

Design it so users may schedule:

```php
Schedule::command('monitor:prune')->daily();
```

The package may optionally register automatic pruning if this can be done safely and transparently.

Never delete Laravel's normal logs.

Only manage files owned by this package.

---

# Core Event Types

At minimum support structured events for:

```text
request
response
exception
warning
info
slow_request
slow_query
query
queue_failed
breadcrumb
```

Architecture should allow adding more event types later without rewriting the storage layer.

---

# Request Trace ID

Every monitored HTTP request must receive a unique trace ID.

Example:

```text
req_01JXYZ8D91A...
```

Prefer ULID because it is sortable and available in Laravel.

The same trace ID must correlate:

- request start
- request completion
- exceptions
- warnings
- breadcrumbs
- database queries
- slow queries
- queue-related activity when correlation is possible

Optionally expose the ID as:

```http
X-Trace-ID
```

Make response header behavior configurable.

Example:

```env
MONITOR_TRACE_HEADER=true
```

The trace ID must be available through a dedicated context service.

Avoid global mutable state that breaks under long-running workers such as Octane.

Request state MUST be cleared correctly after the lifecycle ends.

Consider compatibility with:

- PHP-FPM
- Laravel Octane
- queue workers

---

# HTTP Request Monitoring

Create middleware that records:

- timestamp
- trace ID
- environment
- HTTP method
- URI / normalized route
- status code
- duration
- memory usage when practical
- authenticated user ID when enabled
- request source when safe

Do not record request bodies by default.

Do not record passwords, tokens, cookies, authorization headers, secrets, or sensitive authentication data.

Configuration should support:

```env
MONITOR_REQUESTS=true
MONITOR_CAPTURE_USER=true
MONITOR_CAPTURE_REQUEST_BODY=false
MONITOR_SLOW_REQUEST_MS=1000
```

Sensitive fields must support masking.

Example default masked fields:

```text
password
password_confirmation
token
access_token
refresh_token
authorization
cookie
secret
api_key
client_secret
```

---

# Exception Monitoring

Unhandled exceptions must be captured automatically through Laravel-supported exception reporting mechanisms.

Captured information should include:

- trace ID
- timestamp
- exception class
- message
- file
- line
- abbreviated stack trace
- route
- HTTP method
- request URL/path
- environment
- user ID if enabled
- previous exception where useful
- handled status

Example:

```json
{
  "type": "exception",
  "handled": false
}
```

For exceptions explicitly captured inside application code:

```php
Monitor::capture($e);
```

store:

```json
{
  "type": "exception",
  "handled": true
}
```

---

# Caught Exceptions

Understand this limitation clearly:

An exception swallowed inside:

```php
try {
    ...
} catch (Throwable $e) {
    return response(...);
}
```

cannot magically be observed by the package if the application never reports or exposes it.

Provide two supported approaches:

```php
report($e);
```

and:

```php
Monitor::capture($e);
```

Prefer integration with Laravel's native exception reporting whenever possible.

Document clearly that handled exceptions require explicit reporting.

Avoid monkey-patching or unsafe interception techniques in an attempt to capture swallowed PHP exceptions.

---

# Public Monitor API

Provide a clean facade/API.

Expected API:

```php
Monitor::capture($exception);

Monitor::debug('message', $context);
Monitor::info('message', $context);
Monitor::warning('message', $context);
Monitor::error('message', $context);

Monitor::breadcrumb('message', $context);
```

Context should accept arrays.

Example:

```php
Monitor::warning('Payment provider returned empty response', [
    'order_id' => $order->id,
    'provider' => 'midtrans',
]);
```

Context data must pass through sanitization before storage.

---

# Breadcrumbs

Breadcrumbs help show what happened before an exception.

Example:

```php
Monitor::breadcrumb('Starting checkout');

Monitor::breadcrumb('Checking stock', [
    'product_id' => $product->id,
]);

Monitor::breadcrumb('Creating payment');
```

When viewing a trace, breadcrumbs should appear chronologically.

Breadcrumb storage must have configurable limits so one request cannot generate unlimited entries.

Example:

```env
MONITOR_MAX_BREADCRUMBS=100
```

---

# Database Query Monitoring

Provide optional query monitoring using Laravel-supported database events.

Configuration:

```env
MONITOR_QUERIES=false
MONITOR_SLOW_QUERY_MS=500
```

Production default SHOULD NOT log every query.

Recommended defaults:

```text
all query capture = disabled
slow query capture = enabled
```

When query monitoring is enabled capture:

- trace ID
- SQL
- bindings safely sanitized
- duration
- connection name

Never expose secrets through query bindings.

Slow queries should generate `slow_query` events.

---

# N+1 Detection

N+1 detection is optional but desirable.

Implement only if it can be done reliably without excessive overhead.

A simple initial strategy may fingerprint normalized queries within a single request.

Example:

```text
SELECT * FROM users WHERE id = ?
```

If the same normalized query executes above a configurable threshold:

```env
MONITOR_NPLUS1=true
MONITOR_NPLUS1_THRESHOLD=20
```

create a diagnostic event.

Do NOT claim definitively that something is N+1 when it is only heuristic.

Use wording such as:

```text
Possible N+1 detected
```

---

# Slow Request Detection

Automatically identify requests exceeding:

```env
MONITOR_SLOW_REQUEST_MS=1000
```

Capture:

- route
- method
- duration
- total queries when available
- DB query duration when available
- memory use when practical
- trace ID

Command:

```bash
php artisan monitor:slow
```

Expected output:

```text
SLOW REQUESTS

4.82s POST /api/report
  Trace: req_...
  Queries: 42
  DB Time: 3.91s

2.31s GET /api/dashboard
  Trace: req_...
  Queries: 118
  Possible N+1
```

---

# Queue Monitoring

Capture failed Laravel jobs.

Information:

- timestamp
- queue
- connection
- job class
- exception
- attempts when available
- trace ID / correlation ID when available

Do not serialize entire job payloads containing sensitive data into monitor logs by default.

---

# Error Fingerprinting and Grouping

The CLI should group repeated exceptions instead of presenting thousands of duplicate entries.

Generate an issue fingerprint from stable properties such as:

```text
exception class
+
normalized message
+
file
+
line
```

Do NOT include highly dynamic values directly in fingerprint generation.

Normalize common dynamic values where practical:

- numeric IDs
- UUIDs
- ULIDs
- timestamps

Example:

```text
SQLSTATE[23505]
OrderService.php:84

Occurrences: 10,284
First Seen: 08:12
Last Seen: 13:45
```

V1 may calculate grouping by scanning a bounded recent log window rather than maintaining a database.

Keep architecture open for an indexed storage engine in the future.

---

# CLI Commands

Implement the following commands.

## Dashboard

```bash
php artisan monitor
```

or:

```bash
php artisan monitor:status
```

Show a concise terminal dashboard.

Example:

```text
APPLICATION MONITOR
─────────────────────────────────────

Environment       production
Requests          12,842
Errors            37
Error Rate        0.28%
Avg Response      182 ms
P95 Response      621 ms

TOP ERRORS
─────────────────────────────────────

12x QueryException
    OrderService.php:84

8x ConnectionException
   PaymentService.php:41
```

Do not require a web UI.

---

## Live Tail

```bash
php artisan monitor:tail
```

Continuously follow today's daily monitoring log.

Example:

```text
13:23:01 GET   /api/products  200   112ms
13:23:02 POST  /api/orders    201   284ms
13:23:04 POST  /api/payment   500   923ms
         ERROR ConnectionException
         PaymentService.php:41
```

Support useful filters:

```bash
php artisan monitor:tail --errors
php artisan monitor:tail --status=500
php artisan monitor:tail --route=/api/payment
php artisan monitor:tail --trace=req_xxx
```

Implement streaming efficiently.

Do not repeatedly read the complete log file.

Handle log rotation gracefully when practical.

---

## Errors

```bash
php artisan monitor:errors
```

Support:

```bash
php artisan monitor:errors --since=1h
php artisan monitor:errors --since=24h
php artisan monitor:errors --limit=20
php artisan monitor:errors --handled
php artisan monitor:errors --unhandled
```

Group duplicate errors.

Show:

- count
- exception
- message summary
- location
- first seen
- last seen
- most recent trace ID

---

## Trace

```bash
php artisan monitor:trace <trace-id>
```

Output complete chronological activity.

Example:

```text
TRACE req_01JXYZ
────────────────────────────────────

POST /api/orders
Status: 500
Duration: 842 ms

TIMELINE

13:42:20.102 REQUEST
POST /api/orders

13:42:20.133 BREADCRUMB
Starting checkout

13:42:20.192 QUERY
SELECT ...

13:42:20.284 QUERY
INSERT ...

13:42:20.291 ERROR
QueryException

app/Services/OrderService.php:84

13:42:20.944 RESPONSE
500
```

---

## Slow Requests

```bash
php artisan monitor:slow
```

Filters:

```bash
php artisan monitor:slow --since=1h
php artisan monitor:slow --threshold=2000
php artisan monitor:slow --limit=20
```

---

## Queries

```bash
php artisan monitor:queries
```

Options:

```bash
php artisan monitor:queries --slow
php artisan monitor:queries --trace=req_xxx
php artisan monitor:queries --since=1h
```

---

## N+1

If N+1 detection is implemented:

```bash
php artisan monitor:nplus1
```

---

## Prune

```bash
php artisan monitor:prune
```

Delete package-owned log files older than configured retention.

Support:

```bash
php artisan monitor:prune --dry-run
```

---

# Installation Command

Implement:

```bash
php artisan monitor:install
```

It should:

1. publish configuration
2. create required storage directory safely
3. explain relevant environment variables
4. avoid overwriting existing config without confirmation / force option
5. perform a basic health check

Optional:

```bash
php artisan monitor:install --force
```

---

# Configuration File

Publish:

```text
config/monitor.php
```

Suggested structure:

```php
return [

    'enabled' => env('MONITOR_ENABLED', true),

    'storage' => [
        'path' => storage_path('logs/monitor'),
        'retention_days' => (int) env('MONITOR_RETENTION_DAYS', 7),
    ],

    'request' => [
        'enabled' => env('MONITOR_REQUESTS', true),
        'slow_ms' => (int) env('MONITOR_SLOW_REQUEST_MS', 1000),
        'capture_user' => env('MONITOR_CAPTURE_USER', true),
        'capture_body' => env('MONITOR_CAPTURE_REQUEST_BODY', false),
        'trace_header' => env('MONITOR_TRACE_HEADER', true),
    ],

    'queries' => [
        'enabled' => env('MONITOR_QUERIES', false),
        'slow_ms' => (int) env('MONITOR_SLOW_QUERY_MS', 500),
    ],

    'n_plus_one' => [
        'enabled' => env('MONITOR_NPLUS1', false),
        'threshold' => (int) env('MONITOR_NPLUS1_THRESHOLD', 20),
    ],

    'breadcrumbs' => [
        'max' => (int) env('MONITOR_MAX_BREADCRUMBS', 100),
    ],

];
```

Improve naming or organization when necessary, but preserve the intent.

---

# Privacy and Security

This package may run in production.

Security is critical.

Never store by default:

- passwords
- bearer tokens
- API keys
- cookies
- session IDs
- authorization headers
- refresh tokens
- client secrets
- database passwords
- payment card information

Build a reusable sanitizer.

Example:

```text
Sanitizer
├── headers
├── request body
├── query bindings
├── context
└── exception metadata
```

Allow custom masked field names through configuration.

Never dump `$_SERVER`, entire request objects, or complete environment variables.

---

# Performance Requirements

Monitoring must never become a major application bottleneck.

Important:

- avoid unnecessary allocations
- avoid full-log scans during request processing
- avoid synchronous heavy analysis
- do not calculate expensive statistics during every HTTP request
- use append-only writing
- minimize filesystem operations
- use lazy listeners where practical
- monitoring failures must not break the host application

If monitoring fails:

```text
application behavior must continue normally
```

Never turn an observability failure into an application outage.

---

# Production Safety

Package behavior must degrade safely.

Wrap monitoring internals carefully.

Do not swallow the host application's exception.

Example:

```text
Application exception
       ↓
Monitor attempts capture
       ↓
Monitor storage fails
       ↓
Original application exception continues normally
```

Never alter HTTP status codes.

Never change application business logic.

Never change exception semantics.

---

# Octane Safety

Code should be safe under Laravel Octane where reasonable.

Avoid request-specific information in static state or singleton objects that survives between requests unless properly reset.

Trace contexts and breadcrumbs must be cleared at the end of every request.

Add tests for state leakage if possible.

---

# Package Structure

Prefer a maintainable structure similar to:

```text
src/
├── MonitorServiceProvider.php
├── Facades/
│   └── Monitor.php
├── Contracts/
│   ├── Monitor.php
│   └── EventWriter.php
├── Monitoring/
│   ├── MonitorManager.php
│   ├── TraceContext.php
│   ├── Sanitizer.php
│   └── Fingerprint.php
├── Events/
│   └── ...
├── Middleware/
│   └── MonitorRequest.php
├── Listeners/
│   ├── CaptureQuery.php
│   └── CaptureFailedJob.php
├── Writers/
│   └── DailyJsonWriter.php
├── Commands/
│   ├── MonitorCommand.php
│   ├── TailCommand.php
│   ├── ErrorsCommand.php
│   ├── TraceCommand.php
│   ├── SlowCommand.php
│   ├── QueriesCommand.php
│   ├── PruneCommand.php
│   └── InstallCommand.php
└── Support/
    ├── LogReader.php
    ├── DurationParser.php
    └── TerminalFormatter.php

config/
└── monitor.php

tests/
├── Unit/
├── Feature/
└── Compatibility/
```

Do not blindly create classes that are unnecessary.

Keep responsibilities small.

---

# Package Discovery

Register package provider using Composer's Laravel package auto-discovery.

Composer example:

```json
{
  "extra": {
    "laravel": {
      "providers": [
        "Vendor\\Package\\MonitorServiceProvider"
      ]
    }
  }
}
```

If a facade is provided, register it using modern package conventions.

---

# Composer Design

Use PSR-4 autoloading.

Target PHP:

```json
"php": "^8.3"
```

Support:

```text
Laravel 11
Laravel 12
Laravel 13
```

Use explicit Illuminate version unions where required:

```text
^11.0 || ^12.0 || ^13.0
```

Avoid unnecessarily pinning patch versions.

Do not depend on packages that unnecessarily prevent installation on one of the supported Laravel versions.

Run:

```bash
composer validate --strict
```

as part of validation.

---

# Automated Compatibility Tests

Compatibility is a first-class requirement.

Use Orchestra Testbench for package integration tests where appropriate.

Create CI that validates supported Laravel versions.

Required test matrix concept:

```text
Laravel 11 + supported PHP
Laravel 12 + supported PHP
Laravel 13 + supported PHP
```

At minimum test a PHP version common to the supported framework when possible.

Because supported PHP ranges differ across Laravel majors, create a valid matrix rather than blindly generating invalid combinations.

The package itself targets PHP >= 8.3.

A reasonable baseline includes PHP 8.3 and newer supported versions.

CI must confirm Composer can resolve each Laravel major independently.

Do not rely only on:

```text
latest Laravel
```

A future package update must not accidentally break Laravel 11 or Laravel 12 while adding Laravel 13 support.

---

# Dependency Testing

Use tools such as:

```bash
composer test
composer validate --strict
```

Add static analysis when practical.

Recommended development tooling:

- Pest or PHPUnit
- Orchestra Testbench
- Laravel Pint
- Larastan / PHPStan

Do not let development tooling force runtime dependencies unnecessarily.

---

# Test Coverage Requirements

Write tests for at least:

## Request tracing

- trace ID generated
- same trace ID used by related events
- header emitted when enabled
- state cleared after request

## Daily writer

- correct daily filename
- append behavior
- valid JSON per line
- directory created safely

## Exceptions

- unhandled exception captured
- manual `Monitor::capture()` works
- handled status correct
- monitor failure does not change host exception behavior

## Sanitization

- passwords masked
- authorization masked
- token masked
- nested arrays masked
- custom sensitive fields masked

## Slow requests

- below threshold ignored
- above threshold recorded

## Queries

- disabled mode has minimal behavior
- slow query detected
- bindings sanitized

## Error grouping

- identical errors grouped
- dynamic IDs do not unnecessarily split fingerprints

## CLI

- monitor dashboard renders
- tail parser works
- errors command groups errors
- trace command filters by trace ID
- slow command filters correctly
- prune deletes only package files

## Retention

- old package logs deleted
- recent logs retained
- unrelated files never deleted

## Compatibility

Run feature tests under every supported Laravel major.

---

# README

Create a professional README containing:

1. What the package does
2. Why it exists
3. Supported versions
4. Installation
5. Configuration
6. Quick start
7. Commands
8. Capturing handled exceptions
9. Breadcrumbs
10. Query monitoring
11. Production recommendations
12. Security/privacy behavior
13. Performance considerations
14. Upgrade policy
15. Version compatibility table

Example compatibility table:

```text
Package      Laravel      PHP
1.x          11-13        >=8.3
```

Explain that exceptions swallowed by application `catch` blocks require:

```php
report($e);
```

or:

```php
Monitor::capture($e);
```

Do not imply otherwise.

---

# Upgrade Policy

Follow Semantic Versioning.

Package releases:

```text
PATCH
bug fixes

MINOR
backwards-compatible features

MAJOR
breaking package API changes
```

Laravel adding a new major version does NOT automatically require a new package major version if support can be added backwards-compatibly.

For example, when Laravel 14 becomes available:

1. review official Laravel upgrade/release documentation
2. update dependency constraints if compatible
3. update compatibility layer only where necessary
4. add Laravel 14 CI matrix
5. run full Laravel 11/12/13/14 suite
6. update README compatibility table
7. release a minor package version when no package API breaks

Do not remove older supported Laravel versions casually.

Dropping Laravel 11 support, for example, is a package compatibility change that must be intentional and documented.

---

# Renovation / Dependency Updates

Configure dependency maintenance so future Laravel releases can be evaluated easily.

If GitHub Actions is being used, add Dependabot configuration for Composer and GitHub Actions.

Dependabot should create PRs for dependency updates rather than automatically merging framework major updates.

Framework major-version upgrades must always pass the entire compatibility matrix before merging.

Do not auto-merge major Laravel dependency changes.

---

# CI

Create GitHub Actions.

CI should include:

```text
composer validate
tests
static analysis
code style check
compatibility matrix
```

Ensure a failure in Laravel 11 compatibility is visible separately from Laravel 12 and Laravel 13.

Prefer reproducible dependency resolution.

---

# Commands UX

CLI output is a core product feature.

Use Laravel Console / Symfony Console capabilities.

Output should be:

- readable
- concise
- color-aware
- useful over SSH
- usable without a graphical terminal
- easy to scan during incidents

Avoid excessive decorative output.

Respect:

```text
--no-ansi
```

where provided by Symfony/Laravel console.

Use tables where they improve clarity.

---

# Large Log Handling

Do NOT implement commands by loading a multi-gigabyte log file entirely into memory.

Build readers that:

- stream line-by-line
- can scan from the end where useful
- honor limits
- stop early when sufficient matches are found
- support bounded time windows

Example:

```bash
php artisan monitor:errors --since=1h --limit=20
```

should not necessarily parse 30 days of logs.

Design `LogReader` so storage implementations can be replaced later.

---

# Extension Architecture

Create an abstraction for storage writing.

For example:

```php
interface EventWriter
{
    public function write(array $event): void;
}
```

V1 implementation:

```text
DailyJsonWriter
```

This leaves room later for:

```text
DatabaseWriter
RedisWriter
RemoteWriter
ClickHouseWriter
```

Do NOT implement these additional writers now unless required.

Likewise provide a reader abstraction if appropriate.

---

# Do Not Overengineer V1

V1 priority:

1. installation
2. daily structured logs
3. trace IDs
4. HTTP request tracking
5. exception tracking
6. manual capture
7. breadcrumbs
8. slow request tracking
9. slow query tracking
10. error grouping
11. terminal commands
12. retention
13. sanitization
14. tests
15. Laravel 11/12/13 compatibility

Features explicitly NOT required for initial implementation:

- AI explanation
- web dashboard
- cloud service
- external collector
- OpenTelemetry backend
- distributed tracing across multiple applications
- ClickHouse
- Elasticsearch
- billing
- authentication UI
- remote alerts

Keep architecture extensible but do not implement speculative systems.

---

# Implementation Workflow for the Agent

Before coding:

1. Inspect the current repository.
2. Identify whether this is an empty package or existing package.
3. Read existing `composer.json`.
4. Check existing namespace and package name.
5. Preserve existing conventions where reasonable.
6. Check Laravel package development best practices.
7. Build a concrete implementation plan internally.

Then implement incrementally.

Recommended implementation order:

```text
Composer/package skeleton
        ↓
Service provider
        ↓
Configuration
        ↓
Event schema
        ↓
Daily writer
        ↓
Trace context
        ↓
HTTP middleware
        ↓
Exception integration
        ↓
Monitor public API
        ↓
Breadcrumbs
        ↓
Query monitoring
        ↓
Queue failures
        ↓
Log reader
        ↓
CLI commands
        ↓
Grouping/fingerprint
        ↓
Pruning
        ↓
Tests
        ↓
CI compatibility matrix
        ↓
README
```

After every significant phase:

- run relevant tests
- review errors
- fix before proceeding

---

# Code Quality Rules

Use:

- strict typing where appropriate
- constructor injection
- contracts/interfaces around replaceable infrastructure
- small focused classes
- immutable event data where beneficial
- explicit return types
- PSR-12 / Laravel style

Avoid:

- giant manager classes
- hidden global state
- unnecessary static methods
- premature abstractions
- unnecessary dependencies
- framework internals
- copy-pasted Laravel-version branches

Use Laravel Pint.

---

# Failure Isolation

This package is observability infrastructure.

It MUST NOT become the reason production goes down.

Any failure inside:

```text
writer
sanitizer
fingerprinting
request metrics
query listener
terminal metadata
```

must be handled appropriately.

During runtime monitoring, prefer failing silently or falling back safely when monitoring itself breaks.

Do not hide failures while running explicit diagnostic Artisan commands; those should report meaningful errors.

---

# Definition of Done

The package is considered complete for V1 when:

1. It installs successfully in Laravel 11.
2. It installs successfully in Laravel 12.
3. It installs successfully in Laravel 13.
4. Tests pass for every supported framework version.
5. PHP 8.3+ is supported.
6. Daily monitoring files are generated.
7. Requests have trace IDs.
8. Unhandled exceptions are automatically tracked.
9. Handled exceptions can be tracked via `report()` or `Monitor::capture()`.
10. Sensitive information is sanitized.
11. Slow requests are detected.
12. Slow queries are detected when configured.
13. Failed jobs are captured.
14. Repeated errors can be grouped.
15. `monitor:tail` works in real time.
16. `monitor:errors` works.
17. `monitor:trace` works.
18. `monitor:slow` works.
19. `monitor:queries` works.
20. `monitor:prune` works safely.
21. `monitor:install` works.
22. Package overhead remains reasonable.
23. Monitoring failures do not break the host app.
24. README is complete.
25. Composer validation passes.
26. CI compatibility matrix passes.
27. Architecture permits adding Laravel 14+ without rewriting the package.

---

# Final Agent Instruction

Do not only generate scaffolding.

Implement a working V1 package end-to-end.

When encountering differences between Laravel 11, 12, and 13:

- investigate the public API available in each version
- choose the most stable shared API
- isolate unavoidable differences
- add tests proving compatibility

Do not sacrifice Laravel 11 compatibility merely because the development environment currently uses Laravel 13.

Do not sacrifice Laravel 13 compatibility merely because older implementation patterns still work in Laravel 11.

Do not claim compatibility unless it is covered by automated tests.

At completion:

1. run all tests available in the current environment
2. run Composer validation
3. run static analysis if configured
4. run code-style checks
5. review `git diff`
6. summarize architecture
7. list created commands
8. report test results
9. explicitly mention any compatibility test that could not be executed locally
10. do not commit or push unless explicitly instructed