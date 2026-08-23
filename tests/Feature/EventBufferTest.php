<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Buffer\FlushedBatch;
use Misakstvanu\Prism\PrismServiceProvider;

/**
 * Reconfigure the app with a full credential set and re-run boot() so
 * registerCapture() wires the buffer singleton and the JobProcessing listener.
 * The default Testbench environment has no token, so capture is otherwise inert.
 */
function bootCapture(int $batchSize = 1000): void
{
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.batch.size' => $batchSize,
    ]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    (new PrismServiceProvider(app()))->boot();
}

it('accumulates events grouped by type', function () {
    $buffer = new EventBuffer;

    $buffer->add('request', ['path' => '/a']);
    $buffer->add('log', ['message' => 'x']);
    $buffer->add('request', ['path' => '/b']);

    expect($buffer->count())->toBe(3)
        ->and($buffer->dropped())->toBe(0)
        ->and($buffer->all())->toBe([
            'request' => [['path' => '/a'], ['path' => '/b']],
            'log' => [['message' => 'x']],
        ]);
});

it('defaults to a capacity of 1000', function () {
    expect((new EventBuffer)->capacity())->toBe(1000)
        ->and((new EventBuffer)->isEmpty())->toBeTrue();
});

it('drops events past the configured cap and counts them', function () {
    $buffer = new EventBuffer(capacity: 2);

    $buffer->add('log', ['n' => 1]);
    $buffer->add('log', ['n' => 2]);
    $buffer->add('log', ['n' => 3]); // over cap → dropped
    $buffer->add('log', ['n' => 4]); // over cap → dropped

    expect($buffer->count())->toBe(2)
        ->and($buffer->dropped())->toBe(2)
        ->and($buffer->all()['log'])->toHaveCount(2);
});

it('treats a zero or negative capacity as unbounded', function () {
    $buffer = new EventBuffer(capacity: 0);

    foreach (range(1, 5000) as $n) {
        $buffer->add('log', ['n' => $n]);
    }

    expect($buffer->count())->toBe(5000)
        ->and($buffer->dropped())->toBe(0);
});

it('flushes the buffered events and dropped count, then clears', function () {
    $buffer = new EventBuffer(capacity: 1);
    $buffer->add('log', ['n' => 1]);
    $buffer->add('log', ['n' => 2]); // over cap → dropped

    $batch = $buffer->flush();

    expect($batch)->toBeInstanceOf(FlushedBatch::class)
        ->and($batch->count)->toBe(1)
        ->and($batch->dropped)->toBe(1)
        ->and($batch->isEmpty())->toBeFalse()
        ->and($batch->events)->toBe(['log' => [['n' => 1]]]);

    // Draining leaves the buffer empty — both counters reset.
    expect($buffer->isEmpty())->toBeTrue()
        ->and($buffer->count())->toBe(0)
        ->and($buffer->dropped())->toBe(0)
        ->and($buffer->all())->toBe([]);
});

it('registers the buffer as a config-sized singleton when capture is active', function () {
    bootCapture(batchSize: 5);

    $a = app(EventBuffer::class);
    $b = app(EventBuffer::class);

    expect($a)->toBeInstanceOf(EventBuffer::class)
        ->and($a)->toBe($b)            // same instance — a singleton
        ->and($a->capacity())->toBe(5); // capacity read from prism.batch.size
});

it('does not register the buffer when capture is inert', function () {
    // Default Testbench boot: enabled but no token → registerCapture() is never
    // reached, so the buffer is not bound.
    expect(app()->bound(EventBuffer::class))->toBeFalse();
});

it('clears the buffer between two consecutive queue jobs in the same worker', function () {
    bootCapture();

    $buffer = app(EventBuffer::class);

    // Job 1 runs and buffers some telemetry.
    $buffer->add('log', ['message' => 'from job 1']);
    $buffer->add('query', ['sql' => 'select 1']);
    expect($buffer->count())->toBe(2);

    // Job 2 begins in the same long-lived worker process.
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    // `shouldIgnoreMissing()` answers null, and the reject listener the provider
    // registers reads this one for real — a Job always names itself.
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\PriceOrder');
    event(new JobProcessing('redis', $job));

    // Job 2 sees a clean buffer — none of job 1's events leaked across.
    expect($buffer->count())->toBe(0)
        ->and($buffer->all())->toBe([]);
});
