<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/*
 * Browser SDK US-012 — an incoming browser `traceparent` keys the backend
 * request.
 *
 * The browser SDK mints a 32-hex trace id per page view and sends it as
 * `traceparent` on the page's same-origin `fetch` / XHR calls (US-019). Every
 * browser error and log line of that page is keyed on the same id when it is
 * forwarded through `/_prism/browser`, so the whole of browser ↔ backend
 * correlation — a JavaScript error beside the API call it made, its queries and
 * its log lines — rests on the backend ADOPTING the page's id rather than
 * minting one of its own. Nothing in Prism does that: it is keepsuit's server
 * middleware parenting the request span to the incoming context, through the
 * `tracecontext` propagator upstream defaults to and Prism deliberately does not
 * touch (US-020). Which is exactly why it needs a test of its own: the
 * mechanism is a config key nobody in this package writes, so the change that
 * severs the correlation — narrowing `opentelemetry.propagators` to `none`,
 * or Prism starting to derive it — would fail nothing else and show up only as
 * two traces that happen to be adjacent in time.
 *
 * NO ALLOW-LIST IS APPLIED TO A BROWSER-SUPPLIED TRACE ID, and none should be.
 * The header comes from a page anyone can open, so the id is untrusted — but
 * it is harmless: `trace_id` is a plain `String` column on every telemetry
 * table, every read is scoped `organization_id = ?` first, and nothing joins on
 * the trace id across tenants. The worst a forged id can do is correlate a
 * caller's own request with a page it did not come from, inside a workspace
 * the token already writes to. The only check on it is the W3C format (32 hex,
 * not all zeros), and that is the propagator's, not Prism's — see the second
 * case. US-020's `W3CPropagationTest` pins the same boundary from the
 * service-to-service side; this file is the browser's reading of it, with the
 * two assertions the SDK's correlation depends on spelt out separately.
 *
 * Runs in the three-provider host, in `installed.json` order, over a REAL
 * request through the kernel: the request record is written from
 * `terminate()`, after the request span has ended, so it is the row an
 * implementation that only read the active span would leave under a different
 * id from all of its children (US-017).
 */
beforeEach(function () {
    // The application's boot has already bound the real transport as a
    // singleton, so the recorder goes in afterwards — see PrismSpanLaneTest.
    $this->transport = new BrowserTraceparentRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

it('adopts the page trace id from an untrusted traceparent and keys the request record on it', function () {
    // Prism derives most of `opentelemetry.*` from its own config; this key it
    // writes nothing to. Upstream's default is the W3C propagator, and every
    // assertion below depends on it: a Prism that quietly narrowed this to
    // `none` — or a host that did — would leave the request under a trace of
    // its own with nothing else in this suite failing.
    expect(config('opentelemetry.propagators'))->toBe('tracecontext');

    // What the SDK mints: a 32-hex page trace id no process on the backend has
    // ever seen, carried with a 16-hex parent span id and the sampled flag.
    $pageTraceId = 'b7ad6b7169203331e5a1b3c9d8f0a2c4';
    $parentSpanId = '9c4e2a1f7d3b6e80';

    $insideRequest = null;

    Route::get('/prism-browser-traceparent-probe', function () use (&$insideRequest) {
        // Read while the request span is open — this is the id every signal
        // produced here (the query, the log line) is keyed on at the moment it
        // is produced, and what a browser error forwarded later has to match.
        $insideRequest = TraceContext::otelTraceId();

        DB::select('select 1 as one');
        Log::warning('browser traceparent probe');

        return 'ok';
    });

    $this->withHeaders(['traceparent' => '00-'.$pageTraceId.'-'.$parentSpanId.'-01'])
        ->get('/prism-browser-traceparent-probe')
        ->assertOk();

    // AC, first half: inside the request, the trace is the page's.
    expect($insideRequest)->toBe($pageTraceId);

    // AC, second half: the shipped `request` record — the row the request
    // detail screen is keyed on, written after the span has ended — names it
    // too. Exactly one request record ships for one request; asserting the
    // list rather than the first element is what says so.
    expect(browserTraceparentRecordTraceIds($this->transport->sent, 'request'))->toBe([$pageTraceId]);

    // And so does everything else the execution produced, so the page's error
    // lands beside the backend's query and log line rather than in a trace
    // one hop away from them.
    expect(browserTraceparentRecordTraceIds($this->transport->sent, 'query'))->toBe([$pageTraceId])
        ->and(browserTraceparentRecordTraceIds($this->transport->sent, 'log'))->toBe([$pageTraceId]);
});

it('starts a trace of its own when the traceparent is not a well-formed W3C header', function () {
    // The one check a browser-supplied id meets, and it is the propagator's:
    // a header the W3C grammar refuses (a trace id of the wrong width here) is
    // ignored rather than adopted verbatim, so a garbage value cannot reach the
    // `trace_id` column — while a well-formed one is never vetted further.
    $insideRequest = null;

    Route::get('/prism-browser-traceparent-malformed-probe', function () use (&$insideRequest) {
        $insideRequest = TraceContext::otelTraceId();

        return 'ok';
    });

    $this->withHeaders(['traceparent' => '00-not-a-trace-id-9c4e2a1f7d3b6e80-01'])
        ->get('/prism-browser-traceparent-malformed-probe')
        ->assertOk();

    expect($insideRequest)->toMatch('/^[0-9a-f]{32}$/')
        ->and(browserTraceparentRecordTraceIds($this->transport->sent, 'request'))->toBe([$insideRequest]);
});

/**
 * The trace id of every shipped event of one type, in shipping order.
 *
 * Uniquely named because Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<string>
 */
function browserTraceparentRecordTraceIds(array $envelopes, string $type): array
{
    $ids = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $events */
        $events = $envelope['events'];

        foreach ($events as $event) {
            if ($event['type'] === $type) {
                $ids[] = (string) $event['trace_id'];
            }
        }
    }

    return $ids;
}

/** A transport that keeps every batch instead of sending it. */
final class BrowserTraceparentRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
