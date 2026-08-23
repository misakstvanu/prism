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

Two packages come along as dependencies, and **neither needs an OS or PECL extension** — the whole
install is `composer require` and two variables:

- `laravel/nightwatch` (`^1.0`) is the capture engine. **Its agent daemon is not used and not
  required:** Prism installs its own transport over Nightwatch's ingest seam, so records never leave
  the process except in a Prism batch. There is no phar to run, no socket to open and no Laravel
  Nightwatch account to hold.
- `keepsuit/laravel-opentelemetry` (`^2.2`) is the span lane, the half that gives the Traces
  waterfall its parent/child nesting. It instruments the framework through events and middleware
  (unlike `open-telemetry/opentelemetry-auto-laravel`, which hard-requires the `ext-opentelemetry`
  C extension). **Nothing is exported as OTLP:** Prism consumes the spans in process, so there is
  no collector to run and no exporter connection is ever opened.

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

This validates the config and POSTs a single test event to confirm the endpoint accepts your token.
A green run means the next request, job or scheduled task your app runs is already reporting to the
console.

It also reports the capture engine, so the two questions Nightwatch's own documentation raises are
answered on the spot:

```
  Capture engine
    engine ......... laravel/nightwatch v1.28.7
    ingest ......... in-process — records go straight into Prism's buffer
    agent .......... not required — no daemon, nothing opens the agent socket
    otel spans ..... configured
    token .......... present
    endpoint ....... https://your-workspace.prism.app/api/ingest
```

`ingest` is the line to read when a workspace looks empty: it says whether Prism's own pipeline is
installed over the engine's, which happens only when `PRISM_ENABLED` is true **and** `PRISM_TOKEN`
is set. `otel spans` is the second half of the same question — it says whether the span processor
that gives the Traces waterfall its nesting is registered; `not configured` costs the nesting and
nothing else. Anything else there means records are being captured and handed to a socket ingest nothing
is listening on — which looks exactly like a quiet application from the console.

> Nothing else is needed. Requests, exceptions, logs, queries, commands, mail, notifications, jobs
> and scheduled tasks are the capture engine's, the trace waterfall is the span lane's, and replica
> metrics are Prism's own — all of it wired by the provider at boot. In particular you do **not**
> have to add the engine's `nightwatch` channel to `config/logging.php`: Prism attaches its handler
> to the channels `log.channels` names for you.

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

**One config file, three subjects.** Prism owns the capture engine's and the span lane's
configuration as well as its own: `nightwatch.*` and `opentelemetry.*` are derived from the
`prism.*` keys below at boot, so there is nothing else to publish and nothing else to keep in step.
Publish either of those files yourself and Prism steps back from it entirely — see
[Capture engine](#capture-engine-laravelnightwatch) and
[Span lane](#span-lane-keepsuitlaravel-opentelemetry) for what that costs.

> The tables in this section are a **hand-maintained mirror** of `config/prism.php`. Nothing
> generates one from the other, so a change to the config file that does not edit this README in the
> same commit leaves the two disagreeing — with the README being what an installer reads.

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

### Capture engine (`laravel/nightwatch`)

Which signals are captured is Nightwatch's vocabulary — this package declares no `capture` toggles
of its own. Prism derives Nightwatch's config from its own at register time, so **you publish one
config file, not two**:

| Nightwatch key | Derived from | Why |
| --- | --- | --- |
| `nightwatch.enabled` | `prism.enabled` | One master switch. Nightwatch defaults itself to enabled, so without this `PRISM_ENABLED=false` would leave 38 framework hooks registered. |
| `nightwatch.token` | `prism.token` | The two should name the same credential. |
| `nightwatch.server` | `prism.replica` | A record's own `server` field and the batch envelope's `replica` field must agree, or one process reports under two names. Re-derived once more after every provider has booted, so a host that names its process from its own `boot()` still gets one name on both. |
| `nightwatch.sampling.requests` | `prism.sample.requests` | See [Sampling](#sampling). |
| `nightwatch.sampling.commands` | `prism.sample.commands` | " |
| `nightwatch.sampling.scheduled_tasks` | `prism.sample.schedules` | " |
| `nightwatch.sampling.exceptions` | *pinned at `1.0`* | An error is never sampled out. This is the one derived key with **no** environment escape hatch. |
| `nightwatch.capture_request_payload` | `prism.request.capture_payload` | See [Scrubbed keys](#scrubbed-keys). |
| `nightwatch.redact_payload_fields` | `prism.scrub` | " (upstream's own defaults survive underneath) |
| `nightwatch.redact_headers` | `prism.scrub` | " |

`nightwatch.ingest.uri` is left at its default and never used — nothing opens that socket.

**Taking a key back** is deliberately not "set it in the published config and Prism will notice".
Nightwatch registers before Prism in every real install and merges its own defaults first, so by the
time Prism looks, *every* key exists and "is it unset" answers nothing. Ownership is decided by
evidence that survives that ordering, and there are two kinds:

- **Publish `config/nightwatch.php`** (`php artisan vendor:publish --tag=nightwatch-config`) and
  Prism writes nothing at all — the whole file is yours, including the keys above.
- **Set the matching `NIGHTWATCH_*` variable** and that one key is yours, the rest stay derived:
  `NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN`, `NIGHTWATCH_SERVER`, `NIGHTWATCH_REQUEST_SAMPLE_RATE`,
  `NIGHTWATCH_COMMAND_SAMPLE_RATE`, `NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE`,
  `NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD`, `NIGHTWATCH_REDACT_PAYLOAD_FIELDS`,
  `NIGHTWATCH_REDACT_HEADERS`. `nightwatch.sampling.exceptions` has no variable on purpose.

### Sampling

The share of executions captured, as a fraction of 1. `1.0` keeps everything (the default), `0.0`
keeps nothing.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `sample.requests` | `PRISM_SAMPLE_REQUESTS` | `1.0` | Fraction of HTTP requests captured. |
| `sample.commands` | `PRISM_SAMPLE_COMMANDS` | `1.0` | Fraction of artisan commands captured. |
| `sample.schedules` | `PRISM_SAMPLE_SCHEDULES` | `1.0` | Fraction of scheduled-task runs captured. |

**The unit is an execution, not a signal — so lowering the request rate lowers span volume with
it.** A request that is sampled out contributes *nothing at all*: not its own row, and not the
queries, cache reads, log lines, outgoing calls or spans it made along the way. There is no separate
span, query or log rate to turn down, and no half-captured trace to reassemble at the far end. Halve
`PRISM_SAMPLE_REQUESTS` and you halve everything a request produces.

**A job inherits the decision made about whatever dispatched it.** There is no job rate for the same
reason there is no query rate: a worker's run of a job carries the dispatching execution's verdict in
Laravel's `Context`, into the payload and out the other side. So a request kept by `sample.requests`
has the jobs it queued kept with it, and a request that was dropped drops them — the trace stays
whole across the queue boundary rather than half-recorded.

**An error is never lost to sampling.** There is deliberately no `sample.exceptions`: an execution
that was sampled out is pulled back into the sample the moment it throws, so the fault — and the
context buffered before it — ships anyway. The Prism server pins the exception domain to `1.0` on
ingest as well, so a team is never blind to breakage because of a rate someone tuned.

Sampling composes with the **server-side** rules configured per application in **Console →
Settings → Sampling**, which decide what a workspace keeps once a batch arrives. The two multiply
and neither knows about the other: a client at `0.5` under a workspace rule at `50%` keeps a
quarter. Client-side sampling is the one that saves bandwidth; server-side is the one that saves
storage and quota.

A rate this package cannot read at all is treated as `1.0`, never `0.0` — capturing more than
intended shows up on a bill, while capturing nothing shows up as a console that has quietly been
empty for a week.

> Note the key is `sample`, not `sampling`. On a host that *is* a Prism workspace, `prism.sampling`
> is the server's own per-workspace block and the two must not collide: a package/host config merge
> is shallow, so one key cannot mean two things.

### Span lane (`keepsuit/laravel-opentelemetry`)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `otel.enabled` | `PRISM_OTEL_ENABLED` | `true` | The span lane. `false` switches the OpenTelemetry SDK off outright — no instrumentation is registered and no span is produced. |
| `otel.slow_trace_ms` | `PRISM_SLOW_TRACE_MS` | `2000` | A trace at or beyond this many milliseconds is kept **whole** by tail sampling, however aggressive sampling is. An unreadable value falls back to `2000`, never to zero. |

The lane exists for one thing the capture engine cannot express: **nesting**. Nightwatch's cache,
query and outgoing-request sensors fire on *completion* only, so their records are flat siblings
sharing a trace id. An OpenTelemetry span carries a parent span id by construction, which is what
the Traces waterfall is drawn from.

It is also **where the trace id comes from**. Two engines mint one: the capture engine a UUID per
execution, the SDK a 32-hex W3C id per trace — and every Prism screen correlates a request with the
spans, logs, queries, exceptions and jobs it produced by that single column. OpenTelemetry wins,
because its id is the one that travels. Every record is re-keyed onto it as it passes through Prism's
ingest, so nothing you do is needed — and a signal recorded with no OpenTelemetry trace to speak of
keeps the id it arrived with, so nothing is ever left unkeyed.

**And it is what carries a trace between processes.** `propagators` is left at `tracecontext`, so a
trace crosses three boundaries as the W3C standard every APM and every language SDK speaks:

- an **incoming `traceparent`** continues the caller's trace, so a request arriving from another
  service is the same trace rather than an adjacent one;
- an **outgoing call** through Laravel's HTTP client has `traceparent` injected, so the service you
  call continues yours — whatever it is written in;
- a **dispatched job** carries `traceparent` in its payload and runs under a CONSUMER span parented
  to the PRODUCER span that queued it, so a worker's records land under the request that queued them.

Nothing bespoke rides along: `traceparent` is spoken by every APM and every language SDK, so a
Laravel app calling a Go service produces one trace rather than two. A trace id is therefore a
**32-character lowercase hex string**, not a UUID — worth knowing if you match or store one of your
own. If you propagate the header by hand from a service Prism does not instrument, its format is
`00-<32 hex trace id>-<16 hex span id>-<2 hex flags>`.

**With the lane on, it is also the producer of two signals the capture engine watches as well.**
Both engines see a query and an outgoing HTTP call, so leaving both alone would store one query as a
`queries` row *and* a `db` span while storing one outgoing call as two `http` spans. The span wins,
because it is the one that knows its parent — so the engine's own `query` and `outgoing-request`
records are dropped as they pass through Prism's ingest, and the `queries` row is built from the db
span instead (`db.query.text`, `db.system.name`, the span's duration, and Prism's own `slow` marker
from `query.slow_threshold_ms`). Three consequences worth knowing:

- **A record is dropped only when the span really replaced it.** A console command opens no trace
  (the console instrumentation is off — the capture engine owns commands), and neither does a query
  run before the request span opens or a process where you published `config/opentelemetry.php` and
  did not add Prism's processor. In every one of those the engine's record is kept, because it is the
  only producer there is.
- **The SQL is the span's `db.query.text`, which OpenTelemetry truncates at 500 characters**, and
  `connection` is the driver (`pgsql`, `mysql`) rather than Laravel's connection name — no span
  attribute names the connection. A statement upstream's query instrumentation skips (it records
  `SELECT`, `INSERT`, `UPDATE` and `DELETE` only, so not `begin`, `commit` or DDL) has no span and so
  no row. Set `PRISM_OTEL_ENABLED=false` if you would rather have the engine's fuller records without
  the nesting.
- **A Redis command is a `db` span and never a `queries` row.** Both instrumentations name the system
  the same way, which is why one lane rule covers both — but Prism's query signal is Laravel's
  `QueryExecuted`, which Redis does not raise.

`prism.scrub` applies to the span lane too: the statement and the outgoing URL meet the same list the
engine's records do, so a scrub entry cannot cover one producer out of two.

**A slow or failing request's trace is kept whole.** The lane runs with OpenTelemetry *tail*
sampling on, which defers the keep-or-drop decision until the trace has finished and then applies two
rules: any span whose status is `ERROR` keeps the whole trace, and any trace at or beyond
`otel.slow_trace_ms` (`PRISM_SLOW_TRACE_MS`, 2000 by default) keeps the whole trace. This is the
client-side half of a rule the Prism server already enforces on what it receives — belt and braces
rather than a duplicate: the server's half keeps a slow trace once it has arrived, this one keeps it
from being dropped before it is sent at all. Answer any of upstream's own
`OTEL_TRACES_TAIL_SAMPLING_*` variables and your answer is kept, and a tail-sampling rule of your own
is merged beside Prism's rather than replaced.

Because tail sampling holds a trace's spans until its decision — `decision_wait` is 5000ms by
default, longer than most requests live — Prism force-flushes the tracer **immediately before**
draining its own buffer, so whatever is released lands in the batch its own execution ships rather
than in whichever execution flushes next. Nothing about that needs configuring, and under the `spool`
flush strategy a span released after its request has ended is carried by the next drain.

Two things follow that are worth knowing. Turning tail sampling on makes OpenTelemetry's *head*
sampler always-on and applies whatever you configured (`OTEL_TRACES_SAMPLER_TYPE=traceidratio`, say)
at the tail instead — after the errors and slow-trace rules have had their say, which is the point.
And **Prism's own span processor sits beside that decision rather than behind it**, so what Prism
ships is not thinned by the OpenTelemetry sampler at all: the knob for span volume is
`sample.requests`, because a sampled-out execution discards its whole buffer, spans included.

Turning it off costs the nesting, the shared id and cross-process propagation, and nothing else —
requests, errors, logs, queries, jobs, commands, mail, notifications and replica metrics all keep
flowing, correlated within one execution by the capture engine's own trace id, the Traces screen
falls back to the flat spans its records produce, and the `queries` rows go back to being the
engine's. What does not survive is a trace that spans services or a queue: there is no `traceparent`
to carry, so each process traces itself. `PRISM_ENABLED=false` switches it off along
with everything else.

As with the capture engine, Prism derives the whole OpenTelemetry configuration from its own, so
**you publish one config file, not three**:

| OpenTelemetry key | Derived from | Why |
| --- | --- | --- |
| `opentelemetry.disabled` | `prism.enabled` + `prism.otel.enabled` | One master switch. Also written into `OTEL_SDK_DISABLED`, because that variable — not the config key — is what `Sdk::isDisabled()` reads, and upstream stamped it before Prism could decide. |
| `opentelemetry.traces.exporter` | *pinned at `null`* | Spans are consumed in process and ship in Prism's own batch. `null` is upstream's Noop exporter, so no PSR-18 client is discovered and no transport is ever built. |
| `opentelemetry.metrics.exporter` | *pinned at `null`* | " |
| `opentelemetry.logs.exporter` | *pinned at `null`* | " — logs are the capture engine's signal. |
| `opentelemetry.service_name` | `prism.app` | A span's resource has to name the same application the batch envelope does. |
| `opentelemetry.service_instance_id` | `prism.replica` | " (the replica) |
| `opentelemetry.resource_attributes` | `prism.environment` → `deployment.environment` | " (the tier). Merged, so your own resource attributes survive alongside it. |
| `opentelemetry.worker_mode.flush_after_each_iteration` | *pinned at `true`* | Upstream defaults it to `false`, which in a queue worker or an Octane process ships one job's spans inside the *next* job's batch — landing them under the wrong execution, silently. |
| `opentelemetry.traces.sampler.tail_sampling` | *enabled, with `ErrorsRule` + `SlowTraceRule` from `prism.otel.slow_trace_ms`* | A slow or failing execution's trace is kept whole however aggressive sampling is. `decision_wait` stays at upstream's own default, because Prism force-flushes the tracer at the end of every execution. |
| `opentelemetry.traces.processors` | *Prism's own span processor is appended* | The whole span lane, in one line of config: `Misakstvanu\Prism\Otel\PrismSpanProcessor` turns each finished span into a Prism `span` event. Appended to upstream's own slot rather than replacing the tracer, so keepsuit keeps its resource, sampler and propagators — and your own processors keep working beside it. |
| `opentelemetry.propagators` | *left at upstream's `tracecontext`* | A trace crosses a service or queue boundary as W3C `traceparent`. Prism writes nothing here; `OTEL_PROPAGATORS` is yours. |
| `opentelemetry.instrumentation.*` | *see below* | Five on, six off. |

**On:** `HttpServerInstrumentation`, `HttpClientInstrumentation`, `QueryInstrumentation`,
`QueueInstrumentation`, `RedisInstrumentation` — the lanes the waterfall draws.

**Off:** `CacheInstrumentation` (it only calls `addEvent()` on the active span, and a span *event*
is not a span — Prism has nowhere to store one, so the cache lane comes from the capture engine's
own record instead), `ConsoleInstrumentation` (the engine's command sensor already owns commands),
and `EventInstrumentation` / `ViewInstrumentation` / `LivewireInstrumentation` /
`ScoutInstrumentation`, which have no Prism counterpart at all.

Most of those keys have the environment variable upstream documents for them
(`OTEL_TRACES_EXPORTER`, `OTEL_SERVICE_INSTANCE_ID`, `OTEL_INSTRUMENTATION_CACHE`, …) and answering
one keeps your answer. **`OTEL_SERVICE_NAME` and `OTEL_SDK_DISABLED` are the two exceptions**, and
not for tidiness: the OpenTelemetry package writes both variables itself, during its own
`register()`, so by the time Prism looks they are always set and their presence says nothing about
what you asked for. The SDK switch still respects a `true` you resolved before Prism touched it; the
service name does not.

The way to take the whole thing over is to publish `config/opentelemetry.php`
(`php artisan vendor:publish --tag=laravel-opentelemetry-config`) — Prism writes **nothing at all**
when that file exists, on the assumption that a host with one is running the SDK on its own account.
Note that this also means Prism's span processor is not registered for you: add
`Misakstvanu\Prism\Otel\PrismSpanProcessor::class` to `traces.processors` yourself if you want the
waterfall as well as your own pipeline. `php artisan prism:check` reports that state as
`otel spans ... not configured — … is installed but not registered`.

### Batching and flush

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `batch.size` | `PRISM_BATCH_SIZE` | `1000` | Max events one request may buffer; the excess is dropped and counted, bounding memory. |
| `batch.flush` | `PRISM_FLUSH_STRATEGY` | `terminate` | `terminate` ships after the response is returned (off the critical path — the default). `sync` ships inline before the process ends (useful for one-off scripts and tests). `spool` parks the batch in the cache for one debounced drain job (see below). |
| `batch.timeout` | `PRISM_FLUSH_TIMEOUT` | `2.0` | Ingest POST timeout in seconds, kept short so a slow endpoint never stalls the process. |
| `batch.queue_threshold` | `PRISM_QUEUE_THRESHOLD` | `100` | A flush larger than this is handed to a queued job instead of sent inline. `0` keeps every flush inline. |
| `batch.queue` | `PRISM_FLUSH_QUEUE` | `null` | Queue the send/drain job is dispatched onto; `null` = default queue. |

#### The `spool` strategy

Under `terminate` and `sync` the process that captured the telemetry is also the one that ships it —
a POST per request. `spool` decouples the two: a finished batch is written to the cache and a single
debounced job drains everything spooled since the last drain, so a burst of requests costs one ingest
POST instead of one each. It is the right choice when reaching the ingest host is expensive per
request, and the necessary one when the host *is* the ingest host — an inline POST into a
single-worker dev server deadlocks against the request making it.

Only finished work is ever spooled (a flush runs at the end of a request, job or scheduled task), and
a cache lock guards the hand-off, so a drain can never pick up a half-written batch. A batch that
fails to send goes back on the spool and a fresh drain is armed, so an outage costs a delay rather
than the telemetry.

It needs two things: a **cache store with atomic locks** shared by every process that captures (redis,
memcached, database, file — not `array` outside tests), and a **queue worker** (the drain never runs
on the `sync` driver). Without either, the flush quietly falls back to `terminate` rather than piling
up telemetry it cannot ship — `php artisan prism:check` reports which, and how many batches are
waiting.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `batch.spool.delay` | `PRISM_SPOOL_DELAY` | `5` | Seconds the drain waits before running — the debounce window everything spooled during it ships in. |
| `batch.spool.grace` | `PRISM_SPOOL_GRACE` | `60` | Extra seconds the "a drain is pending" marker is held, covering the wait for a free worker. Once it lapses the next flush arms a replacement. |
| `batch.spool.ttl` | `PRISM_SPOOL_TTL` | `900` | Seconds a spooled batch survives in the cache. |
| `batch.spool.max_batches` | `PRISM_SPOOL_MAX_BATCHES` | `500` | Batches held at once; past the cap the oldest are dropped. `0` = unbounded. |
| `batch.spool.max_attempts` | `PRISM_SPOOL_MAX_ATTEMPTS` | `3` | Send attempts a batch gets before it is discarded, so a wrong endpoint cannot cycle forever. |
| `batch.spool.store` | `PRISM_SPOOL_STORE` | `null` | Cache store backing the spool; `null` = default store. |
| `batch.spool.lock_seconds` | `PRISM_SPOOL_LOCK_SECONDS` | `5` | How long the spool index lock is held. |
| `batch.spool.lock_wait` | `PRISM_SPOOL_LOCK_WAIT` | `3` | How long a flush waits for that lock before giving up and sending inline. |

### Per-domain limits

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `request.capture_payload` | `PRISM_CAPTURE_REQUEST_PAYLOAD` | `false` | Whether a request's body is recorded at all. Off, a body is captured only for a request that faulted. |
| `log.channels` | — | `[]` | Logging channels Prism attaches the engine's handler to. Empty = the app's default channel/stack. You do not have to add `nightwatch` to `config/logging.php` yourself. |
| `log.level` | `PRISM_LOG_LEVEL` | `debug` | Minimum PSR-3 level captured. |
| `query.slow_threshold_ms` | `PRISM_SLOW_QUERY_MS` | `100` | A query at/above this is marked slow, and is then kept (with its whole trace) whatever the **server's** per-workspace rules say. It does not survive the client-side rates above — those drop the execution before anything is sent. `0` disables the marker. |

### Runtime metrics

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `metrics.system_interval` | `PRISM_METRICS_INTERVAL` | `60` | Seconds between CPU/memory/uptime samples. Runs on every replica. |
| `metrics.queue_interval` | `PRISM_QUEUE_POLL_INTERVAL` | `30` | Seconds between queue-depth/worker samples. Runs only on a process that works the queue. |
| `metrics.replica_type` | `PRISM_REPLICA_TYPE` | `null` | `web` or `worker`. `null` infers it from the process. |

Both samples are taken when an execution ends — a request, a command or a job — and the interval is
what keeps that from meaning a sample every time. **A due sample ships in a batch of its own**, and
deliberately so: the capture engine discards an execution's whole batch when [the sampling
rates](#sampling) reject it, and a replica's health has nothing to do with whether one request was
interesting. Turning `sample.requests` down therefore does not thin out the Replicas screen or the
`replica_cpu` alert with it.

Runtime metrics are also the one signal the capture engine has no sensor for, so Prism keeps
collecting them itself; everything else on this page is the engine's.

### Ignore lists

Activity the client never captures. Every list but `ignore.exceptions` matches with `Str::is`
wildcards, so one pattern covers a family (`api/internal/*`, `App\Jobs\Internal\*`, `prism:*`); a
pattern without a `*` matches exactly.

| Key | Default | Meaning |
| --- | --- | --- |
| `ignore.paths` | Prism's own routes, `telescope*`, `horizon*`, `_debugbar*`, `nova*`, `up`, `health*` | Request URI patterns never captured. |
| `ignore.jobs` | `[]` | Queued job class names, as resolved for display — for a queued broadcast that is the event class, not the framework's wrapper. Covers both the dispatch and the worker's run of it. |
| `ignore.commands` | `[]` | Artisan command names — `prism:*`, `reports:build`. A scheduled task runs as one of these, so a schedule silenced here is silenced wherever it is started from. |
| `ignore.http` | `[]` | Outgoing HTTP destinations, matched against the host, the host and path, and the full URL without its query string — `redis.internal`, `*.googleapis.com` and `http://ch:8123/*` all work. |
| `ignore.cache` | `[]` | Cache keys, matched on the full key before it is truncated for display. |
| `ignore.exceptions` | `NotFoundHttpException`, `ValidationException` | Exception classes never reported. Matched with `instanceof`, so subclasses are covered too. (Laravel's own "don't report" list already covers the two defaults; the entries earn their keep for anything you add.) |

`paths`, `jobs` and `commands` silence a whole **execution**: a request, a job's run or a command
that matches contributes nothing at all — not its own row and not the queries, cache reads, log
lines, spans or outgoing calls it made. `http`, `cache` and `exceptions` silence one **signal**
wherever it occurs, so an ignored cache key is invisible on an otherwise fully captured request.

The one signal that still leaves an ignored execution is the replica heartbeat
(`replica_metric`, see [Runtime metrics](#runtime-metrics)). It is a fixed-cadence sample of the
process rather than a record of anything the execution did — the figure the Replicas screen and the
`replica_cpu` alert read — and it ships on its own so that a replica's health does not depend on
whether the request that happened to be running was one you wanted to see.

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
case-insensitively. The list is additive; extend it with any field specific to your app. Defaults:

`password`, `password_confirmation`, `secret`, `token`, `authorization`, `cookie`, `api_key`,
`access_token`, `refresh_token`, `credit_card`, `card_number`, `cvv`, `ssn`.

One list governs every signal. A key here is redacted wherever it appears:

| Where | What happens |
| --- | --- |
| Request body, headers, query string | The value is replaced; the field, header and parameter stay, so the shape is still legible. |
| Outgoing call query string | Same, so a credential passed to a third-party API never reaches the console. |
| SQL statement | The value half of `password = 'x'` is replaced. A `?` or `:name` placeholder is left alone. |
| Artisan command line | `--password=x` keeps the option and loses the value. |
| Cache key | `token:abc` becomes `token:[REDACTED]`; a key that is only a name (`api_key`) is untouched — the name of an entry is not a secret. |
| Mail subject, exception message | The value half of any `name = value` pair in the text. |

A **queued job's payload is never captured at all**, so a secret dispatched inside a job cannot
reach the console however this list is written: nothing is recorded but the job's name, id, queue,
connection and outcome.

Request **bodies** are held back twice over. The capture engine records a payload only for a request
whose response was a **500**, and `request.capture_payload` (`PRISM_CAPTURE_REQUEST_PAYLOAD`)
defaults to `false`, which means no body is recorded at all. Set `PRISM_CAPTURE_REQUEST_PAYLOAD=true`
where the debugging is worth more than the exposure; the scrub list above still applies to whatever
is captured, and a request that *succeeded* has no body in the console at either setting.

## Manual instrumentation

Automatic capture covers the common signals. Two static helpers on `Misakstvanu\Prism\Prism` let
you record things no instrumentation can see. Both are **safe no-ops when the client is inert**
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

**Instrument a block of code as a trace span** — arbitrary work no instrumentation sees, such as an
expensive computation or a third-party SDK call. The closure is timed and placed on the trace
waterfall as a real OpenTelemetry span, so anything opened inside it (a query, an outgoing call, a
nested `Prism::span()`) nests beneath it by construction and the trace it joins is the one that
crosses service and queue boundaries. The closure's return value is passed through, and with the
span lane off (`PRISM_OTEL_ENABLED=false`) the closure still runs — it is simply not recorded:

```php
$report = Prism::span('generate monthly report', function () use ($account) {
    return $this->reporting->build($account);
});

// Tint it as a database/http/… span with the optional type argument:
$rows = Prism::span('warehouse rollup', fn () => $warehouse->rollup(), type: 'db');
```

## Octane

The package is Octane-aware. Per-request state (the event buffer, the trace context and the recursion
guard) is reset on each `RequestReceived`, so one request's telemetry never
leaks into the next inside a long-lived worker. The `terminate` flush strategy fires under Octane's
per-request kernel terminate, and the HTTP transport reuses a keep-alive connection pool across
requests. No extra configuration is required.

## Horizon

Queue-depth and worker-count metrics are read through Horizon automatically when it is installed —
the package detects `Laravel\Horizon\Contracts\SupervisorRepository` at runtime, with no
compile-time dependency, so nothing breaks when Horizon is absent. Job capture works the same way
whether you run `queue:work`, `queue:listen` or Horizon.

For queue metrics to be reported, run `prism:check` (or any job) on a **worker** process — queue
polling is skipped entirely on web replicas, and an idle worker with no jobs never reaches the end
of an execution, which is where the interval is consulted.

## Capture cost

Two instrumentation engines listen on every request, so what capture costs one is measured rather
than assumed:

```bash
php artisan prism:bench:capture
```

It times the same synthetic request under three configurations — nothing capturing, the capture
engine alone, and the capture engine plus the span lane — and reports what each one *adds* to the
request. Each configuration runs in a process of its own, because which engines listen is decided
during `register()`: three configurations in one process would be three names for one
configuration. The whole sweep repeats (`--rounds`, default 3) round-robin and each row reports its
median round, because ambient load moves the same measurement further than the difference being
looked for.

**The command fails on one thing only: any sign that a span left the process as OTLP** (a protobuf
class loaded anywhere in a child). That is the claim a reader cannot check for themselves — there is
no collector to run, nothing is exported — so it is the one thing gated. The cost figures are
reported, not thresholded: what a captured operation costs depends on the workload, so the
per-signal attribution below is the number to carry away and a limit on the headline would only ever
describe the box that ran it.

The runtime metrics collectors are outside all of this — they sample on an interval rather than per
request, and cost the two capturing configurations ~40 µs a request against the array store, inside
the noise of everything below.

### The recorded run

PHP 8.4.23 with OPcache, Laravel 13.21, 11th-gen i7-1165G7 (8 threads), inside the project's
`prism-be` container while the rest of the stack was running — an ordinary development box under
ordinary load, not a quiet benchmarking rig. 250 requests × 5 rounds, each request running 8
queries, 8 cache read/writes and 1 log line:

| configuration | per request | added | added peak mem | events/req |
| --- | --- | --- | --- | --- |
| capture off | 1.56 ms | — | — | 0.0 |
| Nightwatch only | 3.59 ms | +2.03 ms | +862 kB | 26.0 |
| Nightwatch + OTel spans | 6.90 ms | +5.34 ms | +2909 kB | 35.0 |

Repeat sweeps on the same box put the two added figures at roughly +2.0–2.4 ms and +4.4–5.6 ms —
quote the shape, not the second digit. What does not move is the ordering.

Varying the workload one signal at a time (200 requests × 3 rounds) says where the cost is, and it
is not a fixed per-request overhead:

| workload | Nightwatch | Nightwatch + OTel |
| --- | --- | --- |
| nothing (the request alone) | +0.58 ms | +0.81 ms |
| 8 queries | +0.99 ms | +2.60 ms |
| 8 cache read/writes (16 events) | +1.84 ms | +2.41 ms |

Subtracting the empty request, a query costs the capture engine ~51 µs and the span lane a further
~200 µs. The span lane is the bulk of the default install's cost, and what it is buying is visible
in the event counts: a query under the span lane produces **two** rows — the `db` bar in the
waterfall and the `queries` row derived from it — where a flat capture produces one event and no
nesting at all. Nesting is not free.

The **per-signal** figures are the transferable ones. The headline workload is deliberately
signal-dense — 8 queries and 16 cache events inside a request whose own work is 1.5 ms — so it
answers "what does one captured operation cost", not "what fraction of a request will this be".
Multiply the per-signal numbers by what your own requests actually do. Peak memory moves the same
way and for the same reason: the span lane holds a span per operation until the trace ends, which is
~2.9 MB on this workload against ~0.5 MB without it.

Three findings worth keeping:

- **Rejecting the superseded records upstream does not recover it.** The engine takes its
  `debug_backtrace(limit: 21)` *before* any `reject*` callback runs, so `Nightwatch::rejectQueries()`
  cannot avoid the expensive part. `nightwatch.filtering.ignore_queries` can — it is checked first —
  and measured at ~90 µs per query in a span-lane install, against a per-request query counter that
  would then read zero. It is a partial recovery of an inherent cost, not a fix.
- **The replica-metrics interval throttle costs a cache round trip per request** in any process that
  did not win the interval. It is not capture at all, but it measured 1.19 ms per request against a
  Redis store — larger than everything above — which is why the benchmark runs its children on the
  array store and says so.
- **Peak memory is bounded by the buffer, not by the request.** `PRISM_BATCH_SIZE` is what stops a
  request that issues a hundred queries holding a hundred spans: at capacity the buffer either ships
  early or drops and counts, and the benchmark reports both numbers.

The lever that is already built for this is `PRISM_SAMPLE_REQUESTS`. Sampling is per **execution**:
a request the sampler rejects is discarded whole, spans included, so it never pays the per-signal
costs above. Halving the rate halves the average cost of a request; there is no per-signal rate to
tune and no half-captured trace to reassemble. Turning individual OpenTelemetry instrumentations off
(`OTEL_INSTRUMENTATION_QUERY`, `OTEL_INSTRUMENTATION_HTTP_CLIENT`, …) is the other dial, and it costs
that lane its nesting rather than its volume — see
[Span lane](#span-lane-keepsuitlaravel-opentelemetry).

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
| `prism:check` reports `records are NOT reaching Prism` | The capture engine is running but Prism's ingest was never installed over it — which happens when `PRISM_ENABLED` is false or `PRISM_TOKEN` is blank at boot. Fix those and the `ingest` line reads `in-process`. |
| You went looking for an OTLP collector to point at | There isn't one. Spans never leave the process as OTLP — all three OpenTelemetry exporters are pinned to `null` and Prism ships the spans in its own batch. |
| You went looking for a `nightwatch:agent` daemon | There isn't one. `prism:check` says `agent: not required` for exactly this reason — Prism replaces the engine's transport, so nothing is transmitted over a socket and no daemon is installed, started or monitored. |
| Nothing appears, and `prism:check` shows batches "waiting" | Under `spool`, batches are landing but nothing drains them: check a worker is consuming the queue named by `PRISM_FLUSH_QUEUE` (default queue), and that the endpoint is reachable **from the worker**, which is where the send now happens. |
| Events flow but bodies/args are blank | Expected: sensitive keys are scrubbed at the source, and a body is captured only when `request.capture_payload` is on **and** the response was a 500 — both conditions, not either. A queued job's payload is never captured at all. |
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
