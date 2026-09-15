# Prism client

The client package for the [Prism](../../README.md) observability platform. Drop it into any
Laravel 12+ application to capture logs, requests, errors, traces, queries, jobs, scheduled tasks
and replica metrics and ship them, correlated by trace, to a Prism workspace.

Everything is captured automatically once two variables are set — no code changes, no manual
instrumentation, no daemon and no OS extension. The package depends on nothing in the Prism server
application, so it installs cleanly into any project.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- A Prism workspace and an **ingest** API token (Console → Settings → API tokens)

Two packages come along as dependencies and need no configuration of their own:
`laravel/nightwatch` (the capture engine) and `keepsuit/laravel-opentelemetry` (the span lane, which
gives the Traces waterfall its nesting). Neither needs a PECL extension, an agent daemon or an OTLP
collector — Prism consumes both in process and ships everything in its own batch.

## Quickstart

**1. Install**

```bash
composer require misakstvanu/prism
```

The service provider is auto-discovered — nothing to register.

**2. Set the variables**

```dotenv
PRISM_TOKEN=prism_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
PRISM_APP=your-app-slug
PRISM_ENDPOINT=https://your-workspace.prism.app/api/ingest
```

`PRISM_TOKEN` and `PRISM_APP` are the only required settings; `PRISM_ENDPOINT` defaults to the
hosted endpoint. Everything else has a working default.

**3. Verify**

```bash
php artisan prism:check
```

This validates the config and POSTs a test event to confirm the endpoint accepts your token.

```
  Capture engine
    engine ......... laravel/nightwatch v1.28.7
    ingest ......... in-process — records go straight into Prism's buffer
    agent .......... not required — no daemon, nothing opens the agent socket
    otel spans ..... configured
    browser ........ POST /_prism/browser (enabled, same-origin)
    token .......... present
    endpoint ....... https://your-workspace.prism.app/api/ingest
```

Read the `ingest` line when a workspace looks empty — it says whether telemetry is actually reaching
Prism.

> Nothing else is needed. You do **not** have to add a logging channel to `config/logging.php`;
> Prism attaches its handler for you.

## Required variables

| Variable | Purpose |
| --- | --- |
| `PRISM_TOKEN` | The ingest token from **Console → Settings → API tokens**. With no token the package silently no-ops and logs one warning at boot — it never throws. |
| `PRISM_APP` | The application slug this process reports as. |

## Configuration

Publish the config to override any default:

```bash
php artisan vendor:publish --tag=prism-config
```

The published `config/prism.php` documents every setting inline, and every value can be driven by an
environment variable, so most installs never publish the file. Prism also derives the capture
engine's and the span lane's configuration from its own — **one config file, not three**.

### Master switch and credentials

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | `PRISM_ENABLED` | `true` | Kill switch. `false` registers no listeners at all — the package is inert. |
| `token` | `PRISM_TOKEN` | `null` | Ingest token. *(required, except on a `local` host feeding a local hub)* |
| `app` | `PRISM_APP` | `null` | Application slug this process reports as. *(required)* |
| `endpoint` | `PRISM_ENDPOINT` | `https://prism.dev/api/ingest` | Where batches are POSTed. |

### Identity

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `environment` | `PRISM_ENVIRONMENT` | `APP_ENV` | Deployment tier tagged onto every event. |
| `replica` | `PRISM_REPLICA` | `gethostname()` | The name this process reports as. Override with the pod or container name. |

### Sampling

The share of executions captured, as a fraction of 1.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `sample.requests` | `PRISM_SAMPLE_REQUESTS` | `1.0` | Fraction of HTTP requests captured. |
| `sample.commands` | `PRISM_SAMPLE_COMMANDS` | `1.0` | Fraction of artisan commands captured. |
| `sample.schedules` | `PRISM_SAMPLE_SCHEDULES` | `1.0` | Fraction of scheduled-task runs captured. |

The unit is an **execution**, not a signal: a request that is sampled out contributes nothing at all
— not its own row, nor the queries, log lines, outgoing calls or spans it made. Lowering
`sample.requests` lowers span volume with it, and a job inherits the decision made about whatever
dispatched it, so a trace stays whole across the queue boundary.

**An error is never lost to sampling.** There is no `sample.exceptions`: an execution that was
sampled out is pulled back in the moment it throws.

Client-side sampling composes with the per-application rules in **Console → Settings → Sampling**;
the two multiply. A client at `0.5` under a workspace rule at `50%` keeps a quarter.

### Span lane

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `otel.enabled` | `PRISM_OTEL_ENABLED` | `true` | The span lane. `false` switches OpenTelemetry off outright. |
| `otel.slow_trace_ms` | `PRISM_SLOW_TRACE_MS` | `2000` | A trace at or beyond this is kept whole, however aggressive sampling is. |

The lane is what gives the Traces waterfall its parent/child nesting, and it is what carries a trace
between processes as W3C `traceparent` — an incoming request continues the caller's trace, an
outgoing HTTP call carries yours onward, and a dispatched job runs under the request that queued it.
Turning it off costs the nesting and cross-process propagation and nothing else.

To run the SDK on your own account, publish `config/opentelemetry.php`
(`php artisan vendor:publish --tag=laravel-opentelemetry-config`) — Prism then writes nothing there.
Add `Misakstvanu\Prism\Otel\PrismSpanProcessor::class` to `traces.processors` yourself if you still
want the waterfall.

### Batching and flush

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `batch.size` | `PRISM_BATCH_SIZE` | `1000` | Max events one execution may buffer; the excess is dropped and counted. |
| `batch.flush` | `PRISM_FLUSH_STRATEGY` | `terminate` | `terminate` ships after the response is returned. `sync` ships inline. `spool` parks the batch for one debounced drain job. |
| `batch.timeout` | `PRISM_FLUSH_TIMEOUT` | `2.0` | Ingest POST timeout in seconds. |
| `batch.queue_threshold` | `PRISM_QUEUE_THRESHOLD` | `100` | A flush larger than this is queued instead of sent inline. `0` keeps every flush inline. |
| `batch.queue` | `PRISM_FLUSH_QUEUE` | `null` | Queue the send/drain job runs on. |

#### The `spool` strategy

`spool` decouples capture from delivery: finished batches are written to the cache and one debounced
job drains them all, so a burst of requests costs one ingest POST instead of one each. Use it when
reaching the ingest host is expensive per request — and when the host *is* the ingest host, where an
inline POST into a single-worker dev server would deadlock.

It needs a cache store with atomic locks shared by every capturing process (redis, memcached,
database, file — not `array`) and a queue worker. Without either it falls back to `terminate`;
`prism:check` reports which, and how many batches are waiting.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `batch.spool.delay` | `PRISM_SPOOL_DELAY` | `5` | Seconds the drain waits — the debounce window. |
| `batch.spool.grace` | `PRISM_SPOOL_GRACE` | `60` | Extra seconds the pending-drain marker is held while waiting for a worker. |
| `batch.spool.ttl` | `PRISM_SPOOL_TTL` | `900` | Seconds a spooled batch survives in the cache. |
| `batch.spool.max_batches` | `PRISM_SPOOL_MAX_BATCHES` | `500` | Batches held at once; past the cap the oldest are dropped. `0` = unbounded. |
| `batch.spool.max_attempts` | `PRISM_SPOOL_MAX_ATTEMPTS` | `3` | Send attempts before a batch is discarded. |
| `batch.spool.store` | `PRISM_SPOOL_STORE` | `null` | Cache store backing the spool. |
| `batch.spool.lock_seconds` | `PRISM_SPOOL_LOCK_SECONDS` | `5` | How long the spool index lock is held. |
| `batch.spool.lock_wait` | `PRISM_SPOOL_LOCK_WAIT` | `3` | How long a flush waits for that lock before sending inline. |

### Per-domain limits

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `request.capture_headers` | `PRISM_CAPTURE_REQUEST_HEADERS` | `true` | Record the headers the request was addressed with, scrubbed by key. |
| `request.capture_body` | `PRISM_CAPTURE_REQUEST_BODY` | `true` | Record the body the client sent. |
| `request.capture_response` | `PRISM_CAPTURE_RESPONSE_BODY` | `true` | Record the body the application sent back. |
| `request.max_body` | `PRISM_MAX_BODY` | `65536` | Bytes kept per body and per header bag. A cut value says so; `0` disables the cap. |
| `request.body_content_types` | — | six types | Media types whose bodies are recorded: JSON (including `+json`), form-urlencoded, multipart, XML and `text/plain`. `text/html` is deliberately absent. The list replaces the default wholesale. |
| `job.capture_payload` | `PRISM_CAPTURE_JOB_PAYLOAD` | `true` | Record what a queued job was asked to do, scrubbed by key. |
| `job.max_payload` | `PRISM_MAX_JOB_PAYLOAD` | `65536` | Bytes kept per job payload. `0` disables the cap. |
| `mail.capture_recipients` | `PRISM_CAPTURE_MAIL_RECIPIENTS` | `true` | Record the To, Cc and Bcc addresses of every message (up to 50 per list). |
| `notification.capture_recipients` | `PRISM_CAPTURE_NOTIFICATION_RECIPIENTS` | `true` | Record what a notification was addressed to and where its channel routed. |
| `log.channels` | — | `[]` | Logging channels Prism attaches its handler to. Empty = the app's default channel or stack. |
| `log.level` | `PRISM_LOG_LEVEL` | `debug` | Minimum PSR-3 level captured. |
| `query.slow_threshold_ms` | `PRISM_SLOW_QUERY_MS` | `100` | A query at or above this is marked slow and kept with its whole trace. `0` disables the marker. |

Uploaded file contents are never recorded at any setting — a multipart request is stored as its
ordinary fields plus a `_prism_files` entry naming, sizing and typing each upload. Bodies and
payloads longer than their cap end in `… [truncated]`.

### Runtime metrics

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `metrics.system_interval` | `PRISM_METRICS_INTERVAL` | `60` | Seconds between CPU/memory/uptime samples. Runs on every replica. |
| `metrics.queue_interval` | `PRISM_QUEUE_POLL_INTERVAL` | `30` | Seconds between queue-depth/worker samples. Runs only on a process that works the queue. |
| `metrics.replica_type` | `PRISM_REPLICA_TYPE` | `null` | `web` or `worker`. `null` infers it from the process. |

A due sample ships in a batch of its own, so turning `sample.requests` down does not thin out the
Replicas screen or the `replica_cpu` alert with it.

### Browser telemetry

The endpoint the browser SDK
([`@misakstvanu/prism-browser`](https://www.npmjs.com/package/@misakstvanu/prism-browser)) posts to.
It is registered for you the moment this package is installed and enabled — there is no route to add
and no controller to write. The page reports to your own application, which enriches the report with
the signed-in user, the client IP and the environment, scrubs it with the same `scrub` list below,
and forwards it through the pipeline every other signal travels.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `browser.enabled` | `PRISM_BROWSER_ENABLED` | `true` | Whether the endpoint is registered at all. |
| `browser.path` | `PRISM_BROWSER_PATH` | `_prism/browser` | Where it is registered. Excluded from capture automatically, whatever you rename it to. |
| `browser.max_events` | — | `50` | Events one post may carry. |
| `browser.max_bytes` | — | `262144` | Bytes one post may carry. |
| `browser.rate_limit` | — | `120` | Posts per client IP per minute. `0` disables throttling. |
| `browser.origins` | — | `[]` | Origins allowed to post cross-origin. Empty is same-origin only. Matched with `Str::is`, so `https://*.example.com` works. |
| `browser.guard` | — | `null` | The auth guard the signed-in user is read from. |
| `browser.trust_client_user` | — | `false` | Whether a user id the **page** claims is believed when the request carries no authenticated session. |

A frontend on another origin needs one line: set `browser.origins` to the origins your pages are
served from and the route answers preflights and allows credentials for those origins alone. There
is no CORS middleware to add.

`php artisan prism:check` prints the endpoint's address and who may post to it.

### Ignore lists

Activity the client never captures. Every list but `ignore.exceptions` matches with `Str::is`
wildcards, so one pattern covers a family (`api/internal/*`, `App\Jobs\Internal\*`, `prism:*`).

| Key | Default | Meaning |
| --- | --- | --- |
| `ignore.paths` | Prism's own routes, `telescope*`, `horizon*`, `_debugbar*`, `nova*`, `up`, `health*` | Request URI patterns never captured. |
| `ignore.jobs` | `[]` | Queued job class names. Covers both the dispatch and the worker's run of it. |
| `ignore.commands` | `[]` | Artisan command names. A scheduled task runs as one of these. |
| `ignore.http` | `[]` | Outgoing HTTP destinations, matched against the host, the host and path, and the full URL without its query string. |
| `ignore.cache` | `[]` | Cache keys, matched on the full key. |
| `ignore.exceptions` | `NotFoundHttpException`, `ValidationException` | Exception classes never reported. Matched with `instanceof`. |

`paths`, `jobs` and `commands` silence a whole **execution**; `http`, `cache` and `exceptions`
silence one **signal** wherever it occurs.

Two entries are worth adding in almost any install: a **datastore reached over HTTP** (ClickHouse,
OpenSearch, a cloud API) belongs on `ignore.http`, because every read and write becomes a span; and
a **cache prefix used for internal bookkeeping** belongs on `ignore.cache`, cache being the
chattiest signal there is.

### Scrubbed keys

Keys whose values are redacted **at the source**, before an event leaves the process — matched
case-insensitively, at any depth. The list is additive; extend it with any field specific to your
app. Defaults:

`password`, `password_confirmation`, `secret`, `token`, `authorization`, `cookie`, `api_key`,
`access_token`, `refresh_token`, `credit_card`, `card_number`, `cvv`, `ssn`.

One list governs every signal. A key here is redacted in request and response bodies, headers and
query strings, outgoing call URLs, SQL statements, artisan command lines, cache keys, mail subjects,
exception messages, queued job payloads and recipient lists — the field, header or parameter stays,
so the shape is still legible. The one thing no key-based rule can reach is a value inside a job's
serialised command blob: name the **property** on the scrub list, or dispatch a reference rather
than the secret.

## Manual instrumentation

Automatic capture covers the common signals. Two static helpers record what no instrumentation can
see. Both are safe no-ops when the client is inert, so call them unconditionally.

**Report a handled exception:**

```php
use Misakstvanu\Prism\Prism;

try {
    $gateway->charge($order);
} catch (GatewayException $e) {
    report_to_ledger($order);
    Prism::captureException($e);
}
```

**Instrument a block of code as a trace span.** The closure is timed and placed on the waterfall as
a real OpenTelemetry span, so anything opened inside it nests beneath it. The return value is passed
through, and with the span lane off the closure still runs — it is simply not recorded:

```php
$report = Prism::span('generate monthly report', function () use ($account) {
    return $this->reporting->build($account);
});

// Tint it as a database/http/… span with the optional type argument:
$rows = Prism::span('warehouse rollup', fn () => $warehouse->rollup(), type: 'db');
```

## Octane and Horizon

Both work with no extra configuration. Under Octane, per-request state is reset on each
`RequestReceived` so one request's telemetry never leaks into the next, and the HTTP transport
reuses a keep-alive connection pool. Queue-depth and worker-count metrics are read through Horizon
automatically when it is installed, and job capture works the same whether you run `queue:work`,
`queue:listen` or Horizon.

Queue metrics are only reported from a **worker** process — polling is skipped on web replicas, and
an idle worker never reaches the end of an execution, which is where the interval is consulted.

## Troubleshooting

Run the built-in diagnostic first — it names the exact problem:

```bash
php artisan prism:check
```

| Symptom | Likely cause |
| --- | --- |
| `prism:check` reports a missing token or app | `PRISM_TOKEN` / `PRISM_APP` not set, or config cached before they were — run `php artisan config:clear`. |
| A `401` from the endpoint | The token is wrong, revoked, or not an **ingest**-scope token. |
| A `429` from the endpoint | The workspace is over its monthly event quota. Errors are still accepted. |
| Nothing appears in the console | Confirm `PRISM_ENABLED` is not `false`, the path or job is not on an ignore list, and — for a worker — that a job has actually run. |
| `prism:check` reports `records are NOT reaching Prism` | `PRISM_ENABLED` is false, or `PRISM_TOKEN` is blank at boot on a host that is not `local`. |
| A token-less local install gets `401`s | The hub is not running in *its* `local` environment. Token-less ingest is a local-to-local arrangement. |
| The browser SDK's posts come back `404` | The endpoint is not registered, or the SDK is posting elsewhere. `prism:check`'s `browser` line says which. |
| The browser SDK's posts are refused by the browser itself | The page's origin is not on `browser.origins`. |
| You went looking for an OTLP collector or a `nightwatch:agent` daemon | There isn't one of either. Spans and records never leave the process except in a Prism batch. |
| Nothing appears, and `prism:check` shows batches "waiting" | Under `spool`, check a worker is consuming `PRISM_FLUSH_QUEUE` and that the endpoint is reachable **from the worker**. |
| Events flow but a body or the header bag is blank | Scrubbed keys are expected. Otherwise: capture switched off, a media type not on `request.body_content_types`, a streamed or file response, or a request that carried none. |
| A body or payload ends in `… [truncated]` | It was longer than `request.max_body` / `job.max_payload`. Raise it, or set it to `0`. |
| No telemetry after a deploy | Config cache is stale — `php artisan config:clear`. |

`PRISM_ENABLED=false` and an over-quota `429` are both **success** exits for `prism:check` — a
deliberate opt-out and an operational quota state are not wiring faults.

## Tests

The package carries its own [Pest](https://pestphp.com) suite, run through
[Testbench](https://packages.tools/testbench) with no host application present:

```bash
cd packages/prism
composer install
./vendor/bin/pest
```
