<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\CacheCapture;
use Misakstvanu\Prism\Capture\CaptureRequests;
use Misakstvanu\Prism\Capture\HttpCapture;
use Misakstvanu\Prism\Capture\JobCapture;
use Misakstvanu\Prism\Capture\ScheduleCapture;
use Misakstvanu\Prism\Capture\SpanRecorder;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\IgnoreList;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\SpanStack;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double recording the envelopes it is handed. Scheduled tasks flush
 * at the end of every run (US-049), so their events leave the buffer before a
 * test can read it and must be asserted on what shipped. Uniquely named — Pest
 * loads every test file into one process.
 */
class IgnoreCaptureTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so every capturer is
 * wired with the ignore lists under test. The flush strategy is `sync` and no
 * kernel drains the buffer, so a request/cache/HTTP event stays readable
 * directly off the buffer; a schedule event ships through the returned transport
 * instead. Replica health sampling is off because it rides every flush and would
 * inflate the exact counts this file asserts. Uniquely named — Pest loads every
 * test file into one process.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootIgnores(array $overrides = []): IgnoreCaptureTransport
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.metrics' => false,
        'prism.ignore.paths' => [],
        'prism.ignore.jobs' => [],
        'prism.ignore.commands' => [],
        'prism.ignore.http' => [],
        'prism.ignore.cache' => [],
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();
    SpanStack::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(SpanRecorder::class);
    app()->forgetInstance(CaptureRequests::class);
    app()->forgetInstance(CacheCapture::class);
    app()->forgetInstance(HttpCapture::class);
    app()->forgetInstance(JobCapture::class);
    app()->forgetInstance(ScheduleCapture::class);

    (new PrismServiceProvider(app()))->boot();

    $transport = new IgnoreCaptureTransport;
    app()->instance(Transport::class, $transport);

    return $transport;
}

/**
 * Every `schedule` event across everything the transport received, by command.
 *
 * @return list<mixed>
 */
function shippedScheduleCommands(IgnoreCaptureTransport $transport): array
{
    $commands = [];

    foreach ($transport->sent as $envelope) {
        foreach (($envelope['events'] ?? []) as $event) {
            if (($event['type'] ?? null) === 'schedule') {
                $commands[] = $event['payload']['command'] ?? null;
            }
        }
    }

    return $commands;
}

/**
 * Drive one request through the capture middleware's full lifecycle and return
 * how many `request` events it buffered.
 *
 * @param  array<string, string>  $headers
 */
function ignoredRequestEvents(string $uri, array $headers = []): int
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create($uri, 'POST', server: $server);
    $middleware = app(CaptureRequests::class);

    $response = $middleware->handle($request, static fn (): Response => new Response('ok'));
    $middleware->terminate($request, $response);

    return count(app(EventBuffer::class)->all()['request'] ?? []);
}

/** The buffered span events, in order. */
function ignoredSpans(): array
{
    return app(EventBuffer::class)->all()['span'] ?? [];
}

/**
 * A queue Job double stubbing exactly the accessors JobCapture reads. Uniquely
 * named — Pest loads every test file into one process, so it must not collide
 * with JobCaptureTest's own double.
 */
function fakeIgnoredJob(string $name): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('job-uuid-1');
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

afterEach(function (): void {
    Recursion::reset();
    TraceContext::reset();
    SpanStack::reset();
    Queue::createPayloadUsing(null);
});

it('normalises a raw config value into a pattern list', function (): void {
    // Config is untyped, so a published file can hold anything at all.
    expect(IgnoreList::patterns(['a', 'b']))->toBe(['a', 'b'])
        ->and(IgnoreList::patterns('not-an-array'))->toBe([])
        ->and(IgnoreList::patterns(null))->toBe([])
        // Non-string entries are dropped, and the survivors re-keyed into a list.
        ->and(IgnoreList::patterns(['a', 42, null, 'b']))->toBe(['a', 'b']);
});

it('matches any candidate against any pattern, with wildcards', function (): void {
    expect(IgnoreList::matches(['api/*'], 'api/ingest'))->toBeTrue()
        ->and(IgnoreList::matches(['api/*'], 'web/ingest'))->toBeFalse()
        // No wildcard means an exact match, not a prefix one.
        ->and(IgnoreList::matches(['api'], 'api/ingest'))->toBeFalse()
        // Any candidate matching any pattern is a match.
        ->and(IgnoreList::matches(['clickhouse'], 'other', 'clickhouse'))->toBeTrue()
        // A backslashed class name survives the pattern escaping.
        ->and(IgnoreList::matches(['App\Events\*'], 'App\Events\TelemetryUpdated'))->toBeTrue()
        ->and(IgnoreList::matches([], 'anything'))->toBeFalse()
        // An empty candidate must not be silenced by a bare wildcard meant for
        // something else — a missing host is not "every host".
        ->and(IgnoreList::matches(['*'], ''))->toBeFalse();
});

it('never captures an inbound ingest batch from another Prism client', function (): void {
    bootIgnores();

    // The marker header is the only signal available here: this is a fresh
    // request in a fresh process, so the in-process suppression flag cannot see
    // that it originated from a Prism client. Without this the pipeline loops —
    // capturing the batch ships a batch that produces the next one.
    expect(ignoredRequestEvents('/api/ingest', [Recursion::MARKER_HEADER => '1']))->toBe(0);
});

it('captures the same request when it carries no internal marker', function (): void {
    bootIgnores();

    expect(ignoredRequestEvents('/api/ingest'))->toBe(1);
});

it('suppresses everything an inbound ingest batch does while it is handled', function (): void {
    bootIgnores();

    $request = Request::create('/api/ingest', 'POST', server: [
        'HTTP_'.strtoupper(str_replace('-', '_', Recursion::MARKER_HEADER)) => '1',
    ]);

    $middleware = app(CaptureRequests::class);

    // Storing a batch reads the cache and runs queries of its own. Skipping only
    // the request event would leave that collateral captured — and a rate
    // limiter's cache key is hashed, so no pattern could exclude it.
    $middleware->handle($request, static function () use (&$suppressed): Response {
        $suppressed = Recursion::suppressed();
        event(new CacheHit('array', 'a3f9c1e0b2d4', 1));

        return new Response('ok');
    });

    expect($suppressed)->toBeTrue()
        ->and(ignoredSpans())->toBe([])
        // The scope must close with the request, not leak into the next one.
        ->and(Recursion::suppressed())->toBeFalse();
});

it('skips a request path on the ignore list', function (): void {
    bootIgnores(['prism.ignore.paths' => ['api/*']]);

    expect(ignoredRequestEvents('/api/ingest'))->toBe(0)
        ->and(ignoredRequestEvents('/orders'))->toBe(1);
});

it('skips a job class matching an ignore pattern', function (): void {
    bootIgnores(['prism.ignore.jobs' => ['App\Events\*']]);

    $capture = app(JobCapture::class);

    // A queued broadcast resolves to the event class, so one namespace pattern
    // covers every broadcast without naming them individually.
    expect($capture->recordProcessed(new JobProcessed(
        'redis',
        fakeIgnoredJob('App\Events\TelemetryUpdated'),
    )))->toBeFalse();

    expect($capture->recordProcessed(new JobProcessed(
        'redis',
        fakeIgnoredJob('App\Jobs\SendWelcomeEmail'),
    )))->toBeTrue();
});

it('skips a scheduled command matching an ignore pattern', function (): void {
    $transport = bootIgnores(['prism.ignore.commands' => ['prism:*']]);

    $ignored = app(Schedule::class)->exec('prism:usage:flush')->cron('* * * * *');
    $kept = app(Schedule::class)->exec('reports:build')->cron('* * * * *');

    foreach ([$ignored, $kept] as $task) {
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, 0.5));
    }

    expect(shippedScheduleCommands($transport))->toBe(['reports:build']);
});

it('skips an outgoing call to an ignored destination', function (): void {
    bootIgnores(['prism.ignore.http' => ['clickhouse']]);

    Http::fake(['*' => Http::response('', 200)]);

    // A datastore reached over HTTP would otherwise emit a span per read and per
    // write — and storing that span is another write.
    Http::get('http://clickhouse:8123/?query=SELECT+1');
    Http::get('https://api.stripe.com/v1/charges');

    $names = array_map(static fn (array $span): mixed => $span['payload']['name'] ?? null, ignoredSpans());

    expect($names)->toBe(['GET api.stripe.com/v1/charges']);
});

it('skips a cache key matching an ignore pattern', function (): void {
    bootIgnores(['prism.ignore.cache' => ['prism:*']]);

    event(new CacheHit('array', 'prism:usage:1:2026-07', 1));
    event(new CacheHit('array', 'orders:recent', ['id' => 1]));

    $names = array_map(static fn (array $span): mixed => $span['payload']['name'] ?? null, ignoredSpans());

    expect($names)->toBe(['cache:hit orders:recent (array)']);
});
