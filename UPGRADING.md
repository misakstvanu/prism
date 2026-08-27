# Upgrading

Breaking changes, newest first. Each entry says what was removed, what replaced it, and what — if
anything — you have to do.

## 2.0 — the capture engine replacement

Released as `misakstvanu/prism` **2.0.0**, which is the version doing the work here: a caret
constraint (`^1.0`) does not cross a major, so a `composer update` leaves a 1.x install exactly
where it is and the upgrade is a deliberate `composer require misakstvanu/prism:^2.0`. The five
entries below are what that opts you into. The two new dependencies (`laravel/nightwatch`,
`keepsuit/laravel-opentelemetry`) come along automatically and **neither needs an OS or PECL
extension**: there is no daemon to run, no collector to stand up and no second account to hold.

### The capture listeners are gone; both engines capture in their own right

**What changed.** Prism used to watch the framework itself — nine listeners, a request middleware, a
Monolog handler and a span stack of its own. All of it is deleted. `laravel/nightwatch` is the
capture engine and `keepsuit/laravel-opentelemetry` is the span lane; Prism owns the buffer, the
envelope, the transport and the spooling those two feed, and nothing else.

**What you have to do.** For an ordinary install, nothing: `PRISM_TOKEN` and `PRISM_APP` still get
you every signal, and you still do **not** have to add the engine's `nightwatch` channel to
`config/logging.php` — Prism attaches its handler to the channels `prism.log.channels` names for
you. What changed is only visible if you reached into the package:

| Removed | Replacement |
| --- | --- |
| `Misakstvanu\Prism\Capture\*` (every class) | The engines' own sensors and instrumentations. Nothing in that namespace was ever public API. |
| `Misakstvanu\Prism\Support\SpanStack` | The OpenTelemetry context. A span's parent is the SDK's, not a stack of Prism's. |
| `Misakstvanu\Prism\Http\Middleware\TraceRequests` | Nothing to replace: the span lane opens the trace now, and `TraceContext` generates its fallback id lazily on first read. Remove it if you registered it by hand. |
| `prism.request.max_body` (`PRISM_MAX_BODY_SIZE`) | The capture engine decides how much of a body it records. The key is removed rather than left reading nothing. |
| `prism.schedule.max_output` (`PRISM_MAX_OUTPUT_SIZE`) | Removed for the same reason: the engine's scheduled-task record carries no output, so there was nothing left to cap. |
| The `prism.capture` block's `requests`, `exceptions`, `queries`, `traces`, `jobs` and `schedules` toggles | Which signals are captured is the capture engine's vocabulary now (its own `filtering` config). `prism:check` no longer prints a "Capture domains" section, because six of the eight entries would have been read by nobody. |

> **If you published `config/prism.php` before 2.0**, the `capture` block is still in your file and
> only two of its entries still do anything: `capture.logs` and `capture.metrics`, which switch off
> log attachment and replica metrics respectively. The other six are inert — set the engine's own
> `filtering` keys, or the `sample` rates, instead.


**The two public helpers keep their signatures and change producer.**
`Prism::captureException($e)` now reports through the capture engine (still `handled`, still
scrubbed, still subject to `prism.ignore.exceptions`). `Prism::span($name, $callback, $type, $extra)`
now opens a **real OpenTelemetry span**, so anything opened inside the closure nests beneath it by
construction and the trace it joins is the one that crosses services and queues; `$extra` becomes
span attributes. With the span lane off (`PRISM_OTEL_ENABLED=false`) the closure still runs and its
value is still returned — it is simply not recorded, which is the same "safe when inert" contract as
before.

### `sample_rates` is gone; sampling is per execution and the block is `sample`

**What changed.** The old `prism.sample_rates` block held one rate per *signal* — requests,
exceptions, logs, queries, traces, jobs, schedules, metrics — and a trace-consistency rule
(`threshold = max(domainRate, requestRate)`) that existed to stop a sampled-out query taking half a
trace with it. Both are gone. The capture engine samples an **execution**: a request, an artisan
command or a worker's run of a job is either captured whole or not at all.

| Removed | Replacement |
| --- | --- |
| `prism.sample_rates.requests` (`PRISM_SAMPLE_REQUESTS`) | `prism.sample.requests`, same variable, same meaning — but it now governs everything that request produced. |
| `prism.sample_rates.schedules` (`PRISM_SAMPLE_SCHEDULES`) | `prism.sample.schedules`, same variable. |
| — | `prism.sample.commands` (`PRISM_SAMPLE_COMMANDS`) is new: an artisan command is an execution here, where the old client had no such rate. |
| `prism.sample_rates.logs` (`PRISM_SAMPLE_LOGS`) | Nothing. A log line is captured if its execution was. |
| `prism.sample_rates.queries` (`PRISM_SAMPLE_QUERIES`) | Nothing, for the same reason. |
| `prism.sample_rates.traces` (`PRISM_SAMPLE_TRACES`) | Nothing. **To cut span volume, lower `sample.requests`** — a sampled-out request ships no spans. |
| `prism.sample_rates.jobs` (`PRISM_SAMPLE_JOBS`) | Nothing, and the reason is worth knowing: a worker's run of a job **inherits the decision made about the execution that dispatched it** (it rides Laravel's `Context` into the payload). A request kept by `sample.requests` has its jobs kept with it; a request dropped drops them. |
| `prism.sample_rates.metrics` (`PRISM_SAMPLE_METRICS`) | Nothing. Replica metrics are shipped outside any execution, so no rate could apply to them. |
| `prism.sample_rates.exceptions` (pinned `1.0`) | Still pinned `1.0`, and still has no variable — see below. |

**What you have to do.** Rename the block if you published `config/prism.php`; a `sample_rates` key
in your file is read by nobody. If you were tuning a per-signal rate to control volume, tune
`sample.requests` instead — it is the only lever now, and it moves everything at once. The new key is
`sample`, **not** `sampling`: on a host that is itself a Prism workspace, `prism.sampling` is the
server's own per-workspace block, and a package/host config merge is shallow, so one key cannot hold
both.

**Two behaviour changes worth knowing.**

- **An error is still never lost to sampling**, but by a different mechanism: an execution that was
  sampled out is pulled *back into* the sample the moment it throws, so the fault and everything
  buffered before it ship anyway. That is why there is deliberately no `prism.sample.exceptions` —
  the rate is pinned at `1.0` with no escape hatch, matching the server, which pins the exception
  domain to `1.0` on ingest.
- **A slow query no longer rescues its execution client-side.** The old rule pulled a whole trace
  through when one query crossed `query.slow_threshold_ms`. Keeping a *slow* trace whole is now
  tail sampling's job (`otel.slow_trace_ms`), and keeping an interesting one is the **server's**
  (Console → Settings → Sampling) — which only ever sees what the client sent, so a rate low enough
  to drop the execution drops it before either rule can have a say.

A rate this package cannot read at all is treated as `1.0`, never `0.0`. The published-config trap
is the reason: with a shallow merge, a `sample` block you published before a key existed reads
`null`, and `(float) null` is `0.0` — an install that captures nothing, permanently and silently.

### Request bodies are captured only on a fault, and only if you ask

**What changed.** The old client captured a scrubbed, truncated body for every non-GET request,
capped by `prism.request.max_body`. The capture engine records a request payload **only when the
response was a 500** — and Prism defaults the switch to **off**:

| Removed | Replacement |
| --- | --- |
| a body on every non-GET request, capped by `prism.request.max_body` (removed with the listeners, above) | `prism.request.capture_payload` (`PRISM_CAPTURE_REQUEST_PAYLOAD`), default `false`. Set it `true` and a body is recorded for a faulting request only. |

**What you have to do.** If you relied on seeing the payload of an ordinary request in the console,
set `PRISM_CAPTURE_REQUEST_PAYLOAD=true` — and know that it still will not appear for a request that
succeeded. The `prism.scrub` list applies to whatever is captured, exactly as before.

**A queued job's payload is not captured at all**, at any setting: the engine's records carry a
job's name, id, queue, connection and outcome and nothing else. That is a strictly safer default
than the old client's capture-then-scrub, and there is no key to turn it back on.

### `X-Prism-Trace-Id` is gone; a trace travels as W3C `traceparent`

**What changed.** Prism used to propagate a trace itself, over three seams of its own:

| Removed | Where it rode |
| --- | --- |
| `X-Prism-Trace-Id` request header | read off every incoming request, so an upstream service could continue a trace |
| `X-Prism-Trace-Id` request header | stamped onto every outgoing call made through Laravel's HTTP client |
| `prism_trace_id` job payload key | stamped onto every dispatched job, so a worker continued the trace that queued it |

All three are removed, along with the constants that named them
(`Misakstvanu\Prism\Support\TraceContext::HEADER` and `::JOB_PAYLOAD_KEY`) and the incoming-id
argument to `TraceContext::start()`, which now takes none.

**What replaced it.** The span lane (`keepsuit/laravel-opentelemetry`) speaks the W3C Trace Context
standard, and Prism leaves `propagators` at `tracecontext`. So the same three boundaries are carried
by `traceparent`: an incoming one makes the request span its child, an outgoing call has one
injected, and a dispatched job carries one in its payload and runs under a CONSUMER span parented to
the PRODUCER span that queued it. Every record is re-keyed onto that trace as it passes through
Prism's ingest, so the two halves agree without configuration.

**Why, rather than a rename.** `X-Prism-Trace-Id` was a header only a Prism client understood: a
Prism-monitored Laravel app calling a Go service produced *two* traces, because nothing on the other
side had heard of it. `traceparent` is spoken by every APM and every language SDK, so the same call
produces one.

**What you have to do.**

- **If you propagated the header by hand** — a non-Laravel service, a raw cURL call, a front end
  stamping it onto its requests — send `traceparent` instead. Its format is
  `00-<32 hex trace id>-<16 hex span id>-<2 hex flags>`; most HTTP clients have a Trace Context
  helper, and any OpenTelemetry SDK injects it for you.
- **If you read the header** off an inbound request to correlate your own logs, read `traceparent`
  and take characters 4–35 (the trace id).
- **If you read `prism_trace_id` out of a job payload**, read the `traceparent` key in the same
  payload.
- **If you did none of those**, there is nothing to do: Prism wires both ends itself.

**One consequence to know about.** A trace id is now a **32-character lowercase hex string** rather
than a UUID, so anything of yours that stored, matched or formatted a Prism trace id as a UUID needs
widening. Trace ids already in ClickHouse are untouched — the column is a plain `String` — so old and
new rows coexist; only a client-side regex or a fixed-width column of your own is at risk.

**Turning the span lane off changes the answer.** With `PRISM_OTEL_ENABLED=false` (or
`PRISM_ENABLED=false`) there is no OpenTelemetry trace to propagate, so Prism falls back to an id of
its own per execution and **nothing crosses a service or queue boundary**. Signals within one
execution still correlate with each other, which is what the fallback is for; a trace that spans
services needs the lane on.

### Prism stands on two engines' internals, and `UpstreamContractTest` is where that is written down

**What changed.** Nothing you call. This section is for the person a red
`tests/Feature/UpstreamContractTest.php` sends here after a `composer update`.

Prism's capture path is built on parts of `laravel/nightwatch` and
`keepsuit/laravel-opentelemetry` that carry **no compatibility promise**:

| What Prism reaches into | Marked | What it is for |
| --- | --- | --- |
| `Laravel\Nightwatch\Contracts\Ingest` | `@internal` | `Nightwatch\PrismIngest` implements it — that is how records reach Prism's own buffer, envelope and transport instead of an agent socket |
| `Laravel\Nightwatch\Core::$ingest` | `@internal` | the one assignment that installs it |
| the **record array shape** | nothing at all | `Nightwatch\RecordTranslator` reads ~60 field names off it, each with a `?? null` |
| the per-execution counters incrementing **inside** a sensor's lazy resolver | undocumented | why a record superseded by the span lane is dropped in Prism's ingest rather than through a `reject*` callback — a reject returns before the counter moves |
| `opentelemetry.traces.processors` | published config | the slot `Otel\PrismSpanProcessor` is registered through, rather than by replacing the tracer |

Every one of those fails **silently** when it moves: a renamed record field ships an event whose
column holds an empty string, a bumped record version drops a whole signal, and a counter moved out
of a resolver reports a busy request as having run nothing. `UpstreamContractTest` is what turns
each of them into a red build.

**What you have to do** when it goes red:

1. Read the failure — each one names the exact method, property, record field or version that moved.
2. Translate it. A moved field is a change in `RecordTranslator`; a bumped version is a change to its
   `VERSIONS` map *after* reading what else changed in that record; a changed ingest signature is a
   change in `PrismIngest`.
3. Update the pin in `UpstreamContractTest` to the new shape.
4. Add an entry here saying what moved, so an installer upgrading across it knows.

The `@api` half — `sample()`, `report()`, `pause()`, the seven `redact*` and the seven `reject*`
callbacks — is pinned too. `@api` is a promise about intent, not a guarantee of signature.
