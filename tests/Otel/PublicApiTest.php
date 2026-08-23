<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\Prism;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;

/**
 * The three things a host calls or configures directly, asserted against the
 * engines that answer them now (US-021).
 *
 * `Prism::captureException()`, `Prism::span()` and log capture were the manual
 * and semi-manual entry points into capture listeners Prism wrote itself. Those
 * listeners are deleted; the public surface is not, so each one had to change
 * producer without changing shape, and each fails silently if it did not:
 *
 *   - a manual report that reaches nothing looks exactly like an application
 *     that never threw;
 *   - a manual span that records nothing looks exactly like code that was fast;
 *   - **log capture is the one that would have gone quiet on its own**, because
 *     the capture engine registers a `nightwatch` log channel and captures
 *     nothing at all until a host adds that channel to its stack. Deleting
 *     Prism's handler without attaching the engine's would have emptied the Logs
 *     screen for every install that only ever set `PRISM_TOKEN`.
 *
 * All three run in the three-provider host, in the order `installed.json`
 * forces, and drive a real request through the kernel — the claim is about what
 * a running application ships.
 */
beforeEach(function () {
    // Bound after the app's own boot, which has already bound the real
    // transport as a singleton — see PrismSpanLaneTest.
    $this->transport = new PublicApiRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('reports a manually captured exception through the capture engine, marked handled', function () {
    Route::get('/prism-public-api-exception-probe', function () {
        Prism::captureException(new RuntimeException('handled by the application'));

        return 'ok';
    });

    $this->get('/prism-public-api-exception-probe')->assertOk();

    $exceptions = publicApiShipped($this->transport->sent, 'exception');

    expect($exceptions)->toHaveCount(1)
        ->and($exceptions[0]['payload']['message'])->toBe('handled by the application')
        ->and($exceptions[0]['payload']['class'])->toBe(RuntimeException::class)
        // `handled` is the one thing this entry point says that the automatic
        // path does not: the application dealt with this, so it is not a fault
        // that got away. It reaches the wire as the `UInt8` the column is.
        ->and($exceptions[0]['payload']['handled'])->toBe(1);
});

it('records a manual span as a real child of the request, in the lane the caller named', function () {
    Route::get('/prism-public-api-span-probe', function () {
        return Prism::span('monthly report', function (): string {
            // Nested inside, so it must come out as this span's child rather
            // than as another child of the request — which is the property the
            // hand-rolled span stack could only approximate.
            return Prism::span('warehouse rollup', fn (): string => 'rows', 'db');
        }, extra: ['account.id' => 42]);
    });

    $this->get('/prism-public-api-span-probe')->assertOk()->assertSee('rows');

    $byName = [];

    foreach (publicApiShipped($this->transport->sent, 'span') as $span) {
        $byName[(string) $span['payload']['name']] = $span;
    }

    expect($byName)->toHaveKey('monthly report')
        ->and($byName)->toHaveKey('warehouse rollup');

    $outer = $byName['monthly report'];
    $inner = $byName['warehouse rollup'];

    expect($inner['payload']['parent_span_id'])->toBe($outer['payload']['span_id'])
        ->and($inner['trace_id'])->toBe($outer['trace_id'])
        // The request span is the outer one's parent, so a manual span joins
        // the waterfall rather than starting a second root beside it.
        ->and($outer['payload']['parent_span_id'])->not->toBe('')
        // The caller's lane survives: a manual span carries none of the
        // attributes the lane rules read, so without the type attribute both of
        // these would come out `ctrl`.
        ->and($outer['payload']['type'])->toBe('ctrl')
        ->and($inner['payload']['type'])->toBe('db')
        // Extra attributes travel into the payload, where the server keeps
        // whatever has a column and drops the rest.
        ->and($outer['payload']['account.id'])->toBe(42)
        ->and($outer['payload'])->toHaveKey(PrismSpanProcessor::TYPE_ATTRIBUTE);
});

it('returns the closure value untouched when the span lane is switched off', function () {
    config(['prism.otel.enabled' => false]);

    // The contract a host relies on to wrap code unconditionally: with the lane
    // off the closure still runs and its value is still returned, it is simply
    // not recorded.
    expect(Prism::span('not recorded', fn (): int => 7))->toBe(7)
        ->and(publicApiShipped($this->transport->sent, 'span'))->toBe([]);
});

it('captures the host\'s own log lines with no config/logging.php edit', function () {
    Route::get('/prism-public-api-log-probe', function () {
        Log::warning('order placed late');

        return 'ok';
    });

    $this->get('/prism-public-api-log-probe')->assertOk();

    $logs = publicApiShipped($this->transport->sent, 'log');
    $messages = array_column(array_column($logs, 'payload'), 'message');

    // Nothing added `nightwatch` to the stack — the engine's own instruction to
    // its users, and the step an install that only set PRISM_TOKEN never took.
    expect(config('logging.channels.stack.channels'))->not->toContain('nightwatch');

    expect($messages)->toContain('order placed late');
});

it('captures each line once, in a host that DID add the engine channel to its stack', function () {
    config(['logging.channels.stack.channels' => ['single', 'nightwatch']]);

    // Re-boot so the attach runs against the stack the host just declared. A
    // stack channel is built from its children's handler INSTANCES, so the
    // engine's handler is already in the list — and a blind push would report
    // every line twice, which is the one way this wiring can be wrong that
    // still looks like it is working.
    app()->forgetInstance('log');
    (new PrismServiceProvider(app()))->boot();

    // `registerCapture()` re-binds the transport singleton, so the recorder has
    // to go back afterwards or this reads as "nothing was logged" while a real
    // `HttpTransport` quietly holds the answer.
    app()->instance(Transport::class, $this->transport);

    Route::get('/prism-public-api-log-once-probe', function () {
        Log::warning('counted once');

        return 'ok';
    });

    $this->get('/prism-public-api-log-once-probe')->assertOk();

    $logs = publicApiShipped($this->transport->sent, 'log');
    $messages = array_column(array_column($logs, 'payload'), 'message');

    expect(array_values(array_filter($messages, static fn (mixed $m): bool => $m === 'counted once')))->toHaveCount(1);
});

/**
 * Every event of one type across every batch the transport was handed. Uniquely
 * named because Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<array<string, mixed>>
 */
function publicApiShipped(array $envelopes, string $type): array
{
    $matched = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $events */
        $events = $envelope['events'];

        foreach ($events as $event) {
            if ($event['type'] === $type) {
                $matched[] = $event;
            }
        }
    }

    return $matched;
}

/**
 * A transport that keeps every batch instead of sending it, so a lifecycle test
 * can read what the request actually produced.
 */
final class PublicApiRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
