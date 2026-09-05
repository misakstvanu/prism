<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Browser\BrowserForwarder;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A `local` host with no token captures and ships anyway (US-003).
 *
 * The hub's own half of this arrangement is US-002: a Prism running in the
 * `local` environment accepts a batch with no bearer and attributes it to a
 * default workspace. This is the client's half, and it is driven over a real
 * kernel lifecycle because that is the only place the claim is testable — the
 * gate runs once, while the provider boots, and everything downstream of it
 * (the buffer, the flusher, the transport, the browser forwarder) is bound
 * there or not at all.
 */
beforeEach(function () {
    // AFTER the application has booted, so the recorder is installed over the
    // transport `registerCapture()` bound rather than replaced by it — a
    // lifecycle test that quietly ran against a real `HttpTransport` would read
    // as "no telemetry was produced" rather than as a broken double.
    $this->transport = new LocalIngestRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('wires capture on a local host with no token', function () {
    expect(config('prism.token'))->toBeNull()
        ->and(app()->environment())->toBe('local')
        ->and(app()->bound(PrismServiceProvider::ACTIVE))->toBeTrue();
});

it('ships a real request lifecycle with no credential configured', function () {
    Route::get('/prism-local-probe', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

    $this->get('/prism-local-probe')->assertOk();

    $types = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $types[] = $event['type'];
        }
    }

    // The request row itself, which the capture engine writes from
    // `terminate()` — i.e. the whole lifecycle ran, was not sampled out, and
    // reached Prism's own ingest.
    expect($types)->toContain('request');
});

it('binds the browser forwarder, so browser reports are not discarded', function () {
    // The route is registered above the token gate and answers 204 whether or
    // not there is anywhere to forward to (US-005). On this path there IS, so
    // the forwarder must be bound — otherwise a token-less local install would
    // answer every browser report 204 and drop it.
    expect(app()->bound(BrowserForwarder::class))->toBeTrue();
});

/** Records the batches handed to it instead of sending them. */
final class LocalIngestRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
