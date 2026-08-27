<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;

/**
 * The browser reporting endpoint's own execution is never captured (US-005).
 *
 * This is the self-monitoring loop one hop further out than the ingest POST.
 * A browser report is a request the page made *because* Prism is installed:
 * left captured it becomes a `request` row, plus the session read, the scrub
 * pass and the queue dispatch the forwarding does — telemetry about telemetry,
 * on a route a page in a bad state hits once per error. On the dogfooded
 * install (US-037) the console's own frontend reports here, so the loop closes
 * on itself immediately.
 *
 * Asserted through the REAL `Laravel\Nightwatch\Core`, driven over a real
 * request, because the failure mode is silent in both directions. A
 * `dontSample()` made ahead of the engine's own global middleware is overwritten
 * a moment later with nothing to see; and asserting only that no `request` row
 * shipped is a false green, because the engine writes that row in a
 * request-lifecycle handler that runs *after* the discard — every query, cache
 * read, log line and outgoing call the execution made would still go out. That
 * exact false green survived until a dogfooded install noticed it (US-024), so
 * the assertion here is over EVERYTHING shipped and the request is given real
 * work to be wrong about.
 */
beforeEach(function () {
    // The `web` group encrypts cookies, so a route inside it needs a key and
    // the Testbench skeleton ships none.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('prism-key-32-byt', 2))]);

    $this->transport = new BrowserRouteRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('registers the reporting endpoint on a host that has the capture engine too', function () {
    // Non-vacuity for everything below: a 404 would ship nothing either.
    postBrowserReport()->assertNoContent();
});

it('refuses to sample the report execution, past the middleware that re-samples', function () {
    // The ordering trap, asserted where it bites. Nightwatch PREPENDS its own
    // global middleware, whose handle() calls sample() — so Prism's refusal has
    // to be pushed onto the END of the global stack or it is silently undone.
    $execution = browserReportExecution();

    postBrowserReport()->assertNoContent();

    expect($execution->sampling)->toBeFalse();
});

it('ships nothing at all about a browser report', function () {
    browserReportExecution();

    postBrowserReport()->assertNoContent();

    expect(browserRouteShippedSignals())->toBeEmpty();
});

it('ships the same work on a path nothing silenced, so the absence above means something', function () {
    browserReportExecution();

    Route::post('/orders', fn () => 'ok')->middleware('web');

    $this->post('/orders')->assertOk();

    expect(browserRouteShippedTypes())
        ->toContain('request')
        ->toContain('log')
        ->toContain('query')
        // Both the cache read and the outgoing call translate to a `span`.
        ->toContain('span');
});

/**
 * The Prism event types that actually left over the transport.
 *
 * Named apart from the equivalents elsewhere in the suite because Pest loads
 * every test file into one process, where a redeclared function is fatal.
 *
 * @return list<string>
 */
function browserRouteShippedTypes(): array
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
 * fixed-cadence sample of the process shipped through `writeNow()` precisely so
 * it survives an execution the sampler rejected (US-013). It says nothing about
 * the execution under test and is meant to escape it.
 *
 * @return list<string>
 */
function browserRouteShippedSignals(): array
{
    return array_values(array_filter(
        browserRouteShippedTypes(),
        static fn (string $type): bool => $type !== 'replica_metric',
    ));
}

/**
 * A transport that records envelopes instead of sending them. Uniquely named
 * for the one-process reason above; {@see PrismServiceProvider} rebinds
 * `Transport` on every boot, so it is installed after any re-boot a test does.
 */
final class BrowserRouteRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
