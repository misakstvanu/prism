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
    | inert, as if it were not installed. Leave it true and rely on the
    | per-domain toggles below to silence individual signals.
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
    | Per-domain capture toggles
    |--------------------------------------------------------------------------
    |
    | Turn individual signals on or off without touching the master switch.
    | Each maps to one telemetry table on the server (US-006). A domain set to
    | false registers no listener for that signal, so disabling a noisy source
    | costs nothing rather than capturing-then-discarding.
    |
    */

    'capture' => [
        'requests' => env('PRISM_CAPTURE_REQUESTS', true),
        'exceptions' => env('PRISM_CAPTURE_EXCEPTIONS', true),
        'logs' => env('PRISM_CAPTURE_LOGS', true),
        'queries' => env('PRISM_CAPTURE_QUERIES', true),
        'traces' => env('PRISM_CAPTURE_TRACES', true),
        'jobs' => env('PRISM_CAPTURE_JOBS', true),
        'schedules' => env('PRISM_CAPTURE_SCHEDULES', true),
        'metrics' => env('PRISM_CAPTURE_METRICS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client-side sample rates
    |--------------------------------------------------------------------------
    |
    | A fraction in [0, 1] of each domain to keep before shipping — a first,
    | client-side reduction on top of the server's trace-consistent sampling
    | (US-031). 1.0 keeps everything; 0.1 keeps roughly a tenth. Exceptions are
    | pinned to 1.0 and never sampled out here, because an error you dropped at
    | the client is an error the console can never show you.
    |
    */

    'sample_rates' => [
        'requests' => (float) env('PRISM_SAMPLE_REQUESTS', 1.0),
        'exceptions' => 1.0,
        'logs' => (float) env('PRISM_SAMPLE_LOGS', 1.0),
        'queries' => (float) env('PRISM_SAMPLE_QUERIES', 1.0),
        'traces' => (float) env('PRISM_SAMPLE_TRACES', 1.0),
        'jobs' => (float) env('PRISM_SAMPLE_JOBS', 1.0),
        'schedules' => (float) env('PRISM_SAMPLE_SCHEDULES', 1.0),
        'metrics' => (float) env('PRISM_SAMPLE_METRICS', 1.0),
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
    | it null for the default queue.
    |
    */

    'batch' => [
        'size' => (int) env('PRISM_BATCH_SIZE', 1000),
        'flush' => env('PRISM_FLUSH_STRATEGY', 'terminate'),
        'timeout' => (float) env('PRISM_FLUSH_TIMEOUT', 2.0),
        'queue_threshold' => (int) env('PRISM_QUEUE_THRESHOLD', 100),
        'queue' => env('PRISM_FLUSH_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request capture
    |--------------------------------------------------------------------------
    |
    | Settings for the HTTP request capturer (US-043). "max_body" caps the byte
    | size of a captured request body — bodies are recorded for non-GET requests
    | only, scrubbed of any sensitive key first, then truncated to this many
    | bytes so a large upload cannot bloat a batch. Zero keeps the whole scrubbed
    | body with no size cap.
    |
    */

    'request' => [
        'max_body' => (int) env('PRISM_MAX_BODY_SIZE', 65536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log capture
    |--------------------------------------------------------------------------
    |
    | Settings for the log capturer (US-044). A Monolog handler is pushed onto
    | each named channel below, so the host's own log output is mirrored to
    | Prism while its existing destinations keep receiving everything.
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
    | on the server regardless of any configured sampling rate (US-031), so a
    | slow query on an otherwise-sampled-out request is never lost. Set it to 0
    | to disable the marker (no query is treated as slow).
    |
    */

    'query' => [
        'slow_threshold_ms' => (float) env('PRISM_SLOW_QUERY_MS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled task capture
    |--------------------------------------------------------------------------
    |
    | Settings for the scheduled-task capturer (US-049). Each run of a scheduled
    | task — command or closure — is captured with its cron expression, exit
    | code, duration, peak memory and captured output. "max_output" caps the
    | byte size of that captured output so a chatty task cannot bloat a batch;
    | zero keeps the whole output with no size cap. Output is read from the file
    | a task streams to (via "sendOutputTo"); a task with the default /dev/null
    | destination contributes no output.
    |
    */

    'schedule' => [
        'max_output' => (int) env('PRISM_MAX_OUTPUT_SIZE', 16384),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime metrics
    |--------------------------------------------------------------------------
    |
    | Point-in-time facts about the process and the queue backend, sampled on an
    | interval and piggybacked onto an existing flush rather than sent on their
    | own request (US-048/US-051).
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
    | Either sample rides the next flush, so a shorter interval never means a
    | dedicated request.
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
    | Ignore lists
    |--------------------------------------------------------------------------
    |
    | Activity the client never captures. Every list but "exceptions" matches
    | with Str::is wildcards, so one pattern can cover a family ("api/internal/*",
    | "App\Jobs\Internal\*", "prism:*"); a pattern without a "*" matches exactly.
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
    |              wrapper.
    |   commands   Scheduled task commands (US-049), matched on the description
    |              if the task sets one, otherwise the command string.
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
    |              validation are not faults worth an error group.
    |
    */

    'ignore' => [
        'paths' => [
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
    | (US-040), matched case-insensitively against request input, headers, job
    | payloads and query bindings. Extend this list with any field specific to
    | your app that must never reach the console — the redaction happens at the
    | source, so a scrubbed value is never transmitted or stored.
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
