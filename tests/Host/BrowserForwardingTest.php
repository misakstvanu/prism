<?php

declare(strict_types=1);

use Misakstvanu\Prism\Browser\BrowserForwarder;
use Misakstvanu\Prism\Browser\BrowserScrubber;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A browser report survives the request that carried it (US-007).
 *
 * `BrowserRouteCaptureTest` is the other half of this story and the two are only
 * meaningful together: that one proves the forwarding request's own execution is
 * refused — the report is a request the page made *because* Prism is installed,
 * so capturing it is capturing capture — and this one proves the report itself
 * still leaves. Refusing the execution is what makes shipping the report hard,
 * because `PrismServiceProvider::discardRejectedExecution()` empties the shared
 * buffer before the terminating drain: an implementation that simply added the
 * events to it would pass every unit test and deliver nothing, forever, with a
 * 204 on every post saying so.
 *
 * So it is driven over a real kernel lifecycle, through the real
 * `Laravel\Nightwatch\Core`, and asserted on EVERYTHING that left over the
 * transport minus the replica heartbeat. Both directions of the claim are in
 * that one list: the posted events are there with the trace ids the page gave
 * them, and nothing the forwarding request itself did — its query, its cache
 * read, its outgoing call, its log line, its own `request` row — is.
 */
beforeEach(function () {
    // The `web` group encrypts cookies, so a route inside it needs a key and
    // the Testbench skeleton ships none.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('prism-key-32-byt', 2))]);

    // AFTER the app has booted, so it is installed over the `Transport` that
    // `registerCapture()` bound rather than replaced by it.
    $this->transport = new BrowserForwardingRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('ships exactly the posted events and nothing the forwarding request did', function () {
    $execution = browserReportExecution();

    postBrowserReport(['events' => [
        browserEventBody('exception', ['trace_id' => 'aaaaaaaabbbbbbbbccccccccdddddddd']),
        browserEventBody('log', ['trace_id' => 'aaaaaaaabbbbbbbbccccccccdddddddd']),
        browserEventBody('page_view', ['trace_id' => '11111111222222223333333344444444']),
    ]])->assertNoContent();

    // Non-vacuity, both ways: the execution really was refused (so the events
    // below cannot have ridden the shared buffer out), and it really did produce
    // the work that refusal is throwing away.
    expect($execution->sampling)->toBeFalse();

    expect(browserForwardedTypes())->toBe(['exception', 'log', 'page_view'])
        ->and(browserForwardedTraceIds())->toBe([
            'aaaaaaaabbbbbbbbccccccccdddddddd',
            'aaaaaaaabbbbbbbbccccccccdddddddd',
            '11111111222222223333333344444444',
        ]);
});

it('marks the two signals whose table says which runtime a row came from', function () {
    postBrowserReport(['events' => [
        browserEventBody('exception'),
        browserEventBody('log'),
        browserEventBody('page_view'),
    ]])->assertNoContent();

    $payloads = array_map(
        static fn (array $event): array => is_array($event['payload'] ?? null) ? $event['payload'] : [],
        browserForwardedEvents(),
    );

    expect($payloads[0]['runtime'] ?? null)->toBe('browser')
        ->and($payloads[1]['runtime'] ?? null)->toBe('browser')
        ->and($payloads[2])->not->toHaveKey('runtime');
});

it('files browser events under no backend execution, whatever the request id was', function () {
    // The forwarding request is an execution and has an id; a browser event
    // belongs to none. Stamped, a JavaScript fault would appear among the
    // children of the POST that reported it.
    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(browserForwardedEvents()[0]['request_id'])->toBe('');
});

it('reports a broken forwarder rather than swallowing it', function () {
    // The one thing a telemetry client must never do quietly is stop working.
    // Everywhere else in this package a failed ship is logged at debug and
    // dropped, because a throw would cost the host a request it was doing real
    // work in — here the forwarding IS the request, so the throw is allowed out
    // and the host's own handler reports it.
    app()->instance(BrowserForwarder::class, new BrowserForwarder(
        static fn (EventBuffer $buffer): Flusher => throw new RuntimeException('the workspace is unreachable'),
        app('config'),
        app(BrowserScrubber::class),
    ));

    postBrowserReport(['events' => [browserEventBody('exception')]])->assertStatus(500);

    // `Core::report()` re-rolls a sampled-out execution against
    // `sampling.exceptions`, which Prism pins at 1.0 — so the execution the
    // ignore list refused flips back to sampled-in, its buffer is left alone and
    // the failure ships together with the request row that raised it.
    expect(browserForwardedTypes())
        ->toContain('exception')
        ->toContain('request');
});

/**
 * Every event that left over the transport, minus the replica heartbeat.
 *
 * `replica_metric` is the one signal with no capture engine behind it: a
 * fixed-cadence sample of the process, shipped through `writeNow()` precisely so
 * it survives an execution the sampler rejected (US-013). It says nothing about
 * the report under test and is meant to escape it.
 *
 * Named apart from the equivalents elsewhere in the suite because Pest loads
 * every test file into one process, where a redeclared function is fatal.
 *
 * @return list<array<string, mixed>>
 */
function browserForwardedEvents(): array
{
    $events = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if (($event['type'] ?? '') !== 'replica_metric') {
                $events[] = $event;
            }
        }
    }

    return $events;
}

/** @return list<string> */
function browserForwardedTypes(): array
{
    return array_map(
        static fn (array $event): string => (string) ($event['type'] ?? ''),
        browserForwardedEvents(),
    );
}

/** @return list<string> */
function browserForwardedTraceIds(): array
{
    return array_map(
        static fn (array $event): string => (string) ($event['trace_id'] ?? ''),
        browserForwardedEvents(),
    );
}

/**
 * A transport that records envelopes instead of sending them. Uniquely named for
 * the one-process reason above.
 */
final class BrowserForwardingRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
