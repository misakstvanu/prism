<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest as NightwatchSocketIngest;
use Laravel\Nightwatch\RecordsBuffer;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Prism's pipeline installed over Nightwatch's socket ingest (US-004).
 *
 * Out of the box every Nightwatch sensor hands its records to an ingest that
 * writes them over TCP to the `nightwatch:agent` daemon. Prism assigns its own
 * implementation over `Core::$ingest` at boot, which is the entire reason no
 * agent process is required.
 *
 * This file lives under `tests/Host` rather than `tests/Feature`, so its case
 * is the one that registers Nightwatch's own provider too, ahead of Prism's,
 * the way a real install orders them — see `NightwatchHostTestCase` and the
 * `pest()->extend(...)->in('Host')` line in `tests/Pest.php`.
 *
 * **"No socket" is asserted, not assumed.** A daemon that is not running fails
 * in a way that looks exactly like a working install from the host's side —
 * the socket ingest's write is best-effort and nothing surfaces — so the proof
 * has to be a stream factory that fails the test if anything reaches for it.
 * Every lifecycle test below therefore puts a *real* socket ingest back on the
 * `Core` first, carrying that double, and re-runs the swap over it; the last
 * test in the file proves the double really does fire when nothing replaces it.
 */
beforeEach(function () {
    $this->opened = 0;

    // The stream factory the real socket ingest calls to reach the agent. It
    // counts and hands back a memory stream, so a call is a recorded failure
    // rather than a connection refused somewhere in the noise.
    $this->streamFactory = function (string $address, float $timeout) {
        $this->opened++;

        return fopen('php://memory', 'r+');
    };

    $this->transport = new SwapRecordingTransport;
    app()->instance(Transport::class, $this->transport);
});

it("assigns Prism's ingest over Nightwatch's socket ingest during boot", function () {
    // Nothing re-run here: this is the state the application's own boot left,
    // with both providers registered in the order a real host produces.
    expect(app(Core::class)->ingest)->toBeInstanceOf(PrismIngest::class);
});

it('opens no TCP stream across a full request lifecycle', function () {
    $core = restoreSocketIngest();

    swapPrismIngestIn();

    expect($core->ingest)->toBeInstanceOf(PrismIngest::class);

    Route::get('/prism-lifecycle-probe', fn () => 'ok');

    $this->get('/prism-lifecycle-probe')->assertOk();

    // Non-vacuous both ways: the lifecycle really did produce telemetry, and
    // every bit of it left through Prism's own transport instead of a socket.
    expect($this->opened)->toBe(0)
        ->and($this->transport->sent)->not->toBeEmpty();
});

it("carries the request record out through Prism's transport", function () {
    restoreSocketIngest();
    swapPrismIngestIn();

    Route::get('/prism-lifecycle-record', fn () => 'ok');

    $this->get('/prism-lifecycle-record')->assertOk();

    $types = [];

    foreach ($this->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $types[] = $event['type'];
        }
    }

    expect($this->opened)->toBe(0)
        ->and($types)->toContain('request');
});

it('gives the request record the execution id it does not carry', function () {
    restoreSocketIngest();
    swapPrismIngestIn();

    Route::get('/prism-lifecycle-key', fn () => 'ok');

    $this->get('/prism-lifecycle-key')->assertOk();

    $requests = [];

    foreach ($this->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] === 'request') {
                $requests[] = $event;
            }
        }
    }

    // A `request` record IS the execution and so names no `execution_id`; the
    // id is on the `Core`'s execution state, which only the swap site can
    // reach. Left blank, every row in the `requests` table has an empty key.
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['request_id'])->toBe(app(Core::class)->executionState->id)
        ->and($requests[0]['request_id'])->not->toBe('');
});

it("leaves Nightwatch's own ingest alone when Prism is disabled", function () {
    $core = restoreSocketIngest();

    config(['prism.enabled' => false]);
    swapPrismIngestIn();

    expect($core->ingest)->toBeInstanceOf(NightwatchSocketIngest::class)
        // Nothing else was registered either — the master switch is one switch.
        ->and(app()->bound(PrismServiceProvider::ACTIVE))->toBeFalse();
});

it("leaves Nightwatch's own ingest alone when no token is configured", function () {
    $core = restoreSocketIngest();

    config(['prism.token' => null]);
    swapPrismIngestIn();

    expect($core->ingest)->toBeInstanceOf(NightwatchSocketIngest::class)
        ->and(app()->bound(PrismServiceProvider::ACTIVE))->toBeFalse();
});

it('survives a host with no Core bound to swap onto', function () {
    app()->forgetInstance(Core::class);

    expect(app()->bound(Core::class))->toBeFalse();

    // `laravel/nightwatch` is a hard requirement of this package, so this is an
    // odd tree rather than a supported one — but a composer tree is not
    // something to take a host application down over.
    swapPrismIngestIn();

    expect(app()->bound(PrismServiceProvider::ACTIVE))->toBeTrue();
});

it('proves the stream double fires for the ingest it replaced', function () {
    // The other tests assert a zero. This one earns it: left in place, the
    // socket ingest reaches for the agent on the very first record.
    $ingest = socketIngest($this->streamFactory);

    try {
        $ingest->writeNow(['v' => 1, 't' => 'log', 'timestamp' => microtime(true)]);
    } catch (Throwable) {
        // The memory stream never answers `2:OK`, which is fine: the reach for
        // it is the whole point and it has already been counted.
    }

    expect($this->opened)->toBe(1);
});

/**
 * Put a real Nightwatch socket ingest back on the `Core`, carrying the current
 * test's stream double, and hand back the `Core` it was installed on.
 *
 * The application's own boot has already performed the swap by the time a test
 * body runs, so a test that wants to observe the swap has to undo it first —
 * and undoing it with anything other than the real socket implementation would
 * assert nothing about sockets.
 *
 * @return Core<RequestState|CommandState>
 */
function restoreSocketIngest(): Core
{
    $core = app(Core::class);

    $core->ingest = socketIngest(test()->streamFactory);

    return $core;
}

/** The ingest a bare `laravel/nightwatch` install ships, over a given stream factory. */
function socketIngest(callable $streamFactory): NightwatchSocketIngest
{
    return new NightwatchSocketIngest(
        transmitTo: '127.0.0.1:2407',
        connectionTimeout: 0.5,
        timeout: 0.5,
        streamFactory: $streamFactory,
        buffer: new RecordsBuffer(length: 500),
        tokenHash: 'testhash',
    );
}

/**
 * Re-run the provider's boot against the current configuration. The app is
 * already booted, so the `booted` callback the provider registers fires at
 * once — which is exactly the swap under test.
 *
 * The recording transport is re-installed **afterwards**, because a boot that
 * reaches `registerCapture()` re-binds `Transport` as a singleton of its own —
 * and a lifecycle test that quietly ran against a real `HttpTransport` would
 * report an empty recording as if no telemetry had been produced at all.
 */
function swapPrismIngestIn(): void
{
    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    (new PrismServiceProvider(app()))->boot();

    app()->instance(Transport::class, test()->transport);
}

/**
 * A transport that records envelopes instead of sending them. Named apart from
 * the equivalents in `FlushTest` and `PrismIngestTest` because Pest loads every
 * test file into one process, where a redeclared class is fatal.
 */
final class SwapRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
