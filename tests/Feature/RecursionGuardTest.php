<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Contracts\PrismInternal;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Transport\HttpTransport;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double that records the envelopes it is handed AND emits a debug
 * log during the send — exactly as {@see HttpTransport} logs its own activity —
 * so the guard's suppression of the package's own log output is exercised.
 */
class GuardTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public bool $result = true;

    public function send(array $envelope): bool
    {
        // The real transport logs at debug level on the send path; a stand-in
        // log capture listener must recognise this as the package's own.
        Log::debug('Prism transport activity while shipping a batch.');

        $this->sent[] = $envelope;

        return $this->result;
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * wires the buffer, the transport singleton, the reset listeners and the
 * terminating flush. Named apart from FlushTest's bootFlush() so the two files
 * do not redeclare a global function.
 */
function bootGuardedCapture(): void
{
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'terminate',
        'prism.batch.queue_threshold' => 0,
        // Real log capture (US-044) is disabled here so this file's stand-in
        // MessageLogged listener is the only thing that buffers a log line —
        // otherwise the AC5/AC6 tests below would double-count every Log::info().
        'prism.capture.logs' => false,
        // Replica health sampling (US-051) piggybacks a metric onto every flush;
        // disabled here so it never inflates this file's exact event counts.
        'prism.capture.metrics' => false,
    ]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);

    Recursion::reset();

    (new PrismServiceProvider(app()))->boot();
}

/**
 * Register a stand-in for the future log capture listener (US-045): it records
 * every log into the buffer, but consults the recursion guard so the package's
 * own log lines are never captured. Returns the buffer it writes into.
 */
function fakeLogCapture(): EventBuffer
{
    $buffer = app(EventBuffer::class);

    Event::listen(MessageLogged::class, function (MessageLogged $log) use ($buffer): void {
        if (Recursion::suppressed()) {
            return;
        }

        $buffer->add('log', ['message' => $log->message, 'timestamp' => 't']);
    });

    return $buffer;
}

afterEach(fn () => Recursion::reset());

// -- AC1: outbound calls are marked and recognisable ------------------------

it('stamps the internal marker header on every outbound ingest call', function () {
    config([
        'prism.token' => 'prism_live_secret',
        'prism.endpoint' => 'https://prism.test/api/ingest',
    ]);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(202, [], '{"accepted":1}')]));
    $stack->push(Middleware::history($history));
    $transport = new HttpTransport(app('config'), new Client(['handler' => $stack]));

    $transport->send(['v' => 1, 'events' => []]);

    $request = $history[0]['request'];

    expect($request->getHeaderLine(Recursion::MARKER_HEADER))->toBe('1')
        ->and(Recursion::isInternalRequest($request->getHeaders()))->toBeTrue();
});

it('recognises the internal marker header regardless of case', function () {
    expect(Recursion::isInternalRequest(['X-Prism-Internal' => ['1']]))->toBeTrue()
        ->and(Recursion::isInternalRequest(['x-prism-internal' => '1']))->toBeTrue()
        ->and(Recursion::isInternalRequest(['Accept' => 'application/json']))->toBeFalse()
        ->and(Recursion::isInternalRequest([]))->toBeFalse();
});

// -- AC2: the package's own queued job is excluded from job capture ---------

it('recognises the flush job as internal so job capture skips it', function () {
    $job = new SendBatchJob(['v' => 1, 'events' => []]);

    expect($job)->toBeInstanceOf(PrismInternal::class)
        ->and(Recursion::isInternalJob($job))->toBeTrue();

    // A host application job is not internal, so it is captured normally.
    $hostJob = new class
    {
        public function handle(): void {}
    };

    expect(Recursion::isInternalJob($hostJob))->toBeFalse();
});

// -- AC3/AC4: suppression scope brackets the package's own work -------------

it('raises and lowers the suppression flag around package work, nesting safely', function () {
    expect(Recursion::suppressed())->toBeFalse();

    $result = Recursion::suppress(function () {
        expect(Recursion::suppressed())->toBeTrue();

        Recursion::suppress(function () {
            expect(Recursion::suppressed())->toBeTrue();
        });

        // Still suppressed after the inner scope returns.
        expect(Recursion::suppressed())->toBeTrue();

        return 'done';
    });

    expect($result)->toBe('done')
        ->and(Recursion::suppressed())->toBeFalse();
});

it('restores the suppression flag even when the callback throws', function () {
    expect(fn () => Recursion::suppress(function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(Recursion::suppressed())->toBeFalse();
});

it('treats an exception thrown during package work as internal, a host exception as external', function () {
    // Outside a suppress scope an application exception is external — it must be
    // captured and reported.
    expect(Recursion::isInternalException(new RuntimeException('app failure')))->toBeFalse();

    // Thrown while the package is doing its own work, it is internal.
    Recursion::suppress(function () {
        expect(Recursion::isInternalException(new RuntimeException('while flushing')))->toBeTrue();
    });

    // A package exception class is internal wherever it surfaces (the guard's
    // own namespace stands in for one — any Misakstvanu\Prism\* throwable).
    $packageThrowable = new class('internal') extends RuntimeException implements PrismInternal {};
    expect(Recursion::isInternalException($packageThrowable))->toBeTrue();
});

// -- AC5: a flush that itself fails produces no new events -------------------

it('produces no new events in the buffer when the flush itself fails', function () {
    bootGuardedCapture();

    $buffer = fakeLogCapture();

    // A transport that logs (as the real one does) and reports failure.
    $transport = new GuardTransport;
    $transport->result = false;
    app()->instance(Transport::class, $transport);

    // The application produced one event.
    $buffer->add('request', ['path' => '/checkout', 'timestamp' => 't']);
    expect($buffer->count())->toBe(1);

    app()->terminate(); // flush fails internally; must not throw

    // The flush drained the buffer, and the transport's own debug log during
    // the (failed) send was recognised as internal and added nothing back.
    expect($transport->sent)->toHaveCount(1)
        ->and($buffer->isEmpty())->toBeTrue();
});

// -- AC6: a full round trip ships exactly the application's events -----------

it('ships exactly the events the application generated and nothing of its own', function () {
    bootGuardedCapture();

    $buffer = fakeLogCapture();

    $transport = new GuardTransport;
    app()->instance(Transport::class, $transport);

    // The application generates three events: two requests and one log line
    // (the log is captured by the guarded stand-in listener).
    $buffer->add('request', ['path' => '/a', 'timestamp' => 't']);
    $buffer->add('request', ['path' => '/b', 'timestamp' => 't']);
    Log::info('order placed');

    expect($buffer->count())->toBe(3);

    app()->terminate(); // ships the batch; its own send logs are suppressed

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['events'])->toHaveCount(3)
        ->and($buffer->isEmpty())->toBeTrue();
});
