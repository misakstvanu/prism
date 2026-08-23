<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Transport\Transport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * `prism.ignore.*` driven through the REAL capture engine (US-010).
 *
 * The story this file belongs to exists because of one pathological host: the
 * application that *is* the Prism workspace it reports to. There, the whole
 * ingest pipeline is work the host does on Prism's behalf — the inbound batch
 * is a request, storing it is a job, metering it is a run of cache counters and
 * the ClickHouse write is an outgoing HTTP call — so an unfiltered install
 * captures its own bookkeeping, ships that as a second batch, and captures the
 * storing of that one too. The loop does not settle.
 *
 * Every assertion below therefore goes through `Laravel\Nightwatch\Core`'s own
 * entry points rather than through Prism's predicates. A rule that reads
 * plausibly and is never consulted (a `Str::is` pattern handed to a matcher
 * that wants a regex, a `dontSample()` overwritten by upstream's own middleware
 * a moment later) fails no test that only checks the pattern list, and the
 * symptom in production is telemetry the operator believes they switched off.
 *
 * The file lives under `tests/Host` so its case registers Nightwatch's provider
 * ahead of Prism's, the way a real install orders them.
 */
beforeEach(function () {
    $this->transport = new RejectRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('never buffers a cache key on the ignore list', function () {
    bootPrismWithIgnores(['cache' => ['prism:*']]);

    cacheRead('prism:usage:1:2026-08');

    expect(bufferedTypes())->toBeEmpty();
});

it('still buffers a cache key nothing silenced', function () {
    bootPrismWithIgnores(['cache' => ['prism:*']]);

    cacheRead('orders:5');

    // Non-vacuous: the engine really does record cache reads here, so the empty
    // buffer above is the rule working rather than the sensor being asleep.
    expect(bufferedTypes())->toContain('span');
});

it('converts the wildcard pattern the regex matcher would otherwise ignore', function () {
    // The exact shape a host writes, and the one that silences nothing at all
    // when it reaches `preg_match` unconverted: no delimiters means the pattern
    // does not compile, and upstream's fallback then compares it to the key as
    // a literal string.
    bootPrismWithIgnores(['cache' => ['prism:*']]);

    $patterns = app(Core::class)->defaultVendorCacheKeys();

    cacheRead('prism:ingest:metrics:inserts');

    expect(bufferedTypes())->toBeEmpty()
        // And the converted pattern is written the way upstream writes its own.
        ->and($patterns[0])->toStartWith('/');
});

it("never buffers the package's own cache bookkeeping, whatever the host configured", function () {
    bootPrismWithIgnores(['cache' => []]);

    // The spool index and the metrics interval marks. The metrics collectors
    // run deliberately OUTSIDE a suppression scope (their own guards would read
    // as tripped inside one), so these touches look like ordinary application
    // cache traffic to the engine and nothing else would keep them out.
    cacheRead('prism:spool:index');
    cacheRead('prism:metrics:system:web-1');

    expect(bufferedTypes())->toBeEmpty();
});

it('captures nothing at all while the package is doing its own work', function () {
    bootPrismWithIgnores(['cache' => [], 'http' => []]);

    // Recursion is the package's oldest guard, and every listener of the old
    // client consulted it directly. The engine's sensors cannot be made to, so
    // the reject callbacks are where the question gets asked now — building an
    // envelope, spooling it and shipping it are all bracketed by this scope,
    // and captured they would be the contents of the next batch.
    Recursion::suppress(function (): void {
        cacheRead('orders:5');
        outgoingCall('https://api.example.com/v1/things');
        jobQueued('App\Jobs\SendAlertDelivery');
    });

    expect(bufferedTypes())->toBeEmpty();
});

it('never buffers an outgoing call to an ignored destination', function () {
    bootPrismWithIgnores(['http' => ['clickhouse']]);

    outgoingCall('http://clickhouse:8123/?database=prism&query=INSERT');

    expect(bufferedTypes())->toBeEmpty();
});

it('matches an ignored destination by host, host and path, or full URL', function () {
    bootPrismWithIgnores(['http' => ['search.internal/v1/*', 'http://ch:8123/*']]);

    outgoingCall('https://search.internal/v1/query?q=x');
    outgoingCall('http://ch:8123/?query=SELECT');

    expect(bufferedTypes())->toBeEmpty();
});

it('still buffers an outgoing call nothing silenced', function () {
    bootPrismWithIgnores(['http' => ['clickhouse']]);

    outgoingCall('https://api.example.com/v1/things?page=2');

    expect(bufferedTypes())->toContain('span');
});

it('never buffers a queued job on the ignore list', function () {
    bootPrismWithIgnores(['jobs' => ['App\Events\*']]);

    jobQueued('App\Events\TelemetryUpdated');

    expect(bufferedTypes())->toBeEmpty();
});

it("never buffers one of the package's own jobs, whatever the host configured", function () {
    bootPrismWithIgnores(['jobs' => []]);

    // The flush job exists only to ship telemetry, so capturing it produces the
    // batch that dispatches the next one — the loop with no host config in it.
    jobQueued(SendBatchJob::class);

    expect(bufferedTypes())->toBeEmpty();
});

it('still buffers a queued job nothing silenced', function () {
    bootPrismWithIgnores(['jobs' => ['App\Events\*']]);

    jobQueued('App\Jobs\SendAlertDelivery');

    expect(bufferedTypes())->toContain('job');
});

it('ships nothing at all about a request on an ignored path', function () {
    bootPrismWithIgnores(['paths' => ['up', 'health*']]);

    Route::get('/up', function () {
        Log::info('the health check looked at something');

        return 'ok';
    });

    $this->get('/up')->assertOk();

    // Not merely the request row: `dontSample()` discards the execution, so
    // every query, cache read and log line the ignored request produced goes
    // with it. That is more than the old client dropped, and it is what an
    // ignore list is asking for — so the assertion is over EVERYTHING shipped,
    // and the route emits a log line to give it something to be wrong about.
    //
    // The request row is the one signal that is absent either way, because the
    // engine writes it in the request-lifecycle handler that runs *after* the
    // discard. Everything else is written during the request, and asserting
    // only on `request` was a false green for exactly as long as it took a
    // dogfooded install to notice (US-024).
    expect(shippedSignals())->toBeEmpty();
});

it('ships a request nothing silenced, so the absence above means something', function () {
    bootPrismWithIgnores(['paths' => ['up', 'health*']]);

    Route::get('/orders', function () {
        Log::info('an order was priced');

        return 'ok';
    });

    $this->get('/orders')->assertOk();

    expect(shippedTypes())->toContain('request')
        ->and(shippedTypes())->toContain('log');
});

it('sampling survives the engine middleware that re-samples every request', function () {
    // The ordering trap, asserted where it bites: Nightwatch PREPENDS its own
    // global middleware, whose handle() calls sample() — so a dontSample() made
    // ahead of it is silently undone and the ignored path ships after all.
    bootPrismWithIgnores(['paths' => ['up']]);

    Route::get('/up', fn () => app(Core::class)->sampling() ? 'sampled' : 'dropped');

    $this->get('/up')->assertSee('dropped');
});

it('fully suppresses an inbound batch carrying the internal marker', function () {
    // A path the host never listed, because it cannot: this is another Prism
    // client's batch arriving on whatever route the receiving application chose
    // for its ingest. The header is the only signal that crosses the boundary.
    bootPrismWithIgnores(['paths' => []]);

    Route::post('/some/ingest/route', fn () => [
        'suppressed' => Recursion::suppressed(),
        'sampling' => app(Core::class)->sampling(),
    ]);

    $this->postJson('/some/ingest/route', [], [Recursion::MARKER_HEADER => '1'])
        ->assertOk()
        // Suppressed for the request's whole lifetime, not merely at the end:
        // everything the host does while handling the batch is Prism's own.
        ->assertJson(['suppressed' => true, 'sampling' => false]);

    expect(shippedTypes())->not->toContain('request');
});

/**
 * The exception dimension is checked against a class the framework itself
 * WOULD report.
 *
 * `NotFoundHttpException` and `ValidationException` — the package's own two
 * defaults — are in Laravel's internal don't-report list, and the engine's
 * exception sensor asks `shouldReport()` before it builds anything. So a test
 * written with either of them passes whether or not Prism rejects a single
 * record, which is a false green on the one dimension that has no upstream
 * reject hook at all. `RuntimeException` is reportable, so these tests fail
 * when the rejection is removed.
 */
it('never reports an exception class on the ignore list', function () {
    bootPrismWithIgnores(['exceptions' => [RuntimeException::class]]);

    app(Core::class)->report(new RuntimeException('Order 5 could not be priced.'), handled: true);

    expect(bufferedTypes())->toBeEmpty();
});

it('never ships an UNHANDLED exception on the ignore list either', function () {
    // An unhandled exception takes writeNow(), which bypasses the buffer and
    // ships on the spot — a separate path through the same seam, and the one an
    // exception escaping to the handler actually travels.
    bootPrismWithIgnores(['exceptions' => [RuntimeException::class]]);

    app(Core::class)->report(new RuntimeException('Order 5 could not be priced.'), handled: false);

    expect($this->transport->sent)->toBeEmpty()
        ->and(bufferedTypes())->toBeEmpty();
});

it('covers a subclass of an ignored exception', function () {
    bootPrismWithIgnores(['exceptions' => [RuntimeException::class]]);

    app(Core::class)->report(new RejectRulesSubclassException('nope'), handled: true);

    expect(bufferedTypes())->toBeEmpty();
});

it('still reports an exception nothing silenced', function () {
    bootPrismWithIgnores(['exceptions' => [RuntimeException::class]]);

    app(Core::class)->report(new LogicException('Order 5 could not be priced.'), handled: true);

    expect(bufferedTypes())->toContain('exception');
});

it('does ship an unhandled exception nothing silenced', function () {
    bootPrismWithIgnores(['exceptions' => [RuntimeException::class]]);

    app(Core::class)->report(new LogicException('Order 5 could not be priced.'), handled: false);

    expect(shippedTypes())->toContain('exception');
});

it('discards the execution of a command on the ignore list', function () {
    bootPrismWithIgnores(['commands' => ['prism:*']]);

    event(new CommandStarting('prism:alerts:evaluate', new ArrayInput([]), new NullOutput));

    expect(app(Core::class)->sampling())->toBeFalse();
});

it('leaves a command nothing silenced alone', function () {
    bootPrismWithIgnores(['commands' => ['prism:*']]);

    event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

    expect(app(Core::class)->sampling())->toBeTrue();
});

it('discards the whole execution of a job attempt on the ignore list', function () {
    // A job attempt is an execution of its own upstream, so refusing the record
    // would leave every query, cache read and datastore write the ignored job
    // performed — which for the ingest pipeline's own job is most of the loop.
    bootPrismWithIgnores(['jobs' => ['App\Jobs\ProcessIngestBatch']]);

    event(new JobProcessing('redis', rejectRulesJobStub('App\Jobs\ProcessIngestBatch')));

    expect(app(Core::class)->sampling())->toBeFalse();
});

it('leaves the execution of a job attempt nothing silenced alone', function () {
    bootPrismWithIgnores(['jobs' => ['App\Jobs\ProcessIngestBatch']]);

    event(new JobProcessing('redis', rejectRulesJobStub('App\Jobs\SendAlertDelivery')));

    expect(app(Core::class)->sampling())->toBeTrue();
});

/**
 * Re-run Prism's boot with a given `ignore` block, so the reject callbacks the
 * provider installs are the ones under test.
 *
 * The block REPLACES the corresponding lists rather than extending them, which
 * is the same shallow-merge shape a host's own `config/prism.php` has against
 * the package's — a test that quietly kept the package defaults would be
 * asserting something no install ever runs.
 *
 * The recording transport is re-installed afterwards, because a boot that
 * reaches `registerCapture()` re-binds `Transport` as a singleton of its own.
 *
 * @param  array<string, list<string>>  $ignore
 */
function bootPrismWithIgnores(array $ignore): void
{
    config(['prism.ignore' => array_replace((array) config('prism.ignore'), $ignore)]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    (new PrismServiceProvider(app()))->boot();

    app()->instance(Transport::class, test()->transport);

    app(EventBuffer::class)->clear();
}

/**
 * The Prism event types the shared buffer currently holds.
 *
 * The observation point for every reject callback, because a rejected record
 * never reaches an ingest at all — there is nothing later to look at.
 *
 * @return list<string>
 */
function bufferedTypes(): array
{
    return array_map('strval', array_keys(app(EventBuffer::class)->all()));
}

/**
 * The Prism event types that actually left over the transport.
 *
 * The observation point for the two dimensions that are sampled out rather than
 * rejected: an unsampled execution still *buffers* its records, and what makes
 * it an exclusion is that `finishExecution()` discards the buffer instead of
 * shipping it. Emptiness is the wrong assertion here — Prism's own replica
 * metrics ride whichever batch is next, ignored request or not — so the claim
 * is about the type that would have described the ignored work.
 *
 * @return list<string>
 */
function shippedTypes(): array
{
    $types = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $types[] = (string) ($event['type'] ?? '');
        }
    }

    return $types;
}

/**
 * The same, minus the replica heartbeat.
 *
 * `replica_metric` is the one signal with no capture engine behind it: a
 * fixed-cadence sample of the process, shipped through `writeNow()` precisely
 * so it survives an execution the sampler rejected (US-013). It says nothing
 * about the execution under test and is meant to escape it, so an assertion
 * about what an ignored execution shipped sets it aside rather than being
 * written to accommodate it.
 *
 * @return list<string>
 */
function shippedSignals(): array
{
    return array_values(array_filter(
        shippedTypes(),
        static fn (string $type): bool => $type !== 'replica_metric',
    ));
}

/** Drive a real cache read through the engine: it times the operation, so both halves fire. */
function cacheRead(string $key): void
{
    $core = app(Core::class);

    $core->cacheEvent(new RetrievingKey('redis', $key));
    $core->cacheEvent(new CacheHit('redis', $key, ['id' => 5]));
}

/** Drive a real outgoing HTTP call through the engine. */
function outgoingCall(string $url): void
{
    app(Core::class)->outgoingRequest(
        microtime(true) - 0.05,
        microtime(true),
        new Psr7Request('GET', $url),
        new Psr7Response(200, [], 'ok'),
    );
}

/** Drive a real job dispatch through the engine; the sensor times it, so both halves fire. */
function jobQueued(string $name): void
{
    $core = app(Core::class);
    $payload = json_encode(['uuid' => 'e6d1f0a2-1c4b-4f52-9f3a-77c9a1b2c3d4', 'displayName' => $name]);

    $core->queuedJob(new JobQueueing('redis', 'default', $name, $payload, null));
    $core->queuedJob(new JobQueued('redis', 'default', 1, $name, $payload, null));
}

/** The queue job a worker hands to `JobProcessing`, named however the test needs. */
function rejectRulesJobStub(string $name): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

/** A subclass of an ignored throwable, which `instanceof` matching must cover. */
final class RejectRulesSubclassException extends RuntimeException
{
    //
}

/**
 * A transport that records envelopes instead of sending them. Named apart from
 * the equivalents elsewhere in the suite because Pest loads every test file
 * into one process, where a redeclared class is fatal.
 */
final class RejectRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
