# Prism client

The client package for the [Prism](../../README.md) observability platform. Drop it into any
Laravel 12+ application to capture logs, requests, errors, traces, queries, jobs, scheduled tasks
and replica metrics and ship them, correlated by trace, to a Prism workspace.

Everything is captured automatically once two variables are set — no code changes, no manual
instrumentation required. The package deliberately depends on nothing in the Prism server
application, so it installs cleanly into a stranger's project.

## Requirements

- PHP 8.4+
- Laravel 12 or 13 (`illuminate/support` `^12.0|^13.0`)
- A Prism workspace and an **ingest** API token (Console → Settings → API tokens)

## Quickstart

Three steps and telemetry starts flowing.

**1. Install**

```bash
composer require misakstvanu/prism
```

The service provider (`Misakstvanu\Prism\PrismServiceProvider`) is auto-discovered — there is no
provider to register and no kernel file to edit.

**2. Set the two required variables**

```dotenv
PRISM_TOKEN=prism_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
PRISM_APP=your-app-slug
PRISM_ENDPOINT=https://your-workspace.prism.app/api/ingest
```

`PRISM_TOKEN` and `PRISM_APP` are the only required settings. `PRISM_ENDPOINT` defaults to the
hosted endpoint; set it to your workspace's `/api/ingest` if you self-host. Everything else has a
working default.

**3. Verify**

```bash
php artisan prism:check
```

This validates the config, lists which capture domains are on, and POSTs a single test event to
confirm the endpoint accepts your token. A green run means the next request, job or scheduled task
your app runs is already reporting to the console.

> Nothing else is needed. Requests, exceptions, logs, queries, traces, jobs, scheduled tasks and
> replica metrics are all captured by listeners the provider registers at boot.

## Required variables

| Variable | Purpose |
| --- | --- |
| `PRISM_TOKEN` | The ingest token minted in **Console → Settings → API tokens**. With no token the package silently no-ops and logs a single warning at boot — it never throws. |
| `PRISM_APP` | The application slug this process reports as, inside the workspace the token belongs to. |

## Configuration

Publish the config to override any default:

```bash
php artisan vendor:publish --tag=prism-config
```

The published `config/prism.php` documents every setting inline. Every value can also be driven by
an environment variable, so most installs never publish the file.

### Master switch and credentials

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | `PRISM_ENABLED` | `true` | The kill switch. `false` registers **no** listeners and adds zero overhead — the package is inert, as if uninstalled. |
| `token` | `PRISM_TOKEN` | `null` | Ingest token. *(required)* |
| `app` | `PRISM_APP` | `null` | Application slug this process reports as. *(required)* |
| `endpoint` | `PRISM_ENDPOINT` | `https://prism.dev/api/ingest` | Where batches are POSTed. Point at your workspace's `/api/ingest` when self-hosting. |

### Identity

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `environment` | `PRISM_ENVIRONMENT` | `APP_ENV` | Deployment tier (`production`, `staging`, …) tagged onto every event. |
| `replica` | `PRISM_REPLICA` | `gethostname()` | The name this process reports as. One replica row auto-registers per name; override with the pod/container name where the hostname is not meaningful. |

### Per-domain capture toggles

Each maps to one telemetry signal. A domain set to `false` registers no listener, so silencing a
noisy source costs nothing.

| Key | Env | Default |
| --- | --- | --- |
| `capture.requests` | `PRISM_CAPTURE_REQUESTS` | `true` |
| `capture.exceptions` | `PRISM_CAPTURE_EXCEPTIONS` | `true` |
| `capture.logs` | `PRISM_CAPTURE_LOGS` | `true` |
| `capture.queries` | `PRISM_CAPTURE_QUERIES` | `true` |
| `capture.traces` | `PRISM_CAPTURE_TRACES` | `true` |
| `capture.jobs` | `PRISM_CAPTURE_JOBS` | `true` |
| `capture.schedules` | `PRISM_CAPTURE_SCHEDULES` | `true` |
| `capture.metrics` | `PRISM_CAPTURE_METRICS` | `true` |

### Client-side sample rates

A fraction in `[0, 1]` of each domain to keep before shipping — a first reduction on top of the
server's trace-consistent sampling. `1.0` keeps everything. **Exceptions are pinned to `1.0` and
can never be sampled out at the client.**

| Key | Env | Default |
| --- | --- | --- |
| `sample_rates.requests` | `PRISM_SAMPLE_REQUESTS` | `1.0` |
| `sample_rates.logs` | `PRISM_SAMPLE_LOGS` | `1.0` |
| `sample_rates.queries` | `PRISM_SAMPLE_QUERIES` | `1.0` |
| `sample_rates.traces` | `PRISM_SAMPLE_TRACES` | `1.0` |
| `sample_rates.jobs` | `PRISM_SAMPLE_JOBS` | `1.0` |
| `sample_rates.schedules` | `PRISM_SAMPLE_SCHEDULES` | `1.0` |
| `sample_rates.metrics` | `PRISM_SAMPLE_METRICS` | `1.0` |
| `sample_rates.exceptions` | — | `1.0` (fixed) |

### Batching and flush

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `batch.size` | `PRISM_BATCH_SIZE` | `1000` | Max events one request may buffer; the excess is dropped and counted, bounding memory. |
| `batch.flush` | `PRISM_FLUSH_STRATEGY` | `terminate` | `terminate` ships after the response is returned (off the critical path — the default). `sync` ships inline before the process ends (useful for one-off scripts and tests). |
| `batch.timeout` | `PRISM_FLUSH_TIMEOUT` | `2.0` | Ingest POST timeout in seconds, kept short so a slow endpoint never stalls the process. |
| `batch.queue_threshold` | `PRISM_QUEUE_THRESHOLD` | `100` | A flush larger than this is handed to a queued job instead of sent inline. `0` keeps every flush inline. |
| `batch.queue` | `PRISM_FLUSH_QUEUE` | `null` | Queue that job is dispatched onto; `null` = default queue. |

### Per-domain limits

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `request.max_body` | `PRISM_MAX_BODY_SIZE` | `65536` | Byte cap on a captured request body (non-GET only, scrubbed then truncated). `0` = uncapped. |
| `log.channels` | — | `[]` | Logging channels to capture. Empty = the app's default channel/stack. |
| `log.level` | `PRISM_LOG_LEVEL` | `debug` | Minimum PSR-3 level captured. |
| `query.slow_threshold_ms` | `PRISM_SLOW_QUERY_MS` | `100` | A query at/above this is marked slow and always kept (it and its whole trace) regardless of sampling. `0` disables the marker. |
| `schedule.max_output` | `PRISM_MAX_OUTPUT_SIZE` | `16384` | Byte cap on a scheduled task's captured output. `0` = uncapped. |

### Runtime metrics

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `metrics.system_interval` | `PRISM_METRICS_INTERVAL` | `60` | Seconds between CPU/memory/uptime samples. Runs on every replica. |
| `metrics.queue_interval` | `PRISM_QUEUE_POLL_INTERVAL` | `30` | Seconds between queue-depth/worker samples. Runs only on a process that works the queue. |
| `metrics.replica_type` | `PRISM_REPLICA_TYPE` | `null` | `web` or `worker`. `null` infers it from the process. |

Both metric samples piggyback onto the next flush, so a shorter interval never means a dedicated
request.

### Ignore lists

Activity the client never captures. Every list but `ignore.exceptions` matches with `Str::is`
wildcards, so one pattern covers a family (`api/internal/*`, `App\Jobs\Internal\*`, `prism:*`); a
pattern without a `*` matches exactly.

| Key | Default | Meaning |
| --- | --- | --- |
| `ignore.paths` | Prism's own routes, `telescope*`, `horizon*`, `_debugbar*`, `nova*`, `up`, `health*` | Request URI patterns never captured. |
| `ignore.jobs` | `[]` | Queued job class names, as resolved for display — for a queued broadcast that is the event class, not the framework's wrapper. |
| `ignore.commands` | `[]` | Scheduled task commands, matched on the task's description if it sets one, otherwise its command string. |
| `ignore.http` | `[]` | Outgoing HTTP destinations, matched against the host, the host and path, and the full URL without its query string — `redis.internal`, `*.googleapis.com` and `http://ch:8123/*` all work. |
| `ignore.cache` | `[]` | Cache keys, matched on the full key before it is truncated for display. |
| `ignore.exceptions` | `NotFoundHttpException`, `ValidationException` | Exception classes never reported. Matched with `instanceof`, so subclasses are covered too. |

There are two reasons to add something here. The mild one is noise: a health check polled every
second, or a cache key touched on every request, costs an event each time and tells you nothing.

The serious one is **feedback**. Capturing work that exists *because* of telemetry means the capture
produces more work to capture. Prism excludes its own outbound batch automatically — it carries an
internal marker header and runs under a suppression scope — and an *inbound* request carrying that
same marker is treated the same way: capture is suppressed for its whole lifetime, so neither the
request nor the queries, cache reads and log lines it triggers are recorded. An application that
hosts the Prism workspace it reports to therefore never captures another client's ingest POST, on
any endpoint, with no configuration. What it cannot know is the work **your** application does on Prism's
behalf: the job that stores a batch, the counters it increments, the datastore it writes to. Those
are what the lists above are for. Two cases worth checking in any install:

- **A datastore reached over HTTP** rather than as a database connection (ClickHouse, OpenSearch, a
  cloud API) travels through Laravel's HTTP client, so every read and write becomes a span — and
  storing that span is another write. Add its host to `ignore.http`.
- **Cache is the chattiest signal.** One operation touching half a dozen counters emits a span per
  counter, so a prefix used for internal bookkeeping is worth an `ignore.cache` entry.

### Scrubbed keys

Keys whose values are redacted **at the source** before an event leaves the process — matched
case-insensitively against request input, headers, job payloads and query bindings. The list is
additive; extend it with any field specific to your app. Defaults:

`password`, `password_confirmation`, `secret`, `token`, `authorization`, `cookie`, `api_key`,
`access_token`, `refresh_token`, `credit_card`, `card_number`, `cvv`, `ssn`.

## Manual instrumentation

Automatic capture covers the common signals. Two static helpers on `Misakstvanu\Prism\Prism` let
you record things the listeners cannot see. Both are **safe no-ops when the client is inert**
(disabled or unconfigured), so you can call them unconditionally.

**Report a handled exception** — one your code caught but still wants in Prism (a swallowed
integration error, a best-effort background failure). It is recorded as `handled`, scrubbed and
buffered like any other:

```php
use Misakstvanu\Prism\Prism;

try {
    $gateway->charge($order);
} catch (GatewayException $e) {
    report_to_ledger($order);
    Prism::captureException($e);
}
```

**Instrument a block of code as a trace span** — arbitrary work the automatic capturers do not see,
such as an expensive computation or a third-party SDK call. The closure is timed and placed on the
trace waterfall; spans opened inside it (cache lookups, outgoing HTTP, nested `Prism::span()` calls)
nest beneath it automatically. The closure's return value is passed through:

```php
$report = Prism::span('generate monthly report', function () use ($account) {
    return $this->reporting->build($account);
});

// Tint it as a database/http/… span with the optional type argument:
$rows = Prism::span('warehouse rollup', fn () => $warehouse->rollup(), type: 'db');
```

## Octane

The package is Octane-aware. Per-request state (the event buffer, the trace context, the recursion
guard and the span stack) is reset on each `RequestReceived`, so one request's telemetry never
leaks into the next inside a long-lived worker. The `terminate` flush strategy fires under Octane's
per-request kernel terminate, and the HTTP transport reuses a keep-alive connection pool across
requests. No extra configuration is required.

## Horizon

Queue-depth and worker-count metrics are read through Horizon automatically when it is installed —
the package detects `Laravel\Horizon\Contracts\SupervisorRepository` at runtime, with no
compile-time dependency, so nothing breaks when Horizon is absent. Job capture works the same way
whether you run `queue:work`, `queue:listen` or Horizon.

For queue metrics to be reported, run `prism:check` (or any job) on a **worker** process — queue
polling is skipped entirely on web replicas, and an idle worker with no jobs has no flush to
piggyback on.

## Troubleshooting

Run the built-in diagnostic first — it names the exact problem:

```bash
php artisan prism:check
```

| Symptom | Likely cause |
| --- | --- |
| `prism:check` reports a missing token or app | `PRISM_TOKEN` / `PRISM_APP` not set, or config cached before they were — run `php artisan config:clear`. |
| A `401` from the endpoint | The token is wrong, revoked, or not an **ingest**-scope token. Mint a fresh ingest token. |
| A `429` from the endpoint | The workspace is over its monthly event quota. Connectivity and the token are fine — this is a billing state. Errors are still accepted. |
| Nothing appears in the console | Confirm `PRISM_ENABLED` is not `false`, the path/job is not on an ignore list, and — for a worker — that a job has actually run (the batch flushes at the end of each job). |
| Events flow but bodies/args are blank | Expected: sensitive keys are scrubbed at the source, and non-GET bodies are truncated to `request.max_body`. |
| No telemetry after a deploy | Config cache is stale — `php artisan config:clear` (or re-run `config:cache`). |

`PRISM_ENABLED=false` and an over-quota `429` are both **success** exits for `prism:check` — a
deliberate opt-out and an operational quota state are not wiring faults.

## Tests

The package carries its own [Pest](https://pestphp.com) suite, run through
[Testbench](https://packages.tools/testbench) with no host application present — which is what
proves it depends on nothing outside itself:

```bash
cd packages/prism
composer install
./vendor/bin/pest
```
