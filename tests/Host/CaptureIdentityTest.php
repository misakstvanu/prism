<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest as NightwatchSocketIngest;
use Laravel\Nightwatch\RecordsBuffer;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Prism's identity on the wire, and the report that says where records go
 * (US-005).
 *
 * Nightwatch's model has no application and no environment dimension — an
 * install's identity is implied by its one token, and a record carries only
 * `deploy` and `server`. Prism's whole scope model needs all three: every
 * console screen narrows by `?app=`, `?env=` and `?replica=`. They therefore
 * ride the batch **envelope**, which the flusher has always built, rather than
 * being pushed into individual records — so this story is about making the two
 * engines agree on the one dimension they both name (the replica / the server)
 * rather than about new plumbing.
 *
 * That agreement is not free. Nightwatch defaults `server` to `gethostname()`
 * and reads it once, into the `Core` it builds during its own `register()` —
 * which in every real install happens **before** Prism registers. Prism
 * reconciles it in place; without that, a record would name the container's
 * hostname while the envelope carrying it named the configured replica, and
 * every correlated read that joins the two would quietly find nothing.
 *
 * This file lives under `tests/Host` because both claims need Nightwatch's own
 * provider registered ahead of Prism's, the way a real install orders them.
 */
beforeEach(function () {
    $this->transport = new IdentityRecordingTransport;

    // The application's boot has already installed the real transport as a
    // singleton, so the recorder goes in afterwards — a lifecycle test that
    // quietly ran against a real `HttpTransport` would read as "no telemetry
    // was produced" rather than as a broken double.
    app()->instance(Transport::class, $this->transport);
});

it('names the same process on the envelope and inside the record', function () {
    Route::get('/prism-identity-probe', fn () => 'ok');

    $this->get('/prism-identity-probe')->assertOk();

    $envelopes = $this->transport->sent;

    expect($envelopes)->not->toBeEmpty();

    $requests = [];

    foreach ($envelopes as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] === 'request') {
                $requests[] = [$envelope, $event];
            }
        }
    }

    expect($requests)->toHaveCount(1);

    [$envelope, $event] = $requests[0];

    // The three dimensions every console screen scopes by ride the envelope,
    // exactly as they always have.
    expect($envelope['app'])->toBe('demo')
        ->and($envelope['env'])->toBe('production')
        ->and($envelope['replica'])->toBe('web-1')
        // And the record's own `server`, which Nightwatch would otherwise have
        // filled with `gethostname()`, names that same replica. Non-vacuous:
        // the default is the container's hostname, which is not 'web-1'.
        ->and($event['payload']['server'])->toBe('web-1')
        ->and($event['payload']['server'])->not->toBe((string) gethostname());
});

it('reports the in-process ingest, so an operator can see records reach Prism', function () {
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    config(['prism.endpoint' => 'https://prism.test/api/ingest']);

    // The swap really happened in this host — the report below is reading a
    // fact, not restating a config value.
    expect(app(Core::class)->ingest)->toBeInstanceOf(PrismIngest::class);

    $this->artisan('prism:check')
        ->expectsOutputToContain('in-process')
        ->assertExitCode(0);
});

it('reports the engine ingest when Prism never swapped its own in', function () {
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    config(['prism.endpoint' => 'https://prism.test/api/ingest']);

    // The state a token-less or disabled install is left in: Nightwatch is
    // capturing and handing its records to its own socket ingest, and nothing
    // is reaching Prism. That is invisible from the console — an empty
    // workspace looks the same as a quiet one — so the command names it. The
    // REAL socket ingest is put back rather than a stand-in, because what the
    // report reads is the class on the property.
    app(Core::class)->ingest = new NightwatchSocketIngest(
        transmitTo: '127.0.0.1:2407',
        connectionTimeout: 0.5,
        timeout: 0.5,
        streamFactory: fn () => throw new RuntimeException('prism:check must not open the agent socket'),
        buffer: new RecordsBuffer(length: 500),
        tokenHash: 'testhash',
        events: app('events'),
    );

    $this->artisan('prism:check')
        ->expectsOutputToContain('NOT reaching Prism')
        ->assertExitCode(0);
});

/**
 * A transport that records envelopes instead of sending them. Named apart from
 * the equivalents in `FlushTest`, `PrismIngestTest` and `NightwatchIngestSwapTest`
 * because Pest loads every test file into one process, where a redeclared class
 * is fatal.
 */
final class IdentityRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
