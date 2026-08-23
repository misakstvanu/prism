<?php

declare(strict_types=1);

namespace Misakstvanu\Prism;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\CacheInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ConsoleInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\EventInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\LivewireInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueryInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\QueueInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\RedisInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ScoutInstrumentation;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\ViewInstrumentation;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\ErrorsRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\Rules\SlowTraceRule;
use Keepsuit\LaravelOpenTelemetry\TailSampling\TailSamplingRuleInterface;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Factories\Logger as NightwatchLoggerFactory;
use Laravel\Nightwatch\Records\CacheEvent as CacheEventRecord;
use Laravel\Nightwatch\Records\Command as CommandRecord;
use Laravel\Nightwatch\Records\Exception as ExceptionRecord;
use Laravel\Nightwatch\Records\Mail as MailRecord;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Query as QueryRecord;
use Laravel\Nightwatch\Records\QueuedJob;
use Laravel\Nightwatch\Records\Request as RequestRecord;
use Laravel\Nightwatch\SensorManager;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Console\BenchCaptureCommand;
use Misakstvanu\Prism\Console\CheckCommand;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Http\Middleware\RejectIgnoredRequests;
use Misakstvanu\Prism\Metrics\QueueMetrics;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Nightwatch\RejectRules;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Otel\SpanLane;
use Misakstvanu\Prism\Otel\SpanLineage;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Runtime;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\HttpTransport;
use Misakstvanu\Prism\Transport\Transport;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use ReflectionProperty;
use Throwable;

/**
 * The Prism client's Laravel entry point.
 *
 * Auto-discovered through the package's composer `extra.laravel.providers`, so
 * a host application only has to `composer require misakstvanu/prism` — there
 * is nothing to register by hand.
 *
 * Boot gates capture on two conditions so a misconfigured install degrades
 * cleanly (US-036):
 *
 *   - `prism.enabled` is the master switch. When false, boot returns before
 *     touching the event system, so a disabled install registers no listeners
 *     at all and adds zero overhead.
 *   - `prism.token` must be set. When it is missing the package silently
 *     no-ops and logs one warning at boot — it never throws — because a client
 *     with nowhere to send telemetry has nothing to do.
 *
 * `registerCapture()` is the seam later stories fill: the event buffer
 * (US-037), async flush (US-038), trace propagation (US-041), exception
 * capture (US-042) and the remaining per-domain listeners (US-043+) all hang
 * off it, and it is reached only on the fully-configured path, so the two
 * guards above cover everything they add.
 *
 * US-038 adds the flush half: a `terminating` callback drains the buffer and
 * ships it after the response has been sent, and an Octane request-start
 * listener resets the buffer so a long-lived worker never leaks one request's
 * events into the next.
 */
class PrismServiceProvider extends ServiceProvider
{
    /**
     * Container flag set once capture is wired. Absent means the client is
     * inert (disabled or unconfigured); downstream code reads it rather than
     * re-deriving the enabled/token decision.
     */
    public const ACTIVE = 'prism.active';

    /**
     * The slow-trace threshold an install falls back to when
     * `prism.otel.slow_trace_ms` is missing or unreadable — upstream's own
     * default, and the number `config/prism.php` documents. See
     * {@see slowTraceThreshold()} for why the fallback is not zero.
     */
    private const DEFAULT_SLOW_TRACE_MS = 2000;

    /**
     * Which of the span lane's instrumentations Prism turns on, and the
     * environment variable a host answers each with.
     *
     * The five that are on produce the lanes the Traces waterfall draws — the
     * request itself, outgoing calls, database queries, Redis commands and the
     * queue hand-off. The six that are off would each be a second source of a
     * signal the capture engine already owns, or a signal Prism has nowhere to
     * put:
     *
     *   - `cache` only calls `addEvent()` on the active span. Span *events* are
     *     not spans and Prism has no column for them, so the cache lane comes
     *     from the capture engine's own `cache-event` record instead.
     *   - `console` would double every artisan command the engine's own command
     *     sensor already records.
     *   - `event`, `view`, `livewire` and `scout` have no Prism counterpart at
     *     all: they would arrive as untyped spans nothing on any screen reads.
     *
     * @var array<class-string, array{string, bool}>
     */
    private const OTEL_INSTRUMENTATION = [
        HttpServerInstrumentation::class => ['OTEL_INSTRUMENTATION_HTTP_SERVER', true],
        HttpClientInstrumentation::class => ['OTEL_INSTRUMENTATION_HTTP_CLIENT', true],
        QueryInstrumentation::class => ['OTEL_INSTRUMENTATION_QUERY', true],
        QueueInstrumentation::class => ['OTEL_INSTRUMENTATION_QUEUE', true],
        RedisInstrumentation::class => ['OTEL_INSTRUMENTATION_REDIS', true],
        CacheInstrumentation::class => ['OTEL_INSTRUMENTATION_CACHE', false],
        ConsoleInstrumentation::class => ['OTEL_INSTRUMENTATION_CONSOLE', false],
        EventInstrumentation::class => ['OTEL_INSTRUMENTATION_EVENT', false],
        ViewInstrumentation::class => ['OTEL_INSTRUMENTATION_VIEW', false],
        LivewireInstrumentation::class => ['OTEL_INSTRUMENTATION_LIVEWIRE', false],
        ScoutInstrumentation::class => ['OTEL_INSTRUMENTATION_SCOUT', false],
    ];

    /**
     * Whether the host had already switched the OpenTelemetry SDK off before
     * Prism looked. Decided once, at register time: the derivation runs twice
     * (see {@see configureOpenTelemetry()}) and by the second pass the key
     * holds Prism's own answer, so re-reading it would let a first pass made
     * while the client looked disabled pin the SDK off for good.
     */
    private bool $otelDisabledByHost = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prism.php', 'prism');

        $this->configureNightwatch();
        $this->configureOpenTelemetry();
    }

    /**
     * Derive `laravel/nightwatch`'s own configuration from Prism's, so a host
     * publishes ONE config file rather than two for a package it never asked
     * for. Four facts, each load-bearing rather than tidiness:
     *
     *   - `enabled` follows `prism.enabled`, so the master switch really is one
     *     switch. Nightwatch defaults itself to enabled and arrives as a
     *     transitive dependency, so without this a PRISM_ENABLED=false install
     *     would still register 38 framework hooks and keep trying to reach an
     *     agent that is not there.
     *   - `token` follows `prism.token`. It is unused on the send path — the
     *     transport is replaced wholesale by assigning over `Core::$ingest` —
     *     but it is what Nightwatch hashes into each record group, so the two
     *     should name the same credential.
     *   - `server` follows `prism.replica`, so a record's own `server` field and
     *     the batch envelope's `replica` field name the same process.
     *   - `sampling.*` follows `prism.sample.*`, with `sampling.exceptions`
     *     pinned at 1.0 (US-012). Sampling is per execution on both sides, so
     *     the three rates map one-for-one; the exception rate is what upstream
     *     re-rolls a sampled-out execution against when it throws, and pinning
     *     it is what keeps an error from being lost to a rate someone tuned.
     *
     * `redact_payload_fields`, `redact_headers` and `capture_request_payload`
     * come from `prism.scrub` the same way (US-011).
     *
     * `nightwatch.ingest.uri` is deliberately left alone: nothing opens that
     * socket once the ingest is Prism's.
     *
     * PROVIDER ORDER IS THE TRAP HERE, and it does not go our way. Laravel
     * discovers providers in `vendor/composer/installed.json` order, which is
     * sorted by package name — `laravel/nightwatch` sorts before
     * `misakstvanu/prism` in every host there will ever be. Nightwatch
     * snapshots its config into `Core` during its own `register()`, so by the
     * time this runs the values it will actually use have already been read.
     * Hence two halves:
     *
     *   1. the config keys are written, which is what Nightwatch reads when it
     *      registers *after* this provider (its own test suite, and anywhere the
     *      host registers the two by hand); and
     *   2. an already-built `Core` is reconciled in place, which is the path a
     *      real install takes — twice, because `prism.replica` is not final at
     *      register time either; see below.
     *
     * A `$config->has()` guard would look like the right way to leave a host's
     * own values alone and would in fact disable half of this: after
     * Nightwatch's `mergeConfigFrom` every one of these keys exists, so "unset"
     * is never true in a real host. Ownership is therefore decided by evidence
     * that survives the ordering — a published `config/nightwatch.php`, or an
     * explicit `NIGHTWATCH_*` variable in the environment.
     */
    private function configureNightwatch(): void
    {
        // The host's own file wins outright: it was loaded during bootstrap,
        // long before any provider registered, and it answers every key.
        if (is_file($this->app->configPath('nightwatch.php'))) {
            return;
        }

        $this->reconcileNightwatchCore($this->applyNightwatchConfig());

        // AND AGAIN ONCE EVERYTHING HAS BOOTED, because `prism.replica` is not
        // final at register time (US-005). Anything that sets it from a
        // provider's own `boot()` — a host naming its process, Octane, a
        // Testbench case defining its environment — moves it after this
        // provider registered, and a record whose `server` still named the
        // machine's hostname while the envelope carrying it named the
        // configured replica would put one process on the console under two
        // names: every read that joins the two dimensions quietly finds
        // nothing. Re-deriving is idempotent, and it is deliberately the same
        // call rather than a second implementation that stamps the server
        // alone.
        $this->app->booted(function (): void {
            $this->reconcileNightwatchCore($this->applyNightwatchConfig());
        });
    }

    /**
     * Write the derived `nightwatch.*` keys and report which ones Prism
     * actually owns — a host that answered one through the environment keeps
     * its answer, and is left out of the returned list so the `Core`
     * reconciliation below leaves it alone too.
     *
     * @return array<string, mixed>
     */
    private function applyNightwatchConfig(): array
    {
        $config = $this->app['config'];
        $redact = RedactRules::fromConfig($config);

        $derived = [
            'enabled' => ['NIGHTWATCH_ENABLED', (bool) $config->get('prism.enabled')],
            'token' => ['NIGHTWATCH_TOKEN', $config->get('prism.token')],
            'server' => ['NIGHTWATCH_SERVER', (string) $config->get('prism.replica')],
            // `prism.scrub` is the one list, so the two keys upstream's own
            // request sensor reads are derived from it rather than kept beside
            // it (US-011). `capture_request_payload` rides along because it
            // decides whether the first of them is consulted at all.
            'capture_request_payload' => [
                'NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD',
                (bool) $config->get('prism.request.capture_payload', false),
            ],
            'redact_payload_fields' => ['NIGHTWATCH_REDACT_PAYLOAD_FIELDS', $redact->payloadFields()],
            'redact_headers' => ['NIGHTWATCH_REDACT_HEADERS', $redact->headerNames()],
            // `prism.sample.*` in the engine's vocabulary (US-012). Sampling is
            // per EXECUTION on both sides, so the three rates map one-for-one
            // and only the scheduled-task key is spelt differently.
            'sampling.requests' => ['NIGHTWATCH_REQUEST_SAMPLE_RATE', $this->sampleRate($config, 'requests')],
            'sampling.commands' => ['NIGHTWATCH_COMMAND_SAMPLE_RATE', $this->sampleRate($config, 'commands')],
            'sampling.scheduled_tasks' => ['NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE', $this->sampleRate($config, 'schedules')],
            // Pinned, with no environment escape and no `prism.*` key behind
            // it: an error is never lost to sampling. Upstream re-samples a
            // sampled-out execution at this rate the moment it throws, so 1.0
            // here is what makes a fault on a dropped request ship anyway —
            // the same stance the Prism server takes on ingest, where the
            // exception domain is pinned to 1.0 too.
            'sampling.exceptions' => [null, 1.0],
        ];

        $applied = [];

        foreach ($derived as $key => [$variable, $value]) {
            // The host answered this one itself; Prism does not argue with it.
            if ($variable !== null && Env::get($variable) !== null) {
                continue;
            }

            $config->set("nightwatch.{$key}", $value);

            $applied[$key] = $value;
        }

        return $applied;
    }

    /**
     * One configured sampling rate, clamped to the 0..1 the engine accepts.
     *
     * **An unreadable rate is 1.0, never 0.0.** `(float) null` is zero, so a
     * `prism.sample` block that is missing — a host config that replaced it
     * (the package/host merge is shallow), a published file written before this
     * key existed — would otherwise switch the whole install off with nothing
     * anywhere saying so. Capturing more than intended shows up on a bill;
     * capturing nothing shows up as a console that has quietly been empty for a
     * week.
     *
     * @param  mixed  $config  The application's config repository.
     */
    private function sampleRate($config, string $key): float
    {
        $rate = $config->get("prism.sample.{$key}");

        if (! is_numeric($rate)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $rate));
    }

    /**
     * Push the derived values onto a `Core` that was built before this provider
     * registered — the normal case, see `configureNightwatch()`.
     *
     * `enabled` cannot be un-registered once Nightwatch has wired its hooks, so
     * a disabled Prism reaches for `pause()` instead: the runtime flag every
     * sensor checks before it records anything. That is what makes the master
     * switch honest on an install where Prism went second.
     *
     * @param  array<string, mixed>  $applied  The keys Prism actually owns.
     */
    private function reconcileNightwatchCore(array $applied): void
    {
        // Not bound yet: Nightwatch has not registered, and the config written
        // above is exactly what it will read when it does.
        if (! $this->app->bound(Core::class)) {
            return;
        }

        try {
            /** @var Core<RequestState|CommandState> $core */
            $core = $this->app->make(Core::class);

            if (array_key_exists('server', $applied)) {
                $core->executionState->server = (string) $applied['server'];
            }

            if (array_key_exists('enabled', $applied)) {
                $core->config['enabled'] = (bool) $applied['enabled'];

                if (! $applied['enabled']) {
                    $core->pause();
                }
            }

            // The rates are read out of `Core::$config` every time an execution
            // begins (`configureRequestSampling()`, `configureCommandSampling()`,
            // `configureScheduledTaskSampling()`) and when one throws
            // (`report()`), and that array was snapshotted during Nightwatch's
            // own `register()` — so writing the config keys alone leaves every
            // execution sampled at upstream's defaults (US-012).
            foreach ($applied as $key => $value) {
                if (str_starts_with($key, 'sampling.')) {
                    $core->config['sampling'][substr($key, strlen('sampling.'))] = (float) $value;
                }
            }

            $this->reconcileNightwatchSensor($core, $applied);
        } catch (Throwable) {
            // Nightwatch is a dependency, not a contract with the host. A shape
            // change upstream must never take the host application down with it;
            // US-022 is where that drift is caught loudly instead.
        }
    }

    /**
     * Push the three redaction keys onto the `SensorManager` Nightwatch built
     * during its own `register()` (US-011).
     *
     * `capture_request_payload`, `redact_payload_fields` and `redact_headers`
     * are read once, as constructor arguments, and held privately — they are
     * not looked up again while the process runs. So in the ordering every real
     * install has (`laravel/nightwatch` sorts before `misakstvanu/prism`) the
     * config keys written a moment ago are read by nobody, and a host that set
     * `PRISM_CAPTURE_REQUEST_PAYLOAD=true` would silently capture no body at
     * all. This is the same "reconcile the built object in place" half that
     * `enabled` and `server` need, one level down.
     *
     * The request sensor is dropped rather than reconfigured because it is
     * memoised from exactly these three values; it has not been built yet at
     * boot, and clearing it keeps a re-boot (a test, an Octane worker)
     * idempotent.
     *
     * @param  Core<RequestState|CommandState>  $core
     * @param  array<string, mixed>  $applied
     */
    private function reconcileNightwatchSensor(Core $core, array $applied): void
    {
        $properties = [
            'capture_request_payload' => 'captureRequestPayload',
            'redact_payload_fields' => 'redactPayloadFields',
            'redact_headers' => 'redactHeaders',
        ];

        $reconciled = false;

        foreach ($properties as $key => $property) {
            if (! array_key_exists($key, $applied) || ! property_exists($core->sensor, $property)) {
                continue;
            }

            (new ReflectionProperty(SensorManager::class, $property))
                ->setValue($core->sensor, $applied[$key]);

            $reconciled = true;
        }

        if ($reconciled) {
            $core->sensor->requestSensor = null;
        }
    }

    /**
     * Derive `keepsuit/laravel-opentelemetry`'s configuration from Prism's, so
     * a host publishes ONE config file rather than three for two packages it
     * never asked for (US-014).
     *
     * The span lane exists for one reason: the capture engine's cache, query
     * and outgoing-request sensors fire on COMPLETION only, so their records
     * are flat siblings sharing a trace id and no decorator can reconstruct
     * nesting from them. An OpenTelemetry span carries a parent span id by
     * construction, which is the whole of what a waterfall needs.
     *
     * Six facts, each load-bearing:
     *
     *   - **The span processor is the whole lane**, and it is one line of
     *     config: {@see PrismSpanProcessor} is appended to upstream's own
     *     `traces.processors` slot. See {@see registerSpanProcessor()}.
     *   - **Nothing is exported as OTLP.** `traces`, `metrics` and `logs` all
     *     name the `null` exporter, which upstream answers with its Noop
     *     implementations — so no PSR-18 client is discovered, no transport is
     *     built and no connection is opened. Prism consumes the spans in
     *     process and ships them in its own batch; there is no collector to
     *     run and no endpoint to point anywhere.
     *   - **The identity is Prism's**, because a span's resource has to name
     *     the same application, environment and replica the batch envelope
     *     does: `service.name` from `prism.app`, `service.instance.id` from
     *     `prism.replica` and `deployment.environment` from
     *     `prism.environment`.
     *   - **Five instrumentations on, six off** — see {@see OTEL_INSTRUMENTATION}.
     *   - **`worker_mode.flush_after_each_iteration` is flipped to true.**
     *     Upstream defaults it to false, which in a queue worker or an Octane
     *     process means one job's spans are still sitting in the batch
     *     processor when the next job starts and ship inside *its* batch —
     *     the spans land under the wrong execution, and nothing anywhere
     *     reports a problem.
     *   - **`prism.otel.enabled` (and `prism.enabled`) switch the SDK off**
     *     rather than merely leaving it unconfigured, so the master switch
     *     silences all of it.
     *
     * ORDERING, and it is a different shape from Nightwatch's. Providers are
     * discovered in `installed.json` order, sorted by package name, so
     * `keepsuit/laravel-opentelemetry` registers *and boots* before
     * `misakstvanu/prism`. What saves the config half is that every value above
     * is read in upstream's `packageBooted()` — after every provider's
     * `register()` — so writing the keys here is enough for a real install.
     *
     * What is NOT enough is writing them only here: `prism.app` and
     * `prism.replica` are not final at register time (a host naming its
     * process from its own `boot()`, Octane, and every Testbench case, whose
     * `defineEnvironment()` runs *after* `RegisterProviders`). So the same
     * derivation runs again from an `$this->app->booting(...)` callback, which
     * fires before the first provider boots and therefore still lands ahead of
     * upstream's own. Idempotent by construction, and deliberately the same
     * call rather than a second implementation that stamps the identity alone.
     *
     * A host that has published `config/opentelemetry.php` is running the SDK
     * on its own account — exporting to its own collector, most likely — so
     * Prism does not argue with any of it and returns before writing a thing.
     */
    private function configureOpenTelemetry(): void
    {
        // Where a span sits (US-015). Bound in `register()` and as a singleton
        // for two reasons: the span processor is CONSTRUCTED while
        // `keepsuit/…` boots — one provider before Prism's own `boot()` — and
        // the processor and the ingest have to share one registry of trace
        // origins, or a cache span's offset is measured from a front of the
        // trace nothing else agrees on.
        $this->app->singleton(SpanLineage::class);

        // Which signals the span lane owns, and so which of the capture
        // engine's records are duplicates of a span (US-018). Bound here, and
        // unconditionally, for the same two reasons: the span processor reaches
        // for it while `keepsuit/…` boots, and `prism:check` asks it the same
        // question an operator is asking — one rule, read in three places.
        $this->app->singleton(SpanLane::class);

        // The host's own file wins outright: it was loaded during bootstrap,
        // long before any provider registered, and it answers every key. That
        // includes the `traces.processors` slot, so such a host adds
        // PrismSpanProcessor itself — `prism:check` reports it as installed but
        // not registered, which is the one state worth being told about.
        if (is_file($this->app->configPath('opentelemetry.php'))) {
            return;
        }

        $this->otelDisabledByHost = (bool) $this->app['config']->get('opentelemetry.disabled', false);

        $this->applyOpenTelemetryConfig();

        // AND AGAIN once the identity is final — see the note above. `booting`
        // rather than `booted`, because upstream reads all of this while it
        // boots and this provider boots after it.
        $this->app->booting(function (): void {
            $this->applyOpenTelemetryConfig();
        });
    }

    /**
     * Write the derived `opentelemetry.*` keys.
     *
     * A host that answered a key through the environment keeps its answer, the
     * same rule {@see applyNightwatchConfig()} follows — with **two exceptions,
     * and they are not tidiness**. Upstream writes `OTEL_SERVICE_NAME` and
     * `OTEL_SDK_DISABLED` into the environment repository itself, during its
     * own `register()`, from whatever its config said. Both variables are
     * therefore always set by the time this runs and their presence says
     * nothing at all about what the host asked for — the same shape as the
     * `$config->has()` trap on the Nightwatch side. So those two are decided
     * from evidence that survives: the published config file (handled by the
     * caller) and, for the SDK switch, the value upstream's own config
     * resolved to before Prism touched it.
     */
    private function applyOpenTelemetryConfig(): void
    {
        $config = $this->app['config'];

        $enabled = (bool) $config->get('prism.enabled')
            && (bool) $config->get('prism.otel.enabled', true);

        $derived = [
            // No OTLP anywhere. `null` is upstream's own name for its Noop
            // exporters, so nothing is constructed that could open a socket.
            'traces.exporter' => [Variables::OTEL_TRACES_EXPORTER, 'null'],
            'metrics.exporter' => [Variables::OTEL_METRICS_EXPORTER, 'null'],
            'logs.exporter' => [Variables::OTEL_LOGS_EXPORTER, 'null'],
            'service_instance_id' => ['OTEL_SERVICE_INSTANCE_ID', (string) $config->get('prism.replica')],
            // Upstream defaults this to false, which batches one job's spans
            // into the next job's flush.
            'worker_mode.flush_after_each_iteration' => ['OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION', true],
            // Tail sampling: the keep-or-drop decision is deferred until the
            // trace has finished, so a request that turned out to be slow or
            // that recorded an error anywhere in its tree is kept WHOLE
            // (US-019). See {@see tailSamplingRules()}; `decision_wait` stays
            // at upstream's own default because {@see SpanFlush} force-flushes
            // the tracer at the end of every execution, which is what stops a
            // held trace outliving the batch it belongs in.
            'traces.sampler.tail_sampling.enabled' => ['OTEL_TRACES_TAIL_SAMPLING_ENABLED', true],
            'traces.sampler.tail_sampling.rules' => [null, $this->tailSamplingRules($config)],
        ];

        foreach (self::OTEL_INSTRUMENTATION as $class => [$variable, $on]) {
            $derived['instrumentation.'.$class] = [
                $variable,
                // Some entries carry options (excluded paths, allowed headers)
                // and some are a bare boolean; a host's options are kept and
                // only the verdict is written.
                is_array($current = $config->get('opentelemetry.instrumentation.'.$class))
                    ? array_merge($current, ['enabled' => $on])
                    : $on,
            ];
        }

        foreach ($derived as $key => [$variable, $value]) {
            // The host answered this one itself; Prism does not argue with it.
            // A null variable means there is no single question to ask — the
            // tail-sampling rules carry one escape hatch per rule and answer
            // them in {@see tailSamplingRules()} instead.
            if ($variable !== null && Env::get($variable) !== null) {
                continue;
            }

            $config->set("opentelemetry.{$key}", $value);
        }

        // A span's resource has to name the same process the batch envelope
        // does. Blank means an install that has not been configured yet, and
        // an empty service name is worse than upstream's guess.
        if (filled($application = $config->get('prism.app'))) {
            $config->set('opentelemetry.service_name', (string) $application);
            $this->putOpenTelemetryEnvironment(Variables::OTEL_SERVICE_NAME, (string) $application);
        }

        // Merged rather than replaced: resource attributes are additive, so
        // whatever the host put in OTEL_RESOURCE_ATTRIBUTES survives alongside
        // the one dimension Prism has to add.
        $config->set('opentelemetry.resource_attributes', array_merge(
            (array) $config->get('opentelemetry.resource_attributes', []),
            ['deployment.environment' => (string) $config->get('prism.environment')],
        ));

        $this->registerSpanProcessor($config, $enabled);

        if (! $this->otelDisabledByHost) {
            $config->set('opentelemetry.disabled', ! $enabled);

            // The config key alone is read by nobody: `Sdk::isDisabled()` reads
            // the environment variable, and upstream stamped it during its own
            // register() from a config value that predates this decision.
            $this->putOpenTelemetryEnvironment(Variables::OTEL_SDK_DISABLED, $enabled ? 'false' : 'true');
        }
    }

    /**
     * The tail-sampling rules the span lane runs with (US-019).
     *
     * **This is the client-side half of a rule Prism already enforces on the
     * server**, and the two are belt and braces rather than duplicates: the
     * server's sampler keeps a slow or errored trace once it has arrived, and
     * this keeps it from being dropped before it is sent at all. Neither knows
     * about the other and neither is being changed by the other's existence.
     *
     * Two rules, both upstream's own implementations:
     *
     *   - **`ErrorsRule`** — any span in the trace whose status is `ERROR`
     *     keeps the whole trace. That covers every exception the host reports,
     *     because upstream's `reportable()` hook sets exactly that status on
     *     the active span.
     *   - **`SlowTraceRule`** — a trace at or beyond `prism.otel.slow_trace_ms`
     *     ({@see slowTraceThreshold()}) keeps the whole trace.
     *
     * Each rule keeps its own upstream environment escape hatch, so a host that
     * answered `OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS` or
     * `OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES` keeps its answer and any
     * rule it added itself is merged rather than replaced. That is why this
     * cannot ride the `$derived` loop, which asks one variable per key.
     *
     * @return array<class-string<TailSamplingRuleInterface>|string, mixed>
     */
    private function tailSamplingRules(Repository $config): array
    {
        /** @var mixed $existing */
        $existing = $config->get('opentelemetry.traces.sampler.tail_sampling.rules', []);

        /** @var array<class-string<TailSamplingRuleInterface>|string, mixed> $rules */
        $rules = is_array($existing) ? $existing : [];

        if (Env::get('OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS') === null) {
            $rules[ErrorsRule::class] = true;
        }

        if (Env::get('OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES') === null) {
            $rules[SlowTraceRule::class] = [
                'enabled' => true,
                'threshold_ms' => $this->slowTraceThreshold($config),
            ];
        }

        return $rules;
    }

    /**
     * How slow a trace has to be to be kept whole, in milliseconds.
     *
     * `PRISM_SLOW_TRACE_MS` is the variable behind it, through
     * `prism.otel.slow_trace_ms`, with upstream's own
     * `OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS` still honoured as
     * the escape hatch for a host that would rather answer OpenTelemetry
     * directly.
     *
     * **An unreadable value falls back to the documented default, never to
     * zero.** `(int) null` is 0 and a threshold of zero reads as "every trace
     * is slow", which quietly turns tail sampling into "keep everything" — the
     * same trap `sampleRate()` guards one config block over, in the other
     * direction.
     */
    private function slowTraceThreshold(Repository $config): int
    {
        /** @var mixed $override */
        $override = Env::get('OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS');

        /** @var mixed $threshold */
        $threshold = is_numeric($override)
            ? $override
            : $config->get('prism.otel.slow_trace_ms');

        return is_numeric($threshold) && (int) $threshold > 0
            ? (int) $threshold
            : self::DEFAULT_SLOW_TRACE_MS;
    }

    /**
     * Add {@see PrismSpanProcessor} to upstream's own `traces.processors` slot
     * (US-015) — the span lane's whole wiring, and one line of config.
     *
     * Through the slot rather than by replacing the `TracerProvider`: the
     * provider `keepsuit/…` builds carries the resource that names this
     * application, the sampler and the propagators, and owning a second one
     * would mean owning all of that and quietly taking the tracer away from a
     * host that later wants its own. The processor sits *beside* the batch
     * processor upstream always installs, which US-014 has already pointed at
     * a Noop exporter, so nothing leaves the process either way.
     *
     * Written from here rather than from `registerCapture()` because upstream
     * reads this key while it boots and Prism boots after it — the same
     * ordering that makes the whole derivation a `register()` + `booting()`
     * affair. Idempotent, because it runs twice.
     *
     * The token is part of the condition, not an afterthought: an enabled
     * install with no token never reaches `registerCapture()`, so nothing binds
     * the buffer the processor writes into and nothing ships what it collects.
     * Registering it there would fill a buffer no flush will ever drain.
     */
    private function registerSpanProcessor(Repository $config, bool $enabled): void
    {
        if (! $enabled || blank($config->get('prism.token'))) {
            return;
        }

        $processors = $config->get('opentelemetry.traces.processors', []);
        $processors = is_array($processors) ? array_values($processors) : [];

        if (in_array(PrismSpanProcessor::class, $processors, true)) {
            return;
        }

        $processors[] = PrismSpanProcessor::class;

        $config->set('opentelemetry.traces.processors', $processors);
    }

    /**
     * Write an environment variable the OpenTelemetry SDK will actually read.
     *
     * **`Env::getRepository()->set()` cannot be used for this**, and its
     * failure is silent: Laravel's repository wraps phpdotenv's
     * `ImmutableWriter`, which refuses — returning `false`, throwing nothing —
     * to overwrite any variable it did not write itself. Upstream stamps
     * `OTEL_SDK_DISABLED` and `OTEL_SERVICE_NAME` during its own `register()`,
     * and a long-lived process (a test suite, an Octane worker) can outlive the
     * repository instance that recorded those writes — after which every write
     * from here is dropped. What is left is a config key saying the SDK is off
     * and an SDK that is on: `prism.otel.enabled=false` captures spans anyway,
     * and `PRISM_ENABLED=false` builds the whole SDK in every process.
     *
     * `Configuration`'s environment resolver reads `getenv()` first and
     * `$_SERVER` second, so both are written — and `$_ENV` with them, so
     * Laravel's own `env()` agrees with what the SDK sees.
     */
    private function putOpenTelemetryEnvironment(string $name, string $value): void
    {
        putenv("{$name}={$value}");

        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/prism.php' => $this->app->configPath('prism.php'),
            ], 'prism-config');

            // Registered unconditionally, before the enabled/token gates below,
            // so `prism:check` can diagnose a disabled or unconfigured install —
            // that is exactly when a user reaches for it (US-052).
            //
            // `prism:bench:capture` is registered on the same footing and for a
            // sharper version of the same reason (US-023): it measures the cost
            // of capture under four configurations, three of which have it
            // switched off or replaced, and it spawns this very command with
            // `PRISM_ENABLED=false` to do so. A command available only to an
            // enabled install could not measure a disabled one.
            $this->commands([CheckCommand::class, BenchCaptureCommand::class]);
        }

        // Master switch off: register nothing. Zero overhead, no warning —
        // a deliberate opt-out is not a misconfiguration.
        if (! $this->app['config']->get('prism.enabled')) {
            return;
        }

        // Enabled but no token: no-op and surface the reason exactly once,
        // never throwing, so the host app boots normally.
        if (blank($this->app['config']->get('prism.token'))) {
            Log::warning(
                'Prism is enabled but PRISM_TOKEN is not set — telemetry capture is disabled. '
                .'Set PRISM_TOKEN (and PRISM_APP) to enable it, or PRISM_ENABLED=false to silence this warning.'
            );

            return;
        }

        $this->registerCapture();
    }

    /**
     * Wire the capture pipeline. Reached only when the client is enabled and
     * a token is configured, so everything registered here is skipped for a
     * disabled or unconfigured install.
     *
     * **There are no per-domain capture listeners here any more** (US-021).
     * Every signal Prism used to watch for itself — requests, exceptions,
     * queries, cache reads, outgoing calls, jobs and scheduled tasks — is the
     * capture engine's now, and spans are the OpenTelemetry lane's, so what is
     * left is the pipeline those two feed: the buffer they write into, the
     * transport and spool that ship it, the scrubber and reject rules that
     * answer `prism.scrub` and `prism.ignore.*` in their vocabulary, the ingest
     * swap that puts Prism's pipeline behind the engine, and the one signal
     * neither engine has — `replica_metric` (US-013).
     *
     * The one exception is {@see registerLogCapture()}, and it is an exception
     * because upstream leaves that wiring to the host: the engine registers a
     * `nightwatch` log channel and captures nothing until someone adds it to
     * their stack. Attaching it is still Prism's, so an install that only ever
     * set `PRISM_TOKEN` still gets its logs.
     */
    protected function registerCapture(): void
    {
        $this->app->instance(self::ACTIVE, true);

        // One in-memory buffer per process collects every captured event until
        // flush (US-037). Its capacity comes from config so a host can bound
        // per-request memory; a singleton so every listener shares it.
        $this->app->singleton(EventBuffer::class, static function ($app): EventBuffer {
            return new EventBuffer((int) $app['config']->get('prism.batch.size', 1000));
        });

        // One transport per process (US-038). A singleton so its Guzzle client
        // is built once and its keep-alive connection pool is reused across
        // every flush a long-lived runtime (Octane, a worker) performs.
        $this->app->singleton(Transport::class, static function ($app): HttpTransport {
            return new HttpTransport($app['config']);
        });

        // The cache-backed hand-off behind the `spool` flush strategy (US-038):
        // a terminal flush parks its finished batch here and one debounced drain
        // job ships everything at once, so no request ever waits on the ingest
        // host. Singletons because both are stateless readers of config and the
        // cache — the coordination lives in the store's atomic locks, not in the
        // objects, so they are safe to share across a long-lived process.
        $this->app->singleton(BatchSpool::class, static function ($app): BatchSpool {
            return new BatchSpool($app->make(CacheFactory::class), $app['config']);
        });

        $this->app->singleton(SpoolScheduler::class, static function ($app): SpoolScheduler {
            return new SpoolScheduler($app->make(CacheFactory::class), $app['config']);
        });

        // The redactor `prism.scrub` is expressed through (US-040). It is no
        // longer a listener's collaborator — the capture engine owns every
        // signal now — but it is still the one implementation of the rule, and
        // {@see RedactRules} resolves it out of the container to answer
        // upstream's `redact*` callbacks with (US-011).
        $this->app->singleton(Scrubber::class, static function ($app): Scrubber {
            $keys = $app['config']->get('prism.scrub', []);

            return new Scrubber(is_iterable($keys) ? $keys : []);
        });

        // Filtering FIRST: the ingest's own singleton reads `RejectRules` out of
        // the container, and `installPrismIngest()` fires at once when the app
        // is already booted (a re-boot, an Octane worker), which would otherwise
        // build an ingest holding whatever rules the previous boot left.
        $this->registerNightwatchFiltering();
        $this->registerNightwatchRedaction();
        $this->registerNightwatchIngest();
        $this->registerLogCapture();
        $this->registerQueueMetrics();
        $this->registerSystemMetrics();

        // A queue worker handles many jobs in one long-lived process. Clear the
        // buffer as each job begins so one job's events never leak into the
        // next (US-037) — independent of whether the previous job flushed. Also
        // reset the recursion guard so a suppression scope left unbalanced by a
        // fatal in a prior job cannot wedge capture off (US-039), and re-anchor
        // the trace context so one job's fallback id never bleeds into the next
        // (US-041). The trace a job actually runs under comes from the
        // `traceparent` the span lane wrote into its payload at dispatch
        // (US-020), which the lane's own CONSUMER span re-opens here; this only
        // decides what a host with the OpenTelemetry SDK switched off falls back
        // to.
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event): void {
            TraceContext::start();
            Recursion::reset();
            $this->app->make(EventBuffer::class)->clear();
        });

        // Under Octane the app is not rebuilt between requests, so the buffer
        // singleton survives. Reset it, the recursion guard and the trace
        // context at the start of every request so one request's events can
        // never leak into the next (US-038/US-039/US-041); the next read of the
        // trace context establishes a fresh fallback id for the incoming
        // request. Listening by the event's class name means no hard dependency
        // on Octane — the listener simply never fires when Octane is not
        // installed.
        $this->app['events']->listen('Laravel\Octane\Events\RequestReceived', function (): void {
            TraceContext::reset();
            Recursion::reset();
            $this->app->make(EventBuffer::class)->clear();
        });

        // Ship the buffer after the response has been sent to the client, off
        // the request's critical path (US-038). Wrapped so a flush failure is
        // caught and never surfaces to the host application.
        $this->app->terminating(function (): void {
            $this->flush();
        });
    }

    /**
     * Install Prism's pipeline over Nightwatch's socket ingest (US-004).
     *
     * `Core::$ingest` is the one object every Nightwatch sensor hands its
     * records to, and out of the box it is the socket implementation that
     * writes them to the `nightwatch:agent` daemon over TCP. Assigning
     * {@see PrismIngest} over it is what makes the whole capture engine run
     * in-process: the records go into the buffer Prism has always used and
     * leave over the transport Prism has always used, and the agent phar is
     * never constructed, never called and never reached. That is a property
     * `NightwatchIngestSwapTest` asserts with a stream factory that fails the
     * test if anything opens a socket — "it seems to work" would not do here,
     * because a socket to a daemon that is not running fails silently in
     * exactly the same way a working install looks.
     *
     * Four details:
     *
     *   - **The swap happens in `booted`, not here.** Nightwatch binds `Core`
     *     during its own `register()` (before its enabled check, so the binding
     *     exists even for a disabled Nightwatch), and its provider holds that
     *     same object — it handed it to the logger, the middleware, the sampler
     *     and every hook — so mutating the property propagates everywhere with
     *     no rebinding. Waiting until every provider has booted means the swap
     *     happens after Nightwatch has finished wiring, whatever order the two
     *     registered in.
     *
     *   - **It is reached only from `registerCapture()`**, so a disabled or
     *     token-less Prism leaves Nightwatch's own ingest exactly where it was.
     *     Prism does not half-install itself.
     *
     *   - **The flusher arrives as a factory**, because {@see PrismIngest::writeNow()}
     *     ships a one-record batch through a buffer of its own; see that class.
     *     Every dependency is resolved inside the closure, at flush time, so the
     *     ingest holds no stale transport in a long-lived runtime.
     *
     *   - **The execution id is a resolver, not a value.** A `request` record has
     *     no `execution_id` of its own — it *is* the execution — so its Prism
     *     event would carry a blank `request_id` and the request-detail screen
     *     would never find its own children. Only the swap site can fix that,
     *     because only here is the `Core` in hand; and it has to be read per
     *     record rather than captured once, because a queue or Octane worker
     *     hands one `Core` many executions in a row.
     */
    protected function registerNightwatchIngest(): void
    {
        $this->app->singleton(PrismIngest::class, static function ($app): PrismIngest {
            return new PrismIngest(
                $app->make(EventBuffer::class),
                $app->make(RecordTranslator::class),
                static fn (EventBuffer $buffer): Flusher => new Flusher(
                    $app['config'],
                    $buffer,
                    $app->make(Transport::class),
                    $app->make(BatchSpool::class),
                    $app->make(SpoolScheduler::class),
                    $app->make(SpanFlush::class),
                ),
                static function () use ($app): string {
                    if (! $app->bound(Core::class)) {
                        return '';
                    }

                    /** @var Core<RequestState|CommandState> $core */
                    $core = $app->make(Core::class);

                    return $core->executionState->id;
                },
                $app->make(RejectRules::class),
                $app->make(SpanLineage::class),
                $app->make(SpanLane::class),
            );
        });

        $this->app->booted(function (): void {
            $this->installPrismIngest();
        });
    }

    /**
     * Assign {@see PrismIngest} over `Core::$ingest`, if there is a `Core` to
     * assign it to.
     *
     * Nothing bound means `laravel/nightwatch` is not installed at all — an
     * odd state, since the package requires it, but a composer tree is not
     * something to take the host application down over. Neither is a shape
     * change upstream: `Core::$ingest` is marked `@internal`, which is the
     * risk US-022 pins loudly rather than one a request should discover.
     */
    private function installPrismIngest(): void
    {
        if (! $this->app->bound(Core::class)) {
            return;
        }

        try {
            /** @var Core<RequestState|CommandState> $core */
            $core = $this->app->make(Core::class);

            $core->ingest = $this->app->make(PrismIngest::class);
        } catch (Throwable) {
            // See reconcileNightwatchCore(): a dependency's shape is not a
            // contract with the host, and US-022 is where drift is caught.
        }
    }

    /**
     * Say `prism.ignore.*` in the capture engine's vocabulary (US-010).
     *
     * The lists a host writes have not changed; the engine reading them has.
     * `laravel/nightwatch` offers a reject callback per record type, no hook at
     * all for an execution, and none for an exception — so the six dimensions
     * land in three different shapes, all of them assembled by
     * {@see RejectRules} and none of them by a call site of its own.
     *
     *   - `cache`, `http`, `jobs` → **rejected outright.** Nightwatch never
     *     buffers what a reject callback refuses, so an ignored signal costs
     *     nothing. That the record's per-execution counter goes with it is the
     *     point: a cache read nobody wanted to see should not appear as a
     *     figure on the request that made it either. (This is deliberately the
     *     opposite of US-018's stance for a *superseded* record, which has to
     *     be counted before it is dropped.)
     *   - `paths`, `commands` — and an ignored job's *attempt* — → **sampled
     *     out**, because a request, a command and a worker's run of a job each
     *     *are* the execution rather than a record inside one, and
     *     `Core::dontSample()` is the only refusal available at that level. It
     *     is also the stronger one: the whole buffer is discarded, not just the
     *     row, which for the ingest pipeline's own job is most of what there
     *     was to silence. All three hooks must run **after** Nightwatch's own —
     *     its global middleware, its `CommandStarting` listener and its
     *     worker-lifecycle listener each call `sample()` — which is why the
     *     middleware is pushed rather than prepended and why both listeners
     *     here are registered at boot, behind the ones upstream registered
     *     during its own `register()`.
     *   - `exceptions` → **dropped at the seam**, in {@see PrismIngest}: there
     *     is no reject callback for an exception, so the earliest thing Prism
     *     owns is its own ingest. See {@see RejectRules} for why that is an
     *     honest place rather than a lazy one.
     *
     * `captureDefaultVendorCacheKeys()` is left at its default, so Nightwatch's
     * own vendor key list keeps applying on top of Prism's — a host silencing
     * `prism:*` did not thereby ask to start capturing `telescope:*`.
     */
    protected function registerNightwatchFiltering(): void
    {
        $rules = RejectRules::fromConfig($this->app['config']);

        $this->app->instance(RejectRules::class, $rules);

        $kernel = $this->app->make(HttpKernelContract::class);

        if ($kernel instanceof FoundationHttpKernel) {
            // PUSHED, not prepended: Nightwatch prepends its own global
            // middleware, whose handle() re-samples the execution, so a
            // dontSample() made ahead of it would be silently undone.
            $kernel->pushMiddleware(RejectIgnoredRequests::class);
        }

        // An ignored command is an execution too, and the same ordering rule
        // applies — Nightwatch's CommandStarting listener was registered during
        // its own register(), so this one runs after it.
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) use ($rules): void {
            if ($rules->rejectsCommand((string) $event->command)) {
                $this->dontSampleExecution();
            }
        });

        // So is a job attempt: `prepareForJob()` resets the execution state and
        // re-samples on this very event, which is also what makes an ignored
        // job's whole run — its queries, its cache reads, the ClickHouse write
        // it performs — go with the record rather than only the record. Same
        // ordering rule again; Nightwatch's worker-lifecycle listener is
        // registered when `queue:work` starts, ahead of this one firing.
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use ($rules): void {
            if ($rules->rejectsJob($event->job->resolveName())) {
                $this->dontSampleExecution();
            }
        });

        if (! $this->app->bound(Core::class)) {
            return;
        }

        try {
            /** @var Core<RequestState|CommandState> $core */
            $core = $this->app->make(Core::class);

            $core->rejectCacheKeys($rules->cacheKeyPatterns());

            $core->rejectOutgoingRequests(
                static fn (OutgoingRequest $record): bool => $rules->rejectsCurrentWork()
                    || $rules->rejectsOutgoingRequest($record),
            );

            $core->rejectQueuedJobs(
                static fn (QueuedJob $record): bool => $rules->rejectsCurrentWork()
                    || $rules->rejectsQueuedJob($record),
            );

            // {@see Recursion} is what keeps the package from capturing its own
            // work, and the engine's sensors have no idea it exists — so every
            // signal that offers a reject callback gets asked. Shipping a batch
            // is a cache write, a queue dispatch and an HTTP call; captured,
            // each of them is the contents of the next batch.
            $core->rejectCacheEvents(static fn (): bool => $rules->rejectsCurrentWork());
            $core->rejectQueries(static fn (): bool => $rules->rejectsCurrentWork());
            $core->rejectMail(static fn (): bool => $rules->rejectsCurrentWork());
            $core->rejectNotifications(static fn (): bool => $rules->rejectsCurrentWork());
        } catch (Throwable) {
            // See installPrismIngest(): a dependency's shape is not a contract
            // with the host, and US-022 is where drift is caught loudly.
        }
    }

    /**
     * Say `prism.scrub` in the capture engine's vocabulary (US-011).
     *
     * One list still governs every signal; what changed is that the structures
     * carrying a secret are now typed records built by someone else's sensors,
     * and the hook for rewriting one is a `redact*` callback per record type.
     * {@see RedactRules} holds every rule, so a value that reached the console
     * and should not have is traced to one file rather than to seven call
     * sites that each guessed.
     *
     * These are not the reject callbacks' cousins, and the difference matters:
     * a reject **short-circuits** (the record is never built, and its
     * per-execution counter goes with it — US-010 wants exactly that), while a
     * redact **always runs and mutates**. Nothing is dropped here, so no figure
     * on any screen moves; only the field holding the secret changes.
     *
     * Registration is at boot for the ordinary reason — Prism's `registerCapture()`
     * is the enabled+token path — and every callback is a closure over one
     * resolved rule set, so nothing re-reads config while a request is served.
     *
     * There is no callback for a job. Nightwatch's `queued-job` and
     * `job-attempt` records carry the job's name, id, queue, connection and
     * outcome and nothing else — a dispatched payload never reaches a record at
     * all — so that dimension is covered by absence, which
     * `NightwatchRedactRulesTest` asserts rather than assumes.
     */
    protected function registerNightwatchRedaction(): void
    {
        $rules = RedactRules::fromConfig($this->app['config']);

        $this->app->instance(RedactRules::class, $rules);

        if (! $this->app->bound(Core::class)) {
            return;
        }

        try {
            /** @var Core<RequestState|CommandState> $core */
            $core = $this->app->make(Core::class);

            // The request is covered twice on purpose. Upstream's own sensor
            // blanks whatever reached `redact_payload_fields` / `redact_headers`
            // — which Prism derives from the same list — but those are read once
            // during Nightwatch's `register()`, so which of the two halves is
            // load-bearing depends on an install's provider order. This half
            // depends on nothing, and it is the stricter of the two: it matches
            // a field name case-insensitively where upstream compares exactly.
            // Each callback is declared `callable(X): bool` upstream and its
            // return value is discarded — a redaction has nothing to report,
            // it either happened or the record has no such field. The `true`
            // is there to satisfy the signature, not to mean anything.
            $core->redactRequests(static function (RequestRecord $record) use ($rules): bool {
                $rules->redactRequest($record);

                return true;
            });

            $core->redactQueries(static function (QueryRecord $record) use ($rules): bool {
                $rules->redactQuery($record);

                return true;
            });

            $core->redactOutgoingRequests(static function (OutgoingRequest $record) use ($rules): bool {
                $rules->redactOutgoingRequest($record);

                return true;
            });

            $core->redactCacheEvents(static function (CacheEventRecord $record) use ($rules): bool {
                $rules->redactCacheEvent($record);

                return true;
            });

            $core->redactMail(static function (MailRecord $record) use ($rules): bool {
                $rules->redactMail($record);

                return true;
            });

            $core->redactCommands(static function (CommandRecord $record) use ($rules): bool {
                $rules->redactCommand($record);

                return true;
            });

            // Not in the AC's list of six, and registered anyway: an exception
            // message is free text a host does not control (a driver quoting a
            // DSN, a validation library echoing input), and it is the one field
            // of a record that routinely repeats whatever was passed in. Safe
            // to rewrite because nothing groups on it — see {@see RedactRules::redactException()}.
            $core->redactExceptions(static function (ExceptionRecord $record) use ($rules): bool {
                $rules->redactException($record);

                return true;
            });
        } catch (Throwable) {
            // See installPrismIngest(): a dependency's shape is not a contract
            // with the host, and US-022 is where drift is caught loudly.
        }
    }

    /**
     * Tell the capture engine to discard the execution in progress.
     *
     * The refusal available for a request, a command or a job attempt, none of
     * which is a record a reject callback could decline. Guarded and swallowed
     * exactly as {@see installPrismIngest()} is, for the same reason.
     */
    private function dontSampleExecution(): void
    {
        if (! $this->app->bound(Core::class)) {
            return;
        }

        try {
            /** @var Core<RequestState|CommandState> $core */
            $core = $this->app->make(Core::class);

            $core->dontSample();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Route the host's log channels into the capture engine (US-044, rewired by
     * US-021) without a `config/logging.php` edit.
     *
     * **This is the one capture wiring that survived the engine replacement,
     * and it survived because upstream does not do it.** Every other signal the
     * engine watches, it hooks itself; logs are the exception — Nightwatch
     * registers a `nightwatch` *channel* and leaves it to the host to add that
     * channel to its stack. An install that does not know to do so captures no
     * logs at all, silently, which is exactly the failure this method has always
     * existed to prevent. So Prism keeps attaching a handler to the channels
     * `prism.log.channels` names (the app's default stack when the list is
     * empty) — it is simply the engine's handler now rather than one of Prism's.
     *
     * The handler is built by invoking the engine's own logger factory rather
     * than by resolving the `nightwatch` channel, for the provider-ordering
     * reason that runs through this whole file: upstream reads
     * `filtering.log_level` while it registers, long before Prism could write
     * it, so a derived config key would be read by nobody and `prism.log.level`
     * would silently do nothing. Invoking the factory here passes the level
     * directly, whatever order the two providers registered in.
     *
     * The handler bubbles (its `handle()` returns false), so the channel's
     * existing destinations keep receiving every record; Prism only mirrors
     * them. A host that HAS followed upstream's instructions and put
     * `nightwatch` in its stack is covered too: Laravel builds a stack channel
     * out of its children's handler instances, so the strip below finds the
     * engine's handler already there and replaces it rather than doubling every
     * line.
     *
     * `prism.capture.logs` is an undeclared escape hatch — the shipped config
     * does not carry it, and absent it defaults to on. It is here for the same
     * reason `prism.capture.metrics` is, one method along: log attachment and
     * replica metrics are the two signals Prism produces itself rather than
     * reads off an engine, so they are the two a host may want off without
     * switching the whole client off.
     */
    protected function registerLogCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.logs', true)) {
            return;
        }

        $level = $this->app['config']->get('prism.log.level', 'debug');
        $handlers = $this->nightwatchLogHandlers(is_string($level) ? $level : 'debug');

        if ($handlers === []) {
            return;
        }

        $log = $this->app->make('log');

        foreach ($this->configuredLogChannels() as $name) {
            $this->attachLogHandlers($name === null ? $log->channel() : $log->channel($name), $handlers);
        }
    }

    /**
     * The capture engine's Monolog handlers for a given minimum level, or an
     * empty list when the engine is not installed the way this expects.
     *
     * Everything here is upstream `@internal` surface — the factory, the handler
     * class — which is the same footing as the ingest swap and is pinned by the
     * upstream contract test. A throw is answered with no handlers rather than
     * with a boot failure: losing log capture is a gap in one signal, where a
     * fatal in a service provider is the host's whole application.
     *
     * @return list<HandlerInterface>
     */
    private function nightwatchLogHandlers(string $level): array
    {
        try {
            $logger = ($this->app->make(NightwatchLoggerFactory::class))(['level' => $level]);
        } catch (Throwable) {
            return [];
        }

        return $logger instanceof MonologLogger ? array_values($logger->getHandlers()) : [];
    }

    /**
     * The logging channels to capture: the names configured under
     * `prism.log.channels`, or `[null]` (the app's default channel/stack) when
     * none is set.
     *
     * @return list<string|null>
     */
    private function configuredLogChannels(): array
    {
        $configured = $this->app['config']->get('prism.log.channels', []);

        if (! is_array($configured) || $configured === []) {
            return [null];
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    /**
     * Push the handlers onto a channel's Monolog logger, first stripping any
     * handler of the same class the channel already carries — whether an earlier
     * boot pushed it or the host put `nightwatch` in its own stack — so a line
     * is never captured twice. A channel whose logger is not Monolog is skipped.
     *
     * @param  list<HandlerInterface>  $handlers
     */
    private function attachLogHandlers(mixed $channel, array $handlers): void
    {
        if (! is_object($channel) || ! method_exists($channel, 'getLogger')) {
            return;
        }

        $monolog = $channel->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $classes = array_map(static fn (HandlerInterface $handler): string => $handler::class, $handlers);

        $monolog->setHandlers(array_values(array_filter(
            $monolog->getHandlers(),
            static fn (HandlerInterface $existing): bool => ! in_array($existing::class, $classes, true),
        )));

        foreach ($handlers as $handler) {
            $monolog->pushHandler($handler);
        }
    }

    /**
     * Wire queue-depth and worker polling (US-048).
     *
     * Queue depth and worker counts are point-in-time facts about the queue
     * backend, not per-event telemetry, so the {@see QueueMetrics} collector
     * samples them on an interval; {@see flush()} calls it at the end of an
     * execution and all but one call per interval does nothing (AC1). A due
     * sample is shipped with `writeNow()` rather than buffered — see
     * {@see nightwatchIngest()}.
     *
     * Polling is bound only on a process that actually works the queue: a web
     * replica that dispatches jobs but never processes them returns here before
     * binding anything, so it registers no polling and pays nothing (AC4). The
     * per-domain `prism.capture.metrics` toggle gates the whole thing.
     */
    protected function registerQueueMetrics(): void
    {
        if (! $this->app['config']->get('prism.capture.metrics', true)) {
            return;
        }

        // AC4: only a queue worker polls the backend. Nothing is bound on a web
        // replica, so the collect() call in flush() short-circuits there too.
        if (! Runtime::isQueueWorker()) {
            return;
        }

        $this->app->singleton(QueueMetrics::class, function ($app): QueueMetrics {
            $interval = $app['config']->get('prism.metrics.queue_interval', 30);

            return new QueueMetrics(
                fn (): ?Ingest => $this->nightwatchIngest(),
                $app,
                is_numeric($interval) ? (int) $interval : 30,
            );
        });
    }

    /**
     * Wire replica health reporting (US-051).
     *
     * CPU load, memory pressure and uptime are point-in-time facts about the
     * instance itself, so — like queue polling — the {@see SystemMetrics}
     * collector samples them on an interval (default 60s); {@see flush()} calls
     * it at the end of an execution and all but one call per interval does
     * nothing (AC1). A due sample is shipped with `writeNow()` rather than
     * buffered — see {@see nightwatchIngest()}.
     *
     * Unlike queue polling this runs on every replica — a web front-end's health
     * matters as much as a worker's — so it is bound unconditionally, gated only
     * by the same `prism.capture.metrics` toggle.
     *
     * The replica type reported with each sample is inferred from the process
     * (`worker` on a queue worker, else `web`) and can be overridden with
     * `prism.metrics.replica_type` / `PRISM_REPLICA_TYPE` (AC3). The replica name
     * itself defaults to the hostname and is overridable via `PRISM_REPLICA`
     * (AC2) — resolved from config here and carried on the wire envelope by the
     * flusher.
     */
    protected function registerSystemMetrics(): void
    {
        if (! $this->app['config']->get('prism.capture.metrics', true)) {
            return;
        }

        $this->app->singleton(SystemMetrics::class, function ($app): SystemMetrics {
            $interval = $app['config']->get('prism.metrics.system_interval', 60);
            $configuredType = $app['config']->get('prism.metrics.replica_type');

            $type = is_string($configuredType) && $configuredType !== ''
                ? $configuredType
                : (Runtime::isQueueWorker() ? 'worker' : 'web');

            return new SystemMetrics(
                fn (): ?Ingest => $this->nightwatchIngest(),
                $app,
                (string) $app['config']->get('prism.replica', 'unknown'),
                $type,
                is_numeric($interval) ? (int) $interval : 60,
            );
        });
    }

    /**
     * Drain and ship the buffer, swallowing any failure. A telemetry flush must
     * never propagate an error into the host application it monitors (US-038);
     * the failure is logged at debug level and otherwise ignored.
     *
     * The whole flush runs inside a {@see Recursion::suppress()} scope, so any
     * log line or exception the drain, envelope build or inline send emits is
     * recognised as the package's own and never captured — otherwise shipping a
     * batch would generate the very events the next flush ships (US-039). The
     * failure log below is inside the scope for the same reason.
     *
     * **THIS RUNS BEFORE THE CAPTURE ENGINE HAS DECIDED** (US-024), which is
     * why {@see discardRejectedExecution()} comes first. `Kernel::terminate()`
     * is `terminateMiddleware()` → `$app->terminate()` → the request-lifecycle
     * handlers, and it is the last of those three that calls
     * `Core::finishExecution()` — so this callback, registered on the second,
     * would otherwise ship an execution the engine is about to discard. The
     * request row would be missing (the engine writes it in that handler) while
     * every span, query, cache event and log line the execution produced went
     * out anyway: `prism.ignore.paths` half-applied, in the one install where
     * the traffic being ignored is the telemetry pipeline itself.
     */
    protected function flush(): void
    {
        $this->collectQueueMetrics();
        $this->collectSystemMetrics();

        Recursion::suppress(function (): void {
            try {
                $this->discardRejectedExecution();

                $this->app->make(Flusher::class)->flush();
            } catch (Throwable $e) {
                Log::debug('Prism flush failed: '.$e->getMessage());
            }
        });
    }

    /**
     * Empty the buffer when the capture engine has already refused the
     * execution this flush belongs to.
     *
     * `Core::finishExecution()` is `sampling ? digest() : flush()`, and on
     * {@see PrismIngest} `flush()` means DISCARD — an ignored path, an ignored
     * command, a worker's run of an ignored job or an execution the
     * `prism.sample.*` rate rejected takes its whole buffer with it. That
     * discard is the whole strength of the ignore lists under this engine: an
     * ignored request costs nothing rather than merely losing its own row.
     *
     * The buffer is cleared rather than the flush being skipped, so the drain
     * below still runs and still force-flushes the tracer (US-019) — the
     * flusher's own "nothing to send" early return does the rest. That keeps
     * one path through this method whatever the verdict.
     *
     * Guarded and swallowed like every other reach into `laravel/nightwatch`:
     * a shape change upstream is a dependency problem, not something a host's
     * request may be taken down by. Anything unreadable falls back to shipping,
     * because losing telemetry is the worse failure of the two.
     */
    private function discardRejectedExecution(): void
    {
        try {
            if (! $this->app->bound(Core::class)) {
                return;
            }

            /** @var Core<RequestState|CommandState> $core */
            $core = $this->app->make(Core::class);

            // Only when Prism's own ingest is installed: `sampling()` is a
            // statement about a buffer the engine is holding, and it is this
            // one exactly when the swap happened.
            if (! $core->ingest instanceof PrismIngest || $core->sampling()) {
                return;
            }

            $this->app->make(EventBuffer::class)->clear();
        } catch (Throwable) {
            //
        }
    }

    /**
     * The ingest a {@see SystemMetrics} / {@see QueueMetrics} sample ships
     * through, or null when there is none to ship through (US-013).
     *
     * Read per sample, not captured, because the ingest Prism installs is
     * assigned in an `app->booted()` callback ({@see installPrismIngest()}) and
     * both collectors are built before that runs.
     *
     * `Core::$ingest` is the source of truth — it is the object every sensor
     * writes to, so reading it is what keeps a metric on the same road as every
     * captured record even if a later story swaps the ingest again. But it is
     * used **only when it is Prism's own**: `replica_metric` is a record type
     * Prism invented for a signal Nightwatch has no sensor for, so handing it to
     * the upstream socket ingest would post a record the agent cannot read to a
     * daemon this package guarantees is not running. Anything else falls back to
     * the container's {@see PrismIngest} singleton — the same object the swap
     * would have installed.
     */
    private function nightwatchIngest(): ?Ingest
    {
        try {
            if ($this->app->bound(Core::class)) {
                /** @var Core<RequestState|CommandState> $core */
                $core = $this->app->make(Core::class);

                if ($core->ingest instanceof PrismIngest) {
                    return $core->ingest;
                }
            }

            return $this->app->make(PrismIngest::class);
        } catch (Throwable) {
            // A metric is worth less than the host's request. See
            // installPrismIngest(): a dependency's shape is not a contract.
            return null;
        }
    }

    /**
     * Take a queue-metrics sample at the end of this execution (US-048), if one
     * is due. The collector is bound only on a queue worker, so a web replica
     * never even makes the call. Run before the suppression scope below so the
     * collector's own recursion guard is not already tripped, and interval-gated
     * so most executions sample nothing. A due sample ships itself through
     * `writeNow()` (US-013) rather than joining the batch this flush is about to
     * drain — which is what keeps it alive when the execution was sampled out
     * and the batch is discarded. Best-effort: a sampling fault never blocks the
     * flush.
     */
    private function collectQueueMetrics(): void
    {
        if (! $this->app->bound(QueueMetrics::class)) {
            return;
        }

        $this->app->make(QueueMetrics::class)->collect();
    }

    /**
     * Take a replica-health sample at the end of this execution (US-051), if one
     * is due. Bound on every replica, so unlike queue metrics the call runs on
     * web front-ends too. Otherwise exactly as {@see collectQueueMetrics()}:
     * outside the suppression scope, interval-gated, shipped by `writeNow()`,
     * and never able to block the flush.
     */
    private function collectSystemMetrics(): void
    {
        if (! $this->app->bound(SystemMetrics::class)) {
            return;
        }

        $this->app->make(SystemMetrics::class)->collect();
    }
}
