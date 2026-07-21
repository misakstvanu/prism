<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\JobCapture;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double that records the envelopes it is handed, so the flush the
 * provider runs at the end of each job (AC5) can be asserted without a network.
 * Uniquely named — Pest loads every test file into one process, so it must not
 * collide with FlushTest's `RecordingTransport`.
 */
class JobCaptureTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * binds the JobCapture singleton and wires the four job listeners. The flush
 * strategy is `sync`, and a recording transport is bound so the per-job flush is
 * captured — job telemetry ships through it rather than staying on the buffer.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootJobs(array $overrides = []): JobCaptureTransport
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'worker-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.jobs' => true,
        'prism.ignore.jobs' => [],
        // Replica health sampling (US-051) piggybacks a metric onto every flush;
        // disabled here so it never inflates this file's exact event counts.
        'prism.capture.metrics' => false,
    ], $overrides));

    Recursion::reset();
    TraceContext::reset();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);
    app()->forgetInstance(JobCapture::class);

    (new PrismServiceProvider(app()))->boot();

    $transport = new JobCaptureTransport;
    app()->instance(Transport::class, $transport);

    return $transport;
}

/**
 * A queue Job double stubbing exactly the accessors JobCapture reads.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeJob(
    array $payload = [],
    string $name = 'App\\Jobs\\SendWelcomeEmail',
    string $queue = 'emails',
    int $attempts = 1,
    string $uuid = 'job-uuid-1',
): Job {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $job->shouldReceive('payload')->andReturn($payload);

    return $job;
}

/**
 * The first buffered-then-shipped `job` event across everything the transport
 * received, or null when none was captured.
 *
 * @return array<string, mixed>|null
 */
function capturedJob(JobCaptureTransport $transport): ?array
{
    foreach ($transport->sent as $envelope) {
        foreach (($envelope['events'] ?? []) as $event) {
            if (($event['type'] ?? null) === 'job') {
                return $event;
            }
        }
    }

    return null;
}

afterEach(function () {
    Carbon::setTestNow();
    Queue::createPayloadUsing(null);
});

// -- AC1/AC2: the captured fields -------------------------------------------

it('captures the job class, queue, connection, uuid, attempt number and status', function () {
    $transport = bootJobs();

    $job = fakeJob(name: 'App\\Jobs\\SendWelcomeEmail', queue: 'emails', attempts: 2, uuid: 'uuid-abc');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    $payload = capturedJob($transport)['payload'];

    expect($payload['job_class'])->toBe('App\\Jobs\\SendWelcomeEmail')
        ->and($payload['queue'])->toBe('emails')
        ->and($payload['connection'])->toBe('redis')
        ->and($payload['uuid'])->toBe('uuid-abc')
        ->and($payload['attempts'])->toBe(2)
        ->and($payload['status'])->toBe('processed');
});

it('records the raw job payload', function () {
    $transport = bootJobs();

    $job = fakeJob(payload: ['displayName' => 'App\\Jobs\\SendWelcomeEmail', 'data' => ['command' => 'serialized']]);
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(capturedJob($transport)['payload']['payload'])
        ->toBe(['displayName' => 'App\\Jobs\\SendWelcomeEmail', 'data' => ['command' => 'serialized']]);
});

// -- AC3: failures carry the full exception with stack trace ----------------

it('records a failed job with its full exception and stack trace', function () {
    $transport = bootJobs();

    $job = fakeJob();
    $exception = new RuntimeException('payment gateway timed out');

    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $exception));

    $payload = capturedJob($transport)['payload'];

    expect($payload['status'])->toBe('failed')
        ->and($payload['exception'])->toContain('RuntimeException')
        ->and($payload['exception'])->toContain('payment gateway timed out')
        ->and($payload['exception'])->toContain('Stack trace');
});

it('records a released job as a retry', function () {
    $transport = bootJobs();

    $job = fakeJob(attempts: 1);
    event(new JobProcessing('redis', $job));
    event(new JobReleasedAfterException('redis', $job));

    expect(capturedJob($transport)['payload']['status'])->toBe('retried');
});

// -- AC4: queue wait is dispatch-to-processing-start, for a delayed job ------

it('measures queue wait from dispatch to processing start for a delayed job', function () {
    $transport = bootJobs();

    // Dispatched at 12:00:00, sat in the queue, picked up 5 seconds later.
    $dispatchedAt = Carbon::create(2026, 7, 21, 12, 0, 0);
    $queuedAt = $dispatchedAt->copy()->getPreciseTimestamp(6);

    Carbon::setTestNow($dispatchedAt->copy()->addSeconds(5));

    $job = fakeJob(payload: [JobCapture::QUEUED_AT_KEY => $queuedAt]);
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(capturedJob($transport)['payload']['queue_wait_ms'])->toBe(5000.0);
});

it('falls back to the framework createdAt timestamp for queue wait', function () {
    $transport = bootJobs();

    $dispatchedAt = Carbon::create(2026, 7, 21, 12, 0, 0);
    Carbon::setTestNow($dispatchedAt->copy()->addSeconds(3));

    $job = fakeJob(payload: ['createdAt' => $dispatchedAt->copy()->getTimestamp()]);
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(capturedJob($transport)['payload']['queue_wait_ms'])->toBe(3000.0);
});

// -- runtime is processing-start to completion ------------------------------

it('measures the runtime from processing start to completion', function () {
    $transport = bootJobs();

    $start = Carbon::create(2026, 7, 21, 12, 0, 0);
    Carbon::setTestNow($start->copy());

    $job = fakeJob();
    event(new JobProcessing('redis', $job));

    Carbon::setTestNow($start->copy()->addMilliseconds(250));
    event(new JobProcessed('redis', $job));

    expect(capturedJob($transport)['payload']['runtime_ms'])->toBe(250.0);
});

// -- AC5: telemetry ships at the end of each job, not accumulated -----------

it('flushes a job event at the end of the job', function () {
    $transport = bootJobs();

    $job = fakeJob();
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect($transport->sent)->toHaveCount(1)
        ->and(app(EventBuffer::class)->isEmpty())->toBeTrue()
        ->and($transport->sent[0]['events'])->toHaveCount(1)
        ->and($transport->sent[0]['events'][0]['type'])->toBe('job');
});

it('ships each job separately rather than accumulating across a worker', function () {
    $transport = bootJobs();

    $first = fakeJob(uuid: 'uuid-1');
    event(new JobProcessing('redis', $first));
    event(new JobProcessed('redis', $first));

    $second = fakeJob(uuid: 'uuid-2');
    event(new JobProcessing('redis', $second));
    event(new JobFailed('redis', $second, new RuntimeException('boom')));

    expect($transport->sent)->toHaveCount(2)
        ->and($transport->sent[0]['events'][0]['payload']['status'])->toBe('processed')
        ->and($transport->sent[1]['events'][0]['payload']['status'])->toBe('failed');
});

// -- AC / correlation: the trace id rides the envelope ----------------------

it('correlates a job to the trace it runs under', function () {
    $transport = bootJobs();

    // The originating trace rides the payload from dispatch (US-041); the
    // JobProcessing listener continues it, so the job event carries it.
    $job = fakeJob(payload: [TraceContext::JOB_PAYLOAD_KEY => 'trace-from-dispatch']);
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    $event = capturedJob($transport);

    expect($event['trace_id'])->toBe('trace-from-dispatch')
        ->and($event['timestamp'])->toBeString()
        ->and($event)->toHaveKey('user_id');
});

// -- scrubbing: a sensitive payload key is redacted at the source -----------

it('scrubs a sensitive key in the job payload', function () {
    $transport = bootJobs();

    $job = fakeJob(payload: ['data' => ['command' => ['password' => 'hunter2', 'email' => 'a@b.test']]]);
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    $command = capturedJob($transport)['payload']['payload']['data']['command'];

    expect($command['password'])->toBe(Scrubber::REDACTED)
        ->and($command['email'])->toBe('a@b.test');
});

// -- the package's own jobs and ignored classes are never captured ----------

it('never captures the package\'s own jobs', function () {
    $transport = bootJobs();

    $job = fakeJob(name: 'Misakstvanu\\Prism\\Jobs\\SendBatchJob');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect($transport->sent)->toBeEmpty()
        ->and(app(EventBuffer::class)->count())->toBe(0);
});

it('never captures a job class on the ignore list', function () {
    $transport = bootJobs(['prism.ignore.jobs' => ['App\\Jobs\\NoisyInternalJob']]);

    $job = fakeJob(name: 'App\\Jobs\\NoisyInternalJob');
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect($transport->sent)->toBeEmpty()
        ->and(app(EventBuffer::class)->count())->toBe(0);
});

it('never captures a job while the package is doing its own work', function () {
    bootJobs();

    // A terminal event alone, so the JobProcessing reset does not clear the
    // suppression scope before it is checked.
    Recursion::suppress(function () {
        event(new JobFailed('redis', fakeJob(), new RuntimeException('flush-internal')));
    });

    expect(app(EventBuffer::class)->count())->toBe(0);
});

// -- capture is skipped entirely when disabled ------------------------------

it('registers no job listeners and binds nothing when job capture is disabled', function () {
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.batch.flush' => 'sync',
        'prism.capture.jobs' => false,
    ]);
    Recursion::reset();
    TraceContext::reset();
    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(JobCapture::class);

    $before = count(app('events')->getRawListeners()[JobProcessed::class] ?? []);
    (new PrismServiceProvider(app()))->boot();
    $after = count(app('events')->getRawListeners()[JobProcessed::class] ?? []);

    expect($after)->toBe($before)
        ->and(app()->bound(JobCapture::class))->toBeFalse();

    $job = fakeJob();
    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    expect(app(EventBuffer::class)->count())->toBe(0);
});
