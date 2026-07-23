<?php

declare(strict_types=1);

namespace Misakstvanu\Prism;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\CacheCapture;
use Misakstvanu\Prism\Capture\CaptureRequests;
use Misakstvanu\Prism\Capture\ExceptionCapture;
use Misakstvanu\Prism\Capture\HttpCapture;
use Misakstvanu\Prism\Capture\JobCapture;
use Misakstvanu\Prism\Capture\PrismLogHandler;
use Misakstvanu\Prism\Capture\QueryCapture;
use Misakstvanu\Prism\Capture\ScheduleCapture;
use Misakstvanu\Prism\Capture\SpanRecorder;
use Misakstvanu\Prism\Console\CheckCommand;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Http\Middleware\TraceRequests;
use Misakstvanu\Prism\Metrics\QueueMetrics;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\Support\IgnoreList;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Runtime;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\HttpTransport;
use Misakstvanu\Prism\Transport\Transport;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use Psr\Http\Message\RequestInterface;
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

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prism.php', 'prism');
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
            $this->commands([CheckCommand::class]);
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
     * Seam for US-037 (event buffer), US-038 (async flush) and US-042+ (the
     * per-domain listeners). It records that the client is live, wires the
     * in-memory event buffer every listener writes into, and registers the
     * flush that ships that buffer after the response is sent.
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

        // The redactor every capture listener runs its collected data through
        // before buffering (US-040). Built once from the configured scrub list
        // so a secret is stripped at the source and never leaves the process.
        $this->app->singleton(Scrubber::class, static function ($app): Scrubber {
            $keys = $app['config']->get('prism.scrub', []);

            return new Scrubber(is_iterable($keys) ? $keys : []);
        });

        // The exception capturer (US-042). One singleton feeds both the
        // automatic reportable callback below and the manual
        // Prism::captureException() entry point, so both share this buffer,
        // scrubber and ignore list. Registered unconditionally (manual capture
        // must work even if the auto listener is off); the reportable callback
        // is what the per-domain toggle gates.
        $this->app->singleton(ExceptionCapture::class, static function ($app): ExceptionCapture {
            $ignore = $app['config']->get('prism.ignore.exceptions', []);

            return new ExceptionCapture(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                array_values(is_array($ignore) ? $ignore : []),
            );
        });

        // Assembles trace spans into a parent/child waterfall (US-050). One
        // singleton so every span producer — the request lifecycle phases, the
        // cache/HTTP capturers and manual Prism::span() calls — shares the same
        // buffer and open-span stack. Bound before the request capturer, which
        // depends on it, and before the per-domain listeners it serves.
        $this->app->singleton(SpanRecorder::class, static function ($app): SpanRecorder {
            return new SpanRecorder($app->make(EventBuffer::class), $app);
        });

        $this->registerTracePropagation();
        $this->registerExceptionCapture();
        $this->registerRequestCapture();
        $this->registerLogCapture();
        $this->registerQueryCapture();
        $this->registerCacheCapture();
        $this->registerHttpCapture();
        $this->registerJobCapture();
        $this->registerScheduleCapture();
        $this->registerQueueMetrics();
        $this->registerSystemMetrics();

        // A queue worker handles many jobs in one long-lived process. Clear the
        // buffer as each job begins so one job's events never leak into the
        // next (US-037) — independent of whether the previous job flushed. Also
        // reset the recursion guard so a suppression scope left unbalanced by a
        // fatal in a prior job cannot wedge capture off (US-039), and continue
        // the originating trace stamped onto the payload at dispatch so the job
        // runs under the trace that queued it (US-041).
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event): void {
            TraceContext::start($this->payloadTraceId($event));
            Recursion::reset();
            $this->app->make(SpanRecorder::class)->reset();
            $this->app->make(EventBuffer::class)->clear();
        });

        // Under Octane the app is not rebuilt between requests, so the buffer
        // singleton survives. Reset it, the recursion guard and the trace
        // context at the start of every request so one request's events can
        // never leak into the next (US-038/US-039/US-041); the trace middleware
        // then re-establishes a fresh trace for the incoming request. Listening
        // by the event's class name means no hard dependency on Octane — the
        // listener simply never fires when Octane is not installed.
        $this->app['events']->listen('Laravel\Octane\Events\RequestReceived', function (): void {
            TraceContext::reset();
            Recursion::reset();
            $this->app->make(SpanRecorder::class)->reset();
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
     * Wire trace-context propagation across every boundary a trace crosses
     * (US-041), so all of one execution's signals share an id the console can
     * correlate. Three seams, all reached only on the enabled+token path:
     *
     *   - The {@see TraceRequests} middleware, prepended to the global stack so
     *     it runs first, starts a trace for each request and honours an incoming
     *     `X-Prism-Trace-Id` header from an upstream service (AC1/AC2).
     *   - A global HTTP-client request middleware stamps the current trace id
     *     onto every outgoing call, so a downstream service continues it (AC3).
     *     A caller that set the header itself is left untouched. The package's
     *     own ingest POST goes through Guzzle directly, not the HTTP client, so
     *     it is unaffected.
     *   - A queue payload hook stamps the originating trace id onto every
     *     dispatched job, so a job runs under the trace that queued it — read
     *     back by the `JobProcessing` listener above, even across a process
     *     boundary (AC4/AC5).
     */
    protected function registerTracePropagation(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        if ($kernel instanceof FoundationHttpKernel) {
            $kernel->prependMiddleware(TraceRequests::class);
        }

        Http::globalRequestMiddleware(static function (RequestInterface $request): RequestInterface {
            if ($request->hasHeader(TraceContext::HEADER)) {
                return $request;
            }

            return $request->withHeader(TraceContext::HEADER, TraceContext::traceId());
        });

        Queue::createPayloadUsing(static fn (): array => [
            TraceContext::JOB_PAYLOAD_KEY => TraceContext::traceId(),
        ]);
    }

    /**
     * Hook exception capture into the host's exception handler (US-042) without
     * the user editing `bootstrap/app.php`.
     *
     * A `reportable` callback is registered on the handler, so every exception
     * the host reports is also handed to Prism. The callback typehints
     * {@see Throwable} so it handles them all, and returns nothing — the handler
     * treats that as "continue", so the host's default reporting (its logging)
     * is untouched; Prism only observes.
     *
     * The per-domain `prism.capture.exceptions` toggle gates the automatic
     * listener: when off, no callback is registered at all, so a host that only
     * wants manual {@see Prism::captureException()} reporting
     * pays nothing for the auto path. `reportable()` lives on the concrete
     * foundation handler rather than the contract, so a custom handler that
     * lacks it degrades cleanly to manual capture only.
     */
    protected function registerExceptionCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.exceptions', true)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandlerContract::class);

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $e): void {
            $this->app->make(ExceptionCapture::class)->capture($e, handled: false);
        });
    }

    /**
     * Hook HTTP request capture into the host (US-043) without a kernel edit.
     *
     * The {@see CaptureRequests} middleware is appended to the `web` and `api`
     * groups so every request routed through either is recorded on terminate.
     * It is bound as a singleton so the instance that runs `handle()` is the one
     * the query listener below increments and `terminate()` reads — the
     * per-request query count lives on that single instance.
     *
     * The per-domain `prism.capture.requests` toggle gates the whole thing: when
     * off, no middleware is registered and no query listener is bound, so a host
     * that does not want request telemetry pays nothing.
     *
     * A group is only touched when the host has defined it — Laravel's
     * `appendMiddlewareToGroup()` throws on an unknown group, and a minimal
     * kernel (or a console-only app) may define neither. A duplicate append is
     * skipped so a re-boot cannot register the middleware twice.
     */
    protected function registerRequestCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.requests', true)) {
            return;
        }

        $this->app->singleton(CaptureRequests::class, static function ($app): CaptureRequests {
            return new CaptureRequests(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app['config'],
                $app,
                $app->make(SpanRecorder::class),
            );
        });

        $kernel = $this->app->make(HttpKernelContract::class);

        if ($kernel instanceof FoundationHttpKernel) {
            $groups = $kernel->getMiddlewareGroups();

            foreach (['web', 'api'] as $group) {
                if (isset($groups[$group]) && ! in_array(CaptureRequests::class, $groups[$group], true)) {
                    $kernel->appendMiddlewareToGroup($group, CaptureRequests::class);
                }
            }
        }

        // Count the queries a request runs so its request event can report the
        // total (AC2), and feed their durations to the aggregate `db` waterfall
        // span (US-050). The counter lives on the shared middleware singleton and
        // is reset per request in handle(). A query emitted while the package is
        // doing its own work (a flush) is suppressed, so it never inflates the
        // count of the request that triggered the flush.
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (Recursion::suppressed()) {
                return;
            }

            $this->app->make(CaptureRequests::class)->recordQuery(is_numeric($event->time) ? (float) $event->time : 0.0);
        });
    }

    /**
     * Hook log capture into the host (US-044) without a `config/logging.php` edit.
     *
     * A single shared {@see PrismLogHandler} is pushed onto the Monolog logger of
     * every configured channel — the app's default channel (its stack) when none
     * is named. The handler bubbles, so the channel's existing destinations keep
     * receiving every record; Prism only mirrors them into its buffer. The minimum
     * level lives on the handler (`prism.log.level`, default `debug`).
     *
     * The per-domain `prism.capture.logs` toggle gates the whole thing: when off,
     * the handler is neither bound nor attached, so a host that does not want log
     * telemetry pays nothing.
     *
     * A channel is only touched when its underlying logger is a Monolog logger — a
     * host that swapped in a non-Monolog PSR-3 logger degrades cleanly to no log
     * capture. Any handler left by an earlier boot is dropped before the current
     * one is pushed, so re-registration never duplicates capture.
     */
    protected function registerLogCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.logs', true)) {
            return;
        }

        $this->app->singleton(PrismLogHandler::class, static function ($app): PrismLogHandler {
            $level = $app['config']->get('prism.log.level', 'debug');

            return new PrismLogHandler(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                is_string($level) || is_int($level) ? $level : 'debug',
            );
        });

        $handler = $this->app->make(PrismLogHandler::class);
        $log = $this->app->make('log');

        foreach ($this->configuredLogChannels() as $name) {
            $this->attachLogHandler($name === null ? $log->channel() : $log->channel($name), $handler);
        }
    }

    /**
     * Hook database query capture into the host (US-045).
     *
     * A `QueryExecuted` listener hands every executed query to the {@see
     * QueryCapture} singleton, which buffers it as a `query` telemetry event. This
     * is a separate listener from the request query-counter (US-043) on the same
     * event: that one only totals queries for the request event, this one records
     * each query in full with its SQL, scrubbed bindings, connection and duration.
     *
     * The per-domain `prism.capture.queries` toggle gates the whole thing: when
     * off, the capturer is neither bound nor listened for, so a host that does not
     * want query telemetry pays nothing and no listener is registered at all.
     */
    protected function registerQueryCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.queries', true)) {
            return;
        }

        $this->app->singleton(QueryCapture::class, static function ($app): QueryCapture {
            $threshold = $app['config']->get('prism.query.slow_threshold_ms', 100);

            return new QueryCapture(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                is_numeric($threshold) ? (float) $threshold : 100.0,
            );
        });

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->app->make(QueryCapture::class)->capture($event);
        });
    }

    /**
     * Hook cache capture into the host (US-046).
     *
     * Laravel's four cache lifecycle events — a hit, a miss, a write and a forget
     * — are each listened for and handed to the {@see CacheCapture} singleton,
     * which buffers each as a `cache` span so cache behaviour renders on the trace
     * waterfall. Only these four are surfaced; other cache events (flushes, locks)
     * are ignored.
     *
     * Cache spans are part of a trace, so the per-domain `prism.capture.traces`
     * toggle gates the whole thing: when off, nothing is bound or listened for and
     * a host that does not want trace spans pays nothing.
     */
    protected function registerCacheCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.traces', true)) {
            return;
        }

        $this->app->singleton(CacheCapture::class, static function ($app): CacheCapture {
            return new CacheCapture(
                $app->make(EventBuffer::class),
                $app,
                IgnoreList::patterns($app['config']->get('prism.ignore.cache', [])),
            );
        });

        foreach ([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class] as $eventClass) {
            $this->app['events']->listen($eventClass, function (CacheEvent $event): void {
                $this->app->make(CacheCapture::class)->capture($event);
            });
        }
    }

    /**
     * Hook outgoing-HTTP capture into the host (US-046).
     *
     * The {@see HttpCapture} middleware is registered globally on Laravel's HTTP
     * client, so it wraps every request the host makes through it and records a
     * `http` span with the method, host, path, status and measured duration.
     * Wrapping the send is what times the call and sees its response; the
     * package's own ingest POST goes through Guzzle directly (not the HTTP client)
     * and is additionally marked internal, so it is never captured.
     *
     * HTTP spans are part of a trace, so the per-domain `prism.capture.traces`
     * toggle gates the whole thing: when off, no global middleware is registered
     * and a host that does not want trace spans pays nothing.
     */
    protected function registerHttpCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.traces', true)) {
            return;
        }

        $this->app->singleton(HttpCapture::class, static function ($app): HttpCapture {
            return new HttpCapture(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                IgnoreList::patterns($app['config']->get('prism.ignore.http', [])),
            );
        });

        Http::globalMiddleware($this->app->make(HttpCapture::class));
    }

    /**
     * Hook queue-job capture into the host (US-047).
     *
     * Four listeners feed the one {@see JobCapture} singleton: `JobProcessing`
     * records the processing-start time and queue wait, and the three terminal
     * events — `JobProcessed`, `JobReleasedAfterException` (a retry) and
     * `JobFailed` — each buffer a `job` event with the class, queue, connection,
     * UUID, attempt number, wait, runtime, scrubbed payload and (for a failure)
     * the exception.
     *
     * The queue wait is measured from dispatch to processing start, so a queue
     * payload hook stamps the dispatch time onto every job — the same static
     * `createPayloadUsing` mechanism the trace propagation uses (US-041), and it
     * survives across the worker process boundary in the serialized payload.
     *
     * After each captured terminal event the buffer is flushed, so a job's
     * telemetry ships at the end of the job instead of accumulating across a
     * long-running worker (AC5). Only a captured job triggers a flush — the
     * package's own jobs and ignored classes record nothing and flush nothing.
     *
     * The per-domain `prism.capture.jobs` toggle gates the whole thing: when off,
     * nothing is bound, no payload hook is added and no listener is registered.
     */
    protected function registerJobCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.jobs', true)) {
            return;
        }

        $this->app->singleton(JobCapture::class, static function ($app): JobCapture {
            return new JobCapture(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                IgnoreList::patterns($app['config']->get('prism.ignore.jobs', [])),
            );
        });

        // Stamp the dispatch time onto every job so the queue wait can be
        // measured from dispatch to processing start (AC4). Microsecond precision
        // and Carbon's clock, so it stays correct across a delay and is testable.
        Queue::createPayloadUsing(static fn (): array => [
            JobCapture::QUEUED_AT_KEY => now()->getPreciseTimestamp(6),
        ]);

        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(JobCapture::class)->recordStart($event);
        });

        $this->app['events']->listen(JobProcessed::class, function (JobProcessed $event): void {
            if ($this->app->make(JobCapture::class)->recordProcessed($event)) {
                $this->flush();
            }
        });

        $this->app['events']->listen(JobReleasedAfterException::class, function (JobReleasedAfterException $event): void {
            if ($this->app->make(JobCapture::class)->recordReleased($event)) {
                $this->flush();
            }
        });

        $this->app['events']->listen(JobFailed::class, function (JobFailed $event): void {
            if ($this->app->make(JobCapture::class)->recordFailed($event)) {
                $this->flush();
            }
        });
    }

    /**
     * Hook scheduled-task capture into the host (US-049).
     *
     * Three listeners feed the one {@see ScheduleCapture} singleton:
     * `ScheduledTaskStarting` stamps the run-start time and begins a fresh trace,
     * and the two terminal events — `ScheduledTaskFinished` and
     * `ScheduledTaskFailed` — each buffer a `schedule` event with the command,
     * cron expression, exit code, duration, peak memory, captured output and
     * hostname. A failed task carries the thrown exception as its failure reason.
     *
     * After each captured terminal event the buffer is flushed, so a task's
     * telemetry ships at the end of the task rather than accumulating across the
     * `schedule:run` process (AC5) — the same per-terminal flush the job capturer
     * uses. Both closure and command tasks are covered: the framework fires the
     * same events for either (AC4).
     *
     * The per-domain `prism.capture.schedules` toggle gates the whole thing: when
     * off, nothing is bound and no listener is registered.
     */
    protected function registerScheduleCapture(): void
    {
        if (! $this->app['config']->get('prism.capture.schedules', true)) {
            return;
        }

        $this->app->singleton(ScheduleCapture::class, static function ($app): ScheduleCapture {
            $maxOutput = $app['config']->get('prism.schedule.max_output', 16384);

            return new ScheduleCapture(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                is_numeric($maxOutput) ? (int) $maxOutput : 16384,
                IgnoreList::patterns($app['config']->get('prism.ignore.commands', [])),
            );
        });

        $this->app['events']->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->app->make(ScheduleCapture::class)->recordStart($event);
        });

        $this->app['events']->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            if ($this->app->make(ScheduleCapture::class)->recordFinished($event)) {
                $this->flush();
            }
        });

        $this->app['events']->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            if ($this->app->make(ScheduleCapture::class)->recordFailed($event)) {
                $this->flush();
            }
        });
    }

    /**
     * Wire queue-depth and worker polling (US-048).
     *
     * Queue depth and worker counts are point-in-time facts about the queue
     * backend, not per-event telemetry, so the {@see QueueMetrics} collector
     * samples them on an interval and piggybacks the result onto whatever flush
     * happens next — {@see flush()} calls it before draining the buffer, so a
     * sample rides an existing flush rather than a dedicated request (AC1).
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

        $this->app->singleton(QueueMetrics::class, static function ($app): QueueMetrics {
            $interval = $app['config']->get('prism.metrics.queue_interval', 30);

            return new QueueMetrics(
                $app->make(EventBuffer::class),
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
     * collector samples them on an interval (default 60s) and piggybacks the
     * result onto whatever flush happens next; {@see flush()} calls it before
     * draining the buffer, so a sample rides an existing flush rather than a
     * dedicated request (AC1).
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

        $this->app->singleton(SystemMetrics::class, static function ($app): SystemMetrics {
            $interval = $app['config']->get('prism.metrics.system_interval', 60);
            $configuredType = $app['config']->get('prism.metrics.replica_type');

            $type = is_string($configuredType) && $configuredType !== ''
                ? $configuredType
                : (Runtime::isQueueWorker() ? 'worker' : 'web');

            return new SystemMetrics(
                $app->make(EventBuffer::class),
                $app,
                (string) $app['config']->get('prism.replica', 'unknown'),
                $type,
                is_numeric($interval) ? (int) $interval : 60,
            );
        });
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
     * Push the shared handler onto a channel's Monolog logger, first stripping any
     * handler an earlier boot left so re-registration cannot double-capture. A
     * channel whose logger is not Monolog is skipped.
     */
    private function attachLogHandler(mixed $channel, PrismLogHandler $handler): void
    {
        if (! is_object($channel) || ! method_exists($channel, 'getLogger')) {
            return;
        }

        $monolog = $channel->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $monolog->setHandlers(array_values(array_filter(
            $monolog->getHandlers(),
            static fn (HandlerInterface $existing): bool => ! $existing instanceof PrismLogHandler,
        )));

        $monolog->pushHandler($handler);
    }

    /**
     * The originating trace id stamped onto a processing job's payload at
     * dispatch, or null when the job was queued by an uninstrumented producer
     * (in which case the listener starts a fresh trace for it).
     */
    private function payloadTraceId(JobProcessing $event): ?string
    {
        $id = $event->job->payload()[TraceContext::JOB_PAYLOAD_KEY] ?? null;

        return is_string($id) ? $id : null;
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
     */
    protected function flush(): void
    {
        $this->collectQueueMetrics();
        $this->collectSystemMetrics();

        Recursion::suppress(function (): void {
            try {
                $this->app->make(Flusher::class)->flush();
            } catch (Throwable $e) {
                Log::debug('Prism flush failed: '.$e->getMessage());
            }
        });
    }

    /**
     * Piggyback a queue-metrics sample onto this flush (US-048), if one is due.
     * The collector is bound only on a queue worker, so a web replica never even
     * makes the call. Run before the suppression scope below so the collector's
     * own recursion guard is not already tripped, and interval-gated so most
     * flushes add nothing. Best-effort: a sampling fault never blocks the flush.
     */
    private function collectQueueMetrics(): void
    {
        if (! $this->app->bound(QueueMetrics::class)) {
            return;
        }

        $this->app->make(QueueMetrics::class)->collect();
    }

    /**
     * Piggyback a replica-health sample onto this flush (US-051), if one is due.
     * Bound on every replica, so unlike queue metrics the call runs on web
     * front-ends too. Run before the suppression scope below so the collector's
     * own recursion guard is not already tripped, and interval-gated so most
     * flushes add nothing. Best-effort: a sampling fault never blocks the flush.
     */
    private function collectSystemMetrics(): void
    {
        if (! $this->app->bound(SystemMetrics::class)) {
            return;
        }

        $this->app->make(SystemMetrics::class)->collect();
    }
}
