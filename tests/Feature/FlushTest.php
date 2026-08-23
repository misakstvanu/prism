<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\HttpTransport;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double that records the envelopes it is handed instead of sending
 * them, so a flush can be asserted without touching the network.
 */
class RecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public bool $result = true;

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return $this->result;
    }
}

/**
 * A flusher over the given buffer and transport, with the spool collaborators
 * resolved from the container. The default `terminate` strategy never reaches
 * them; the spool's own behaviour is covered in SpoolFlushTest.
 */
function flusherOver(EventBuffer $buffer, Transport $transport): Flusher
{
    return new Flusher(
        app('config'),
        $buffer,
        $transport,
        app(BatchSpool::class),
        app(SpoolScheduler::class),
        app(SpanFlush::class),
    );
}

/**
 * Reconfigure the app with full credentials and re-run boot() so
 * registerCapture() wires the buffer, the transport singleton, the Octane reset
 * listener and the terminating flush.
 */
function bootFlush(): void
{
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'terminate',
        'prism.batch.queue_threshold' => 0,
        // Replica health sampling (US-051) ships a metric at the end of every
        // execution; disabled here so it never inflates this file's exact counts.
        'prism.capture.metrics' => false,
    ]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);

    (new PrismServiceProvider(app()))->boot();
}

/**
 * Build an HttpTransport backed by a MockHandler. Pass a `$history` array by
 * reference to capture the requests the transport actually sends.
 *
 * @param  array<int, mixed>  $responses  MockHandler responses/exceptions.
 * @param  array<int, array<string, mixed>>  $history
 */
function mockedTransport(array $responses, array &$history = []): HttpTransport
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new HttpTransport(app('config'), new Client(['handler' => $stack]));
}

it('gzips and posts the envelope with the bearer token', function () {
    config([
        'prism.token' => 'prism_live_secret',
        'prism.endpoint' => 'https://prism.test/api/ingest',
    ]);

    $history = [];
    $transport = mockedTransport([new GuzzleResponse(202, [], '{"accepted":1}')], $history);

    $ok = $transport->send(['v' => 1, 'events' => [['type' => 'log', 'timestamp' => 't']]]);

    expect($ok)->toBeTrue()
        ->and($transport->sent())->toBe(1)
        ->and($transport->failed())->toBe(0);

    $request = $history[0]['request'];

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://prism.test/api/ingest')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer prism_live_secret')
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json');

    // The body is gzip that the server decodes the same way (US-025).
    $decoded = json_decode(gzdecode($request->getBody()->getContents()), true);

    expect($decoded)->toBe(['v' => 1, 'events' => [['type' => 'log', 'timestamp' => 't']]]);
});

it('counts and swallows a non-2xx response, logging at debug', function () {
    Log::spy();

    $transport = mockedTransport([new GuzzleResponse(500)]);

    $ok = $transport->send(['v' => 1, 'events' => []]);

    expect($ok)->toBeFalse()
        ->and($transport->sent())->toBe(0)
        ->and($transport->failed())->toBe(1);

    Log::shouldHaveReceived('debug')->once();
});

it('counts and swallows a transport timeout without throwing', function () {
    $transport = mockedTransport([
        new ConnectException('cURL error 28: timeout', new GuzzleRequest('POST', 'https://prism.test')),
    ]);

    $ok = $transport->send(['v' => 1, 'events' => []]);

    expect($ok)->toBeFalse()
        ->and($transport->failed())->toBe(1);
});

it('builds a versioned envelope, flattening events and stamping the type', function () {
    config([
        'prism.app' => 'shopfront',
        'prism.environment' => 'staging',
        'prism.replica' => 'pod-7',
        'prism.batch.queue_threshold' => 0,
    ]);

    $buffer = new EventBuffer;
    $buffer->add('request', ['path' => '/a', 'timestamp' => 't1']);
    $buffer->add('log', ['message' => 'x', 'timestamp' => 't2']);
    $buffer->add('request', ['path' => '/b', 'timestamp' => 't3']);

    $transport = new RecordingTransport;
    flusherOver($buffer, $transport)->flush();

    expect($transport->sent)->toHaveCount(1);

    $envelope = $transport->sent[0];

    expect($envelope['v'])->toBe(1)
        ->and($envelope['app'])->toBe('shopfront')
        ->and($envelope['env'])->toBe('staging')
        ->and($envelope['replica'])->toBe('pod-7')
        ->and($envelope['sent_at'])->toBeString()
        ->and($envelope['events'])->toBe([
            ['path' => '/a', 'timestamp' => 't1', 'type' => 'request'],
            ['path' => '/b', 'timestamp' => 't3', 'type' => 'request'],
            ['message' => 'x', 'timestamp' => 't2', 'type' => 'log'],
        ]);

    // Flushing drained the buffer.
    expect($buffer->isEmpty())->toBeTrue();
});

it('does nothing when the buffer is empty', function () {
    $transport = new RecordingTransport;

    flusherOver(new EventBuffer, $transport)->flush();

    expect($transport->sent)->toBeEmpty();
});

it('hands a batch over the threshold to a queued job instead of an inline send', function () {
    Queue::fake();

    config([
        'prism.batch.flush' => 'terminate',
        'prism.batch.queue_threshold' => 2,
    ]);

    $buffer = new EventBuffer;
    $buffer->add('log', ['n' => 1, 'timestamp' => 't']);
    $buffer->add('log', ['n' => 2, 'timestamp' => 't']);
    $buffer->add('log', ['n' => 3, 'timestamp' => 't']); // count 3 > threshold 2

    $transport = new RecordingTransport;
    flusherOver($buffer, $transport)->flush();

    Queue::assertPushed(SendBatchJob::class, 1);

    // Nothing went out inline, and the buffer was still drained.
    expect($transport->sent)->toBeEmpty()
        ->and($buffer->isEmpty())->toBeTrue();
});

it('sends inline under the sync strategy even over the threshold', function () {
    Queue::fake();

    config([
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 2,
    ]);

    $buffer = new EventBuffer;
    $buffer->add('log', ['n' => 1, 'timestamp' => 't']);
    $buffer->add('log', ['n' => 2, 'timestamp' => 't']);
    $buffer->add('log', ['n' => 3, 'timestamp' => 't']);

    $transport = new RecordingTransport;
    flusherOver($buffer, $transport)->flush();

    Queue::assertNothingPushed();
    expect($transport->sent)->toHaveCount(1);
});

it('runs the send through the transport when the queued job executes', function () {
    $transport = new RecordingTransport;

    (new SendBatchJob(['v' => 1, 'events' => []]))->handle($transport);

    expect($transport->sent)->toBe([['v' => 1, 'events' => []]]);
});

it('flushes and clears the buffer after the response has terminated', function () {
    bootFlush();

    $transport = new RecordingTransport;
    app()->instance(Transport::class, $transport);

    $buffer = app(EventBuffer::class);
    $buffer->add('log', ['message' => 'hi', 'timestamp' => 't']);

    app()->terminate();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['events'])->toHaveCount(1)
        ->and($buffer->isEmpty())->toBeTrue();
});

it('never lets a failed flush surface to the host application', function () {
    bootFlush();

    // A transport that fails every send — the terminating flush must swallow it.
    $transport = new RecordingTransport;
    $transport->result = false;
    app()->instance(Transport::class, $transport);

    app(EventBuffer::class)->add('log', ['message' => 'boom', 'timestamp' => 't']);

    app()->terminate(); // must not throw

    expect($transport->sent)->toHaveCount(1);
});

it('binds a shared transport singleton once capture is active', function () {
    bootFlush();

    expect(app(Transport::class))->toBeInstanceOf(HttpTransport::class)
        ->and(app(Transport::class))->toBe(app(Transport::class));
});

it('resets the buffer at the start of each Octane request', function () {
    bootFlush();

    $buffer = app(EventBuffer::class);
    $buffer->add('log', ['message' => 'leftover', 'timestamp' => 't']);
    expect($buffer->count())->toBe(1);

    // Octane starts the next request in the same long-lived worker.
    event('Laravel\Octane\Events\RequestReceived');

    expect($buffer->isEmpty())->toBeTrue();
});

it('does not leak events between two Octane requests', function () {
    bootFlush();

    $transport = new RecordingTransport;
    app()->instance(Transport::class, $transport);

    $buffer = app(EventBuffer::class);

    // Request 1.
    event('Laravel\Octane\Events\RequestReceived');
    $buffer->add('log', ['message' => 'req1', 'timestamp' => 't']);
    app()->terminate();

    // Request 2 in the same worker process.
    event('Laravel\Octane\Events\RequestReceived');
    $buffer->add('log', ['message' => 'req2', 'timestamp' => 't']);
    app()->terminate();

    expect($transport->sent)->toHaveCount(2)
        ->and($transport->sent[0]['events'])->toHaveCount(1)
        ->and($transport->sent[1]['events'])->toHaveCount(1)
        ->and($transport->sent[0]['events'][0]['message'])->toBe('req1')
        ->and($transport->sent[1]['events'][0]['message'])->toBe('req2');
});

it('keeps in-request overhead under 1ms for a request producing 50 events', function () {
    // The in-request cost of monitoring is only the buffering — the flush runs
    // at terminate, after the response is sent. Measure 50 buffered events.
    $buffer = new EventBuffer(capacity: 1000);

    // Warm up so the timing reflects steady-state work, not first-call cost.
    for ($i = 0; $i < 50; $i++) {
        $buffer->add('request', ['warm' => $i]);
    }
    $buffer->clear();

    $start = hrtime(true);
    for ($i = 0; $i < 50; $i++) {
        $buffer->add('request', ['path' => '/api/orders', 'timestamp' => 't', 'i' => $i]);
    }
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect($buffer->count())->toBe(50)
        ->and($elapsedMs)->toBeLessThan(1.0);
});
