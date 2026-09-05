<?php

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| Prism client configuration
|--------------------------------------------------------------------------
|
| Everything the Prism client package reads to decide what to capture and
| where to ship it. Installing the package and setting two variables —
| PRISM_TOKEN and PRISM_APP — is the entire required setup; every other
| value below defaults to something that works out of the box.
|
| Publish this file into a host application to override any default:
|
|     php artisan vendor:publish --tag=prism-config
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | The one kill switch. With PRISM_ENABLED=false the service provider
    | registers no listeners at all and adds zero overhead — the package is
    | inert, as if it were not installed. It is also what switches the capture
    | engine off: "nightwatch.enabled" is derived from this value, so a
    | disabled Prism leaves laravel/nightwatch dormant too rather than letting
    | a transitive dependency keep capturing on its own account.
    |
    | Which signals are captured, and at what rate, is Nightwatch's own
    | vocabulary now (its sampling and filtering blocks) — this package no
    | longer declares "capture" or "sample_rates" toggles of its own.
    |
    */

    'enabled' => env('PRISM_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Credentials  (the only required settings)
    |--------------------------------------------------------------------------
    |
    | "token" is the ingest token minted in the Prism console under
    | Settings → API tokens; "app" is the application slug this process
    | reports as inside the workspace that token belongs to. With no token
    | the package silently no-ops and logs a single warning at boot — it
    | never throws — so a missing variable degrades cleanly instead of
    | breaking the host application.
    |
    | A blank token is allowed on a LOCAL host only: with APP_ENV=local the
    | client captures and ships with no Authorization header at all, and the
    | hub must be running in its own local environment to accept that batch
    | and attribute it to a default workspace (PRISM_INGEST_DEFAULT_ORGANIZATION
    | there). Anywhere else a blank token is the no-op above. "prism:check"
    | reports the token-less local path as a state rather than a failure.
    |
    */

    'token' => env('PRISM_TOKEN'),

    'app' => env('PRISM_APP'),

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Where batches are POSTed. Defaults to the hosted Prism ingest endpoint;
    | point it at a self-hosted workspace's /api/ingest to send there instead.
    |
    */

    'endpoint' => env('PRISM_ENDPOINT', 'https://prism.dev/api/ingest'),

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | "environment" tags every event with the deployment tier (production,
    | staging, …); it defaults to the host app's APP_ENV so most installs need
    | not set it. "replica" is the name this process reports as — one row per
    | replica auto-registers on the server (US-028). It defaults to the machine
    | hostname, which is the right per-container/per-pod identity in most
    | deployments; override it (e.g. with the pod name) when the hostname is
    | not meaningful.
    |
    */

    'environment' => env('PRISM_ENVIRONMENT', env('APP_ENV', 'production')),

    'replica' => env('PRISM_REPLICA', gethostname() ?: 'unknown'),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | The share of executions captured, as a fraction of 1. Sampling here is per
    | EXECUTION rather than per signal: a request, an artisan command or a
    | scheduled task is kept or dropped whole, together with every query, cache
    | read, log line, outgoing call and span it produced. So halving the request
    | rate halves span volume with it — there is no separate span or query rate
    | to turn down, and no half-captured trace to reassemble.
    |
    | 1.0 keeps everything (the default) and 0.0 keeps nothing. A rate this
    | package cannot read at all is treated as 1.0, never 0.0: capturing more
    | than intended is a bill, capturing nothing is an outage nobody is told
    | about.
    |
    | There is deliberately no rate for exceptions. An error is never sampled
    | out — the capture engine pulls a sampled-out execution back into the
    | sample the moment it throws, so a fault on a dropped request still ships,
    | and the Prism server pins the exception rate to 1.0 on ingest as well.
    | A team is never blind to breakage because of a rate someone tuned.
    |
    | Note this is "sample", not "sampling": on a host that IS a Prism
    | workspace, "prism.sampling" is the server's own per-workspace rules and
    | the two must not collide (a package/host config merge is shallow, so one
    | key cannot mean two things).
    |
    */

    'sample' => [
        'requests' => (float) env('PRISM_SAMPLE_REQUESTS', 1.0),
        'commands' => (float) env('PRISM_SAMPLE_COMMANDS', 1.0),
        'schedules' => (float) env('PRISM_SAMPLE_SCHEDULES', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry span lane
    |--------------------------------------------------------------------------
    |
    | The second capture engine, and the one that produces the trace waterfall.
    | `keepsuit/laravel-opentelemetry` instruments the framework through events
    | and middleware — it needs no OS or PECL extension, so installing Prism is
    | still `composer require` and two variables.
    |
    | Spans never leave this process as OTLP. Prism consumes them in-process and
    | ships them in its own batch alongside every other signal, so all three
    | OpenTelemetry exporters (traces, metrics, logs) are pinned to "null" and
    | no exporter connection is ever opened. There is no collector to run and no
    | endpoint to point anywhere.
    |
    | Why a second engine at all: the capture engine's own cache, query and
    | outgoing-request sensors fire on COMPLETION only, so the records they
    | produce are flat siblings sharing a trace id. OpenTelemetry spans carry a
    | parent span id by construction, which is the one thing a waterfall needs
    | and the one thing those records cannot express.
    |
    | With the lane on it is also the PRODUCER of two signals the capture engine
    | watches as well. Both see a query and an outgoing HTTP call, so leaving
    | both alone would store one query as a "queries" row AND a "db" span, and
    | one outgoing call as two "http" spans. The span wins — it is the one that
    | knows its parent — so the engine's own query and outgoing-request records
    | are dropped as they pass through the ingest, and the "queries" row is
    | built from the db span instead. A record is dropped only where the span
    | really replaced it: a console command opens no trace, and neither does a
    | query run before the request span, so in those the engine's record is
    | kept because it is the only producer there is.
    |
    | Turning this off leaves every other signal exactly as it was — requests,
    | errors, logs, queries, jobs, commands, mail, notifications and replica
    | metrics all keep flowing, and the "queries" rows go back to being the
    | capture engine's. What is lost is the nesting: the Traces screen falls
    | back to the spans the engine's own records produce, which are correlated
    | by trace but not by parent. It is also switched off wholesale by
    | PRISM_ENABLED=false, like everything else here.
    |
    | "slow_trace_ms" is the threshold the span lane's TAIL SAMPLING keeps a
    | trace at. Tail sampling defers the keep-or-drop decision until the trace
    | has finished, so a request that turned out to be slow — or that recorded
    | an error anywhere in its tree — is kept WHOLE rather than half-sampled,
    | which is the same stance the Prism server takes when a slow query pulls
    | its trace through. A trace at or beyond this many milliseconds is kept;
    | an unreadable value falls back to 2000, never to zero, because zero is
    | "every trace is slow" and this number is read as a threshold.
    |
    */

    'otel' => [
        'enabled' => (bool) env('PRISM_OTEL_ENABLED', true),

        'slow_trace_ms' => (int) env('PRISM_SLOW_TRACE_MS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batching and flush strategy
    |--------------------------------------------------------------------------
    |
    | Events accumulate in an in-memory buffer during a request (US-037) and
    | ship as one batch. "size" caps how many events one request may buffer;
    | past the cap the excess is dropped and counted (US-037), which bounds
    | memory even under a storm of queries or logs.
    |
    | "flush" chooses when the buffer is sent:
    |   - "terminate": ship after the response is returned, off the request's
    |                  critical path (US-038). The default — the user never
    |                  waits on Prism.
    |   - "sync":      ship inline before the process ends, never handing the
    |                  batch to the queue. Useful for one-off scripts and tests
    |                  where there is no queue worker to drain the batch.
    |   - "spool":     ship from neither. The finished batch is parked in the
    |                  cache and one debounced job drains everything spooled
    |                  since the last drain, so a burst of requests costs one
    |                  ingest POST rather than one each. Requires a queue worker
    |                  and a cache store with atomic locks; without either the
    |                  flush quietly falls back to "terminate" rather than
    |                  holding telemetry it cannot ship. See "spool" below.
    |
    | "timeout" bounds the ingest POST (seconds). Kept short so a slow or
    | unreachable endpoint can never stall the process for long, even though the
    | send happens after the response is returned.
    |
    | "queue_threshold" hands a flush to a queued job instead of an inline send
    | once the buffer holds more than this many events — gzipping and POSTing a
    | large payload during "terminate" would still hold the worker (notably
    | under Octane), so a big batch is offloaded to the queue. Zero keeps every
    | flush inline. "queue" names the queue that job is dispatched onto; leave
    | it null for the default queue — it names the drain job's queue too.
    |
    | The "spool" block below tunes the spool strategy. Only batches from
    | finished work ever reach the spool (a flush runs at the end of a request,
    | job or scheduled task), and a cache lock guards the hand-off, so a drain
    | can never pick up a half-written batch.
    |
    |   delay         Seconds the drain job waits before it runs. This is the
    |                 debounce window: everything spooled during it ships
    |                 together. Longer trades freshness for fewer round trips.
    |   grace         Extra seconds the "a drain is already pending" marker is
    |                 held beyond the delay, covering the time a job waits for a
    |                 free worker. Once it lapses the next flush arms a
    |                 replacement, so a drain lost with its worker cannot strand
    |                 the spool.
    |   ttl           Seconds a spooled batch survives in the cache. A backlog
    |                 nobody could deliver expires rather than accumulating.
    |   max_batches   Batches held at once. Past the cap the oldest are dropped
    |                 (fresh telemetry beats a stale batch nobody could send).
    |                 Zero is unbounded.
    |   max_attempts  Send attempts a batch gets before it is discarded. A batch
    |                 that fails goes back on the spool one attempt older, so an
    |                 outage costs a delay rather than the telemetry — but a
    |                 permanently wrong endpoint must not cycle forever.
    |   store         Cache store backing the spool; null uses the default. It
    |                 must support atomic locks and be shared by every process
    |                 that captures (so not "array" outside tests).
    |   lock_seconds  How long the index lock is held, and lock_wait how long a
    |                 flush waits for it before giving up and sending inline.
    |                 Both are short: the critical section is a list of keys.
    |
    */

    'batch' => [
        'size' => (int) env('PRISM_BATCH_SIZE', 1000),
        'flush' => env('PRISM_FLUSH_STRATEGY', 'terminate'),
        'timeout' => (float) env('PRISM_FLUSH_TIMEOUT', 2.0),
        'queue_threshold' => (int) env('PRISM_QUEUE_THRESHOLD', 100),
        'queue' => env('PRISM_FLUSH_QUEUE'),
        'spool' => [
            'delay' => (int) env('PRISM_SPOOL_DELAY', 5),
            'grace' => (int) env('PRISM_SPOOL_GRACE', 60),
            'ttl' => (int) env('PRISM_SPOOL_TTL', 900),
            'max_batches' => (int) env('PRISM_SPOOL_MAX_BATCHES', 500),
            'max_attempts' => (int) env('PRISM_SPOOL_MAX_ATTEMPTS', 3),
            'store' => env('PRISM_SPOOL_STORE'),
            'lock_seconds' => (int) env('PRISM_SPOOL_LOCK_SECONDS', 5),
            'lock_wait' => (int) env('PRISM_SPOOL_LOCK_WAIT', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request capture
    |--------------------------------------------------------------------------
    |
    | What an HTTP exchange carried: the body the client sent and the body the
    | application sent back. Neither comes from the capture engine — its request
    | record serialises a payload only for a 500 and has no notion of a response
    | body at all — so Prism captures both itself, from a middleware that holds
    | the request and the response at the same time.
    |
    | "capture_body" and "capture_response" default to TRUE, and record every
    | request rather than only a faulting one. That is a deliberate reversal of
    | the previous default: a request body is the single most useful thing to
    | have when a bug reproduces once, and a response body is what says whether
    | the fault was in what came back or in what went in. Turn either off where
    | the exposure outweighs the debugging — the scrub list still applies, and
    | applies BY KEY for a JSON or form body, so a `password` field is redacted
    | rather than pattern-matched out of the text.
    |
    | "max_body" caps each body in bytes. The cut never lands inside a multi-byte
    | character and a truncated body says so, so what is stored is always
    | insertable and never silently partial. `0` disables the cap, which is not
    | advised: a column's cost is bytes and a request's body is whatever an
    | unknown client decided to send.
    |
    | "body_content_types" is what stops a rendered image, a PDF export or a gzip
    | stream reaching a String column. It is matched against the media type
    | alone, so `application/json; charset=utf-8` counts and a `+json` / `+xml`
    | vendor type resolves to its base. NOTE that "text/html" is deliberately
    | absent: an HTML response is the rendered page, the largest and least
    | diagnostic thing an application produces. Add it if you want it — this list
    | REPLACES the default wholesale, because the config merge is shallow.
    |
    | Uploaded file CONTENTS are never recorded at any setting. A multipart
    | request is stored as its ordinary fields plus a `_prism_files` entry naming
    | and sizing each upload.
    |
    | "capture_body" also drives the capture engine's own `capture_request_payload`,
    | so the two answers cannot disagree.
    |
    */

    'request' => [
        'capture_body' => (bool) env('PRISM_CAPTURE_REQUEST_BODY', true),
        'capture_response' => (bool) env('PRISM_CAPTURE_RESPONSE_BODY', true),
        'max_body' => (int) env('PRISM_MAX_BODY', 65536),
        'body_content_types' => [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data',
            'application/xml',
            'text/xml',
            'text/plain',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log capture
    |--------------------------------------------------------------------------
    |
    | Settings for log capture (US-044). The capture engine registers a
    | "nightwatch" log channel and captures nothing until that channel is in
    | your stack, so Prism pushes its handler onto the channels named below for
    | you — your existing destinations keep receiving everything, and there is
    | no config/logging.php edit to remember.
    |
    | "channels" names the logging channels to capture. Leave it empty to attach
    | to the application's default channel (its "stack"), which is what most
    | installs want; list explicit channel names to capture only those.
    |
    | "level" is the minimum level captured — records below it are dropped before
    | they are buffered. It defaults to "debug" (capture everything) and takes any
    | PSR-3 level name (debug, info, notice, warning, error, critical, alert,
    | emergency).
    |
    */

    'log' => [
        'channels' => [],
        'level' => env('PRISM_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query capture
    |--------------------------------------------------------------------------
    |
    | Settings for the database query capturer (US-045). "slow_threshold_ms" is
    | the execution time (in milliseconds) at or above which a query is marked
    | slow. A slow query is always kept — and forces its whole trace to be kept —
    | on the SERVER, regardless of the per-workspace sampling rules configured
    | there (US-031). It does not survive the client-side "sample" rates above:
    | those drop a whole execution before anything is sent, so nothing reaches
    | the server to be rescued. Set it to 0 to disable the marker (no query is
    | treated as slow).
    |
    */

    'query' => [
        'slow_threshold_ms' => (float) env('PRISM_SLOW_QUERY_MS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime metrics
    |--------------------------------------------------------------------------
    |
    | Point-in-time facts about the process and the queue backend, sampled on an
    | interval at the end of an execution (US-048/US-051).
    |
    | "system_interval" is how often (in seconds) this instance's own health —
    | CPU load, memory pressure and uptime — is sampled (US-051). It runs on
    | every replica, web or worker, since an instance's health matters wherever
    | it runs. Readings come from the host OS (load average, /proc/meminfo,
    | /proc/uptime); a host that does not expose one reports null for that field
    | rather than failing.
    |
    | "queue_interval" is how often (in seconds) queue depth and worker counts
    | are sampled (US-048). Queue polling runs only on a process that actually
    | works the queue (a "queue:work"/"queue:listen"/Horizon process). A web
    | replica that dispatches jobs but never processes them registers no polling
    | at all, so it costs nothing there. Depth is read through the host
    | application's own configured queue connection — Prism never opens a backend
    | connection of its own — and a backend it cannot read degrades to a null
    | depth rather than throwing.
    |
    | Either sample ships in a batch of its OWN, and deliberately so (US-013):
    | the capture engine discards an execution's whole batch when the sampling
    | rates below reject it, and a replica's health has nothing to do with
    | whether one request was interesting. Lowering "sample.requests" therefore
    | does not thin out the Replicas screen or the replica_cpu alert with it.
    | The interval is what keeps that from meaning a send per execution.
    |
    | This is also the one signal the capture engine has no sensor for at all,
    | which is why Prism still collects it itself.
    |
    | "replica_type" is the kind of instance this process reports as — "web" or
    | "worker". Leave it null to infer it from the process (a queue worker
    | reports "worker", anything else "web"); set it (e.g. via PRISM_REPLICA_TYPE)
    | when the inference is wrong for your deployment.
    |
    */

    'metrics' => [
        'system_interval' => (int) env('PRISM_METRICS_INTERVAL', 60),
        'queue_interval' => (int) env('PRISM_QUEUE_POLL_INTERVAL', 30),
        'replica_type' => env('PRISM_REPLICA_TYPE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser telemetry
    |--------------------------------------------------------------------------
    |
    | The endpoint the browser SDK (@misakstvanu/prism-browser) posts to, and
    | the limits it is held to. It is registered for you the moment this package
    | is installed and enabled, so the frontend half needs no backend code of
    | yours — install the npm package, point it at "path" below and browser
    | errors, logs and page views arrive beside your server-side ones.
    |
    | The report goes to YOUR application rather than to the Prism workspace,
    | and that is the whole shape of the feature. A browser cannot be given an
    | ingest token — anything the page can read, a reader can read — and a
    | workspace endpoint open to the page would be an unauthenticated write from
    | an origin nobody controls. Posting here instead means the report is
    | enriched with what only the server knows (the signed-in user, the client
    | IP, the environment), scrubbed with the same "scrub" list every other
    | signal meets, and shipped through the same pipeline.
    |
    |   enabled     Whether the route is registered at all. Off, the endpoint
    |               does not exist and the SDK's posts 404.
    |   path        Where it is registered. Anything under a path the host does
    |               not already use; "_prism/" is a namespace nothing else
    |               claims. It is excluded from capture automatically — see
    |               "ignore.paths" below — whatever you rename it to.
    |   max_events  Events one post may carry. Past the cap the excess is
    |               dropped, which bounds what a page can cost the server in one
    |               request.
    |   max_bytes   Bytes one post may carry, measured before it is decoded.
    |   rate_limit  Posts per client IP per minute. 0 disables throttling
    |               entirely; the default is deliberately generous, because a
    |               busy page in a bad state reports in bursts and the throttle
    |               is there to bound abuse rather than to sample.
    |   origins     Origins allowed to post cross-origin. Empty — the default —
    |               is same-origin only, which is every install that serves its
    |               frontend from its own domain and needs no CORS at all. List
    |               a scheme and host ("https://app.example.com") for a frontend
    |               deployed apart from the backend it reports to, and the route
    |               answers OPTIONS preflights and allows credentials for that
    |               origin alone; an unlisted one gets no CORS header, and a
    |               listed one is echoed back rather than answered with "*",
    |               which a browser refuses alongside credentials. Str::is
    |               wildcards work here as they do in "ignore" below, so
    |               "https://*.example.com" covers a fleet of preview URLs.
    |   guard       The auth guard the signed-in user is read from; null uses
    |               the application's default guard.
    |   trust_client_user  Whether a user id the PAGE claims is believed when
    |               the request carries no authenticated session. It defaults to
    |               FALSE and should stay there: a report is an unauthenticated
    |               write, so a client-supplied identity is a value anyone can
    |               set to anyone. Turn it on only where the console's user
    |               attribution is not worth trusting anyway.
    |
    | The route is deliberately NOT behind auth middleware: an anonymous visitor
    | hitting a JavaScript error is exactly the report worth having. It also
    | runs without CSRF verification, because navigator.sendBeacon cannot set a
    | header and a write-only telemetry sink has nothing forgery would win.
    |
    */

    'browser' => [
        'enabled' => (bool) env('PRISM_BROWSER_ENABLED', true),
        'path' => env('PRISM_BROWSER_PATH', '_prism/browser'),
        'max_events' => 50,
        'max_bytes' => 262144,
        'rate_limit' => 120,
        'origins' => [],
        'guard' => null,
        'trust_client_user' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore lists
    |--------------------------------------------------------------------------
    |
    | Activity the client never captures. Every list but "exceptions" matches
    | with Str::is wildcards, so one pattern can cover a family ("api/internal/*",
    | "App\Jobs\Internal\*", "prism:*"); a pattern without a "*" matches exactly.
    |
    | The first three silence a whole EXECUTION: a request, a job's run or a
    | command that matches contributes nothing at all — not its own row, and not
    | the queries, cache reads, log lines or outgoing calls it made along the
    | way. The last three silence one SIGNAL wherever it occurs, so an ignored
    | cache key is invisible on an otherwise fully captured request.
    |
    | Two different reasons to add something here. The mild one is noise: a
    | health check polled every second, or a cache key touched on every request,
    | costs an event each time and tells you nothing. The serious one is
    | feedback: capturing work that exists *because* of telemetry means the
    | capture produces more work to capture. An application monitored by a Prism
    | workspace that it also hosts is the clearest case — the inbound ingest
    | request, the job that stores the batch and the datastore write it performs
    | each generate the events that trigger the next round, and the loop does not
    | settle. Prism's own outbound work is excluded automatically (US-039), but
    | work the *host* does on Prism's behalf is only known to the host.
    |
    |   paths      Request URI patterns (US-043). Defaults cover Prism's own
    |              routes, dev tooling and health checks. An inbound ingest batch
    |              from another Prism client is skipped automatically, whatever
    |              path it arrives on, so it needs no entry here.
    |   jobs       Queued job class names (US-047), as resolved for display — for
    |              a queued broadcast that is the event class, not the framework's
    |              wrapper. Covers the dispatch and the worker's run alike.
    |   commands   Artisan command names — "prism:*", "reports:build". A
    |              scheduled task runs as one of these, so silencing it here
    |              silences it wherever it was started from.
    |   http       Outgoing HTTP destinations (US-046), matched against the host,
    |              the host and path, and the full URL without its query string —
    |              "redis.internal", "*.googleapis.com", "http://ch:8123/*" all
    |              work. Worth using for a datastore reached over HTTP rather than
    |              a database connection, where every read and write would
    |              otherwise become a span.
    |   cache      Cache keys (US-046), matched on the full key before it is
    |              truncated for display. Cache is the chattiest signal: one
    |              operation touching several counters emits a span per counter.
    |   exceptions Exception classes and their subclasses, never reported
    |              (US-042). Matched with instanceof rather than by pattern, so a
    |              subclass of an ignored throwable is ignored too. Routine
    |              control-flow throwables like a missing route or a failed
    |              validation are not faults worth an error group — Laravel's own
    |              "don't report" list already covers those two, so the entries
    |              below are a restatement and the list earns its keep for
    |              whatever you add to it.
    |
    */

    'ignore' => [
        'paths' => [
            // The browser SDK's own reporting endpoint. A report is a request
            // the page made *because* of telemetry, so capturing it would put a
            // request row, its session read and its queue dispatch into the very
            // batch it produced. The configured `browser.path` is excluded on
            // top of this whatever it is renamed to (see RejectRules), so this
            // entry is what covers the default and anything else under the
            // namespace.
            '_prism/*',

            'prism',
            'prism/*',
            'telescope*',
            'horizon*',
            '_debugbar*',
            'nova*',
            'up',
            'health*',
        ],
        'jobs' => [
            // \App\Jobs\HighFrequencyInternalJob::class,
        ],
        'commands' => [
            // 'metrics:*',
        ],
        'http' => [
            // 'search.internal',
        ],
        'cache' => [
            // 'session:*',
        ],
        'exceptions' => [
            NotFoundHttpException::class,
            ValidationException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrubbed keys
    |--------------------------------------------------------------------------
    |
    | Keys whose values are redacted before an event leaves the process
    | (US-040), matched case-insensitively. Extend this list with any field
    | specific to your app that must never reach the console — the redaction
    | happens at the source, so a scrubbed value is never transmitted or stored.
    |
    | One list governs every signal. A key here is redacted wherever it appears:
    | in a request's body, its headers and its query string; in an outgoing
    | call's query string; and as the value half of a "name = value" pair
    | anywhere Prism captures free text — a SQL statement, an artisan command
    | line, a cache key, a mail subject, an exception message. So a secret
    | passed as --api-key=..., written into a raw statement, or built into a
    | cache key all lose the value and keep the shape.
    |
    | Not listed here because there is nothing to list: a queued job's payload
    | is never captured at all, so a secret dispatched inside a job cannot reach
    | the console however this list is written.
    |
    */

    'scrub' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'authorization',
        'cookie',
        'api_key',
        'access_token',
        'refresh_token',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ],

];
