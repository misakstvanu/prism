<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;
use OpenTelemetry\API\Trace\Span;

/*
 * US-020 — one trace across services and across the queue, carried by W3C
 * `traceparent`.
 *
 * Until this story Prism propagated a trace itself, over an `X-Prism-Trace-Id`
 * header and a `prism_trace_id` key in a job payload. Both worked and neither
 * interoperated: a Prism-monitored Laravel app calling a Go service produced two
 * traces, because nothing on the other side had heard of the header. The span
 * lane already speaks the standard every APM and every language SDK speaks, so
 * the bespoke pair is gone and this file is what says the replacement really
 * carries the same three boundaries.
 *
 * It runs in the three-provider host, in the order `installed.json` forces, and
 * drives REAL requests through the kernel. Nothing here can be asserted from the
 * package's own single-provider suite: the propagation belongs to the span lane,
 * and `tests/Feature/TraceContextTest.php` can only assert the *removal*.
 */
beforeEach(function () {
    // The application's boot has already bound the real transport as a
    // singleton, so the recorder goes in afterwards — see PrismSpanLaneTest.
    $this->transport = new W3CPropagationRecordingTransport;

    app()->instance(Transport::class, $this->transport);

    W3CPropagationProbeJob::$trace = null;
});

it('leaves the propagators at tracecontext, so a trace travels as traceparent', function () {
    // Prism derives most of `opentelemetry.*` from its own config; this key it
    // deliberately does not touch. Upstream's default is the W3C propagator, and
    // the whole story is that Prism now relies on it rather than on a header of
    // its own — so a Prism that quietly narrowed this to `none` would take out
    // every boundary below with nothing else failing.
    expect(config('opentelemetry.propagators'))->toBe('tracecontext');
});

it('continues an inbound traceparent rather than starting a trace of its own', function () {
    Route::get('/prism-w3c-inbound-probe', function () {
        // One record from each side of the seam: a signal produced while the
        // request span is open, and the request record itself, which the
        // capture engine writes from `terminate()` after that span has ended.
        DB::select('select 1 as one');
        Log::warning('inbound w3c probe');

        return 'ok';
    });

    // The W3C specification's own example ids.
    $upstream = '4bf92f3577b34da6a3ce929d0e0e4736';

    $this->withHeaders(['traceparent' => '00-'.$upstream.'-00f067aa0ba902b7-01'])
        ->get('/prism-w3c-inbound-probe')
        ->assertOk();

    // Every signal this execution shipped names the caller's trace, which is
    // what makes the two services one trace on the Traces screen rather than
    // two that happen to be adjacent in time.
    expect(w3cTraceIds($this->transport->sent))->toBe([$upstream]);
});

it('carries one trace from the request through an outgoing call into a dispatched job', function () {
    Http::fake(['downstream.test/*' => Http::response('ok')]);

    $payloads = [];

    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payloads) {
        $payloads[] = $event->job->payload();
    });

    Route::get('/prism-w3c-chain-probe', function () {
        Http::get('https://downstream.test/api/resource');

        W3CPropagationProbeJob::dispatch();

        return 'ok';
    });

    $this->get('/prism-w3c-chain-probe')->assertOk();

    // AC: request → outgoing HTTP → dispatched job, one id. Asserted as a set
    // over everything shipped, so a lane that quietly kept an id of its own
    // fails here rather than in whichever screen a reader opens next.
    $traced = w3cTraceIds($this->transport->sent);

    expect($traced)->toHaveCount(1);

    $traceId = $traced[0];

    expect($traceId)->toMatch('/^[0-9a-f]{32}$/');

    // The outgoing call: the standard header, naming this trace, so a service
    // in any language continues it.
    Http::assertSent(fn ($request) => is_string($header = $request->header('traceparent')[0] ?? null)
        && str_starts_with($header, '00-'.$traceId.'-'));

    // The queue hand-off, both halves. The payload is the wire — a worker in
    // another process reads exactly this — and the job's own handler read the
    // trace it ran under.
    expect($payloads)->not->toBeEmpty();

    $traceparent = $payloads[0]['traceparent'] ?? null;

    expect($traceparent)->toBeString()
        ->and($traceparent)->toStartWith('00-'.$traceId.'-')
        ->and(W3CPropagationProbeJob::$trace)->toBe($traceId);

    // And the link itself is keepsuit's PRODUCER/CONSUMER pair, which is what
    // draws the hand-off on the waterfall rather than leaving two traces that
    // merely share an id.
    $names = array_map(
        static fn (array $span): string => (string) $span['payload']['name'],
        w3cSpans($this->transport->sent),
    );

    expect(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'send ')))->not->toBeEmpty()
        ->and(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'process ')))->not->toBeEmpty();
});

it('re-opens the dispatching trace in a worker that has no span of its own', function () {
    $payload = null;

    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payload) {
        $payload ??= $event->job->payload();
    });

    Route::get('/prism-w3c-worker-probe', function () {
        W3CPropagationProbeJob::dispatch();

        return 'ok';
    });

    $this->get('/prism-w3c-worker-probe')->assertOk();

    $traceId = w3cTraceIds($this->transport->sent)[0] ?? null;

    expect($traceId)->toBeString()
        ->and($payload)->toBeArray();

    // The other side of the boundary. Laravel's own context repository is
    // flushed first, because it survives a request in this process and would
    // otherwise answer for the trace the payload is supposed to be carrying —
    // which is exactly how this assertion becomes vacuous.
    Context::flush();

    expect(w3cActiveTraceId())->toBeNull()
        ->and(TraceContext::otelTraceId())->toBeNull();

    $job = w3cWorkerJob($payload);

    event(new JobProcessing('redis', $job));

    // The assertion is on the ACTIVE SPAN, not on `TraceContext`. Laravel
    // dehydrates its own context into every job payload and hydrates it here,
    // so `otelTraceId()`'s second answer would report the right id with the
    // queue instrumentation switched off entirely — the US-017 mechanism
    // passing for the US-020 claim. A live span in the worker can only have
    // come from the `traceparent` keepsuit wrote into the payload and the
    // CONSUMER span it parents there, which is what draws the hand-off on the
    // waterfall rather than leaving two traces that merely share an id.
    expect(w3cActiveTraceId())->toBe($traceId)
        ->and(TraceContext::otelTraceId())->toBe($traceId);

    // Close the consumer span rather than leaving a scope attached: the SDK's
    // context storage is process-global and would carry into the next test.
    event(new JobProcessed('redis', $job));
});

/**
 * Every non-blank trace id in every batch the transport was handed, deduped.
 *
 * Blank is a real answer and is filtered rather than asserted on: a
 * `replica_metric` names no execution at all (US-017), so it names no trace
 * either.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<string>
 */
function w3cTraceIds(array $envelopes): array
{
    $ids = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $events */
        $events = $envelope['events'];

        foreach ($events as $event) {
            if (is_string($traceId = $event['trace_id']) && $traceId !== '') {
                $ids[$traceId] = true;
            }
        }
    }

    return array_keys($ids);
}

/**
 * Every `span` event in every batch the transport was handed.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return list<array<string, mixed>>
 */
function w3cSpans(array $envelopes): array
{
    $spans = [];

    foreach ($envelopes as $envelope) {
        /** @var list<array<string, mixed>> $events */
        $events = $envelope['events'];

        foreach ($events as $event) {
            if ($event['type'] === 'span') {
                $spans[] = $event;
            }
        }
    }

    return $spans;
}

/**
 * The trace of whatever OpenTelemetry span is active right now, or null.
 *
 * Deliberately not {@see TraceContext::otelTraceId()}: that method has a second
 * answer (Laravel's context, US-017) which crosses the same boundary by a
 * different route, so only the live span distinguishes the claim under test.
 */
function w3cActiveTraceId(): ?string
{
    $context = Span::getCurrent()->getContext();

    return $context->isValid() ? $context->getTraceId() : null;
}

/**
 * A queue Job double carrying a real dispatch payload, stubbing exactly the
 * accessors the span lane's CONSUMER span reads off it.
 *
 * @param  array<string, mixed>  $payload
 */
function w3cWorkerJob(array $payload): Job
{
    $job = Mockery::mock(Job::class);

    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('getRawBody')->andReturn((string) json_encode($payload));
    $job->shouldReceive('uuid')->andReturn('w3c-worker-job-uuid');
    $job->shouldReceive('resolveName')->andReturn(W3CPropagationProbeJob::class);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('maxExceptions')->andReturn(null);
    $job->shouldReceive('maxTries')->andReturn(null);
    $job->shouldReceive('retryUntil')->andReturn(null);
    $job->shouldReceive('timeout')->andReturn(null);
    $job->shouldReceive('hasFailed')->andReturn(false);

    return $job;
}

/**
 * A queued job that records the trace it ran under, so a test can read what a
 * dispatched job's own signals would be keyed on.
 */
class W3CPropagationProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?string $trace = null;

    public function handle(): void
    {
        self::$trace = TraceContext::traceId();
    }
}

/** A transport that keeps every batch instead of sending it. */
final class W3CPropagationRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
