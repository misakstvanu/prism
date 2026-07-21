<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Capture\ScheduleCapture;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double recording the envelopes it is handed, so the per-task flush
 * the provider runs at the end of each scheduled task (AC5) can be asserted with
 * no network. Uniquely named — Pest loads every test file into one process, so it
 * must not collide with the transport doubles in the other capture tests.
 */
class ScheduleCaptureTransport implements Transport
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
 * binds the ScheduleCapture singleton and wires the three schedule listeners. The
 * flush strategy is `sync` and a recording transport is bound so each per-task
 * flush is captured — schedule telemetry ships through it rather than staying on
 * the buffer.
 *
 * @param  array<string, mixed>  $overrides
 */
function bootSchedules(array $overrides = []): ScheduleCaptureTransport
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'worker-1',
        'prism.batch.flush' => 'sync',
        'prism.batch.queue_threshold' => 0,
        'prism.capture.schedules' => true,
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
    app()->forgetInstance(ScheduleCapture::class);

    (new PrismServiceProvider(app()))->boot();

    $transport = new ScheduleCaptureTransport;
    app()->instance(Transport::class, $transport);

    return $transport;
}

/**
 * A command (exec) scheduled task with an optional description and output file.
 */
function scheduleCommandTask(
    string $command = 'php artisan backup:run',
    string $expression = '0 3 * * *',
    ?string $description = null,
    ?string $output = null,
): ScheduledEvent {
    $task = app(Schedule::class)->exec($command)->cron($expression);

    if ($description !== null) {
        $task->description($description);
    }

    if ($output !== null) {
        $task->sendOutputTo($output);
    }

    return $task;
}

/** A closure scheduled task with an optional name (its display description). */
function scheduleClosureTask(?string $name = null, string $expression = '0 * * * *'): ScheduledEvent
{
    $task = app(Schedule::class)->call(fn () => null)->cron($expression);

    if ($name !== null) {
        $task->description($name);
    }

    return $task;
}

/**
 * The first buffered-then-shipped `schedule` event across everything the
 * transport received, or null when none was captured.
 *
 * @return array<string, mixed>|null
 */
function capturedSchedule(ScheduleCaptureTransport $transport): ?array
{
    foreach ($transport->sent as $envelope) {
        foreach (($envelope['events'] ?? []) as $event) {
            if (($event['type'] ?? null) === 'schedule') {
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

// -- AC1: the captured fields of a finished task ----------------------------

it('captures the command, expression, exit code, duration, memory and host of a finished task', function () {
    $transport = bootSchedules();

    $task = scheduleCommandTask('php artisan backup:run', '0 3 * * *', 'Run nightly backups');
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.5));

    $payload = capturedSchedule($transport)['payload'];

    expect($payload['command'])->toBe('Run nightly backups')
        ->and($payload['expression'])->toBe('0 3 * * *')
        ->and($payload['exit_code'])->toBe(0)
        ->and($payload['duration_ms'])->toBe(1500.0)
        ->and($payload['memory_mb'])->toBeGreaterThan(0.0)
        ->and($payload['host'])->toBeString();
});

it('uses the raw command when a command task has no description', function () {
    $transport = bootSchedules();

    $task = scheduleCommandTask('php artisan cache:prune', '*/5 * * * *');
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.2));

    expect(capturedSchedule($transport)['payload']['command'])->toBe('php artisan cache:prune');
});

// -- AC4: works for both closure and command tasks --------------------------

it('captures an unnamed closure task, labelling it Closure', function () {
    $transport = bootSchedules();

    $task = scheduleClosureTask(null, '0 * * * *');
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.05));

    $payload = capturedSchedule($transport)['payload'];

    expect($payload['command'])->toBe('Closure')
        ->and($payload['expression'])->toBe('0 * * * *');
});

it('captures a named closure task by its name', function () {
    $transport = bootSchedules();

    $task = scheduleClosureTask('prune stale sessions', '30 2 * * *');
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.05));

    expect(capturedSchedule($transport)['payload']['command'])->toBe('prune stale sessions');
});

// -- AC2: task output is captured and truncated at a configurable size -------

it('captures task output and truncates it at the configured size', function () {
    $transport = bootSchedules(['prism.schedule.max_output' => 10]);

    $file = tempnam(sys_get_temp_dir(), 'prism-sched-');
    file_put_contents($file, 'this output is definitely longer than ten bytes');

    $task = scheduleCommandTask('php artisan report', '0 0 * * *', output: $file);
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    expect(capturedSchedule($transport)['payload']['output'])->toBe('this outpu');

    @unlink($file);
});

it('captures no output for a task streaming to the default /dev/null', function () {
    $transport = bootSchedules();

    $task = scheduleCommandTask();
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    expect(capturedSchedule($transport)['payload']['output'])->toBe('');
});

// -- AC3: tasks that fail or throw are recorded with the failure reason ------

it('records a failed task with its failure reason and a non-zero exit code', function () {
    $transport = bootSchedules();

    $task = scheduleCommandTask('php artisan risky', '0 1 * * *', 'Risky task');
    // The framework records the process exit code even on a failed run.
    $task->exitCode = 3;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('disk full')));

    $payload = capturedSchedule($transport)['payload'];

    expect($payload['exit_code'])->toBe(3)
        ->and($payload['output'])->toContain('RuntimeException')
        ->and($payload['output'])->toContain('disk full');
});

it('defaults a failed task with no recorded exit code to a non-zero code', function () {
    $transport = bootSchedules();

    $task = scheduleClosureTask('cleanup');

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    expect(capturedSchedule($transport)['payload']['exit_code'])->toBe(1);
});

it('computes the duration of a failed task from its start stamp', function () {
    $transport = bootSchedules();

    $start = Carbon::create(2026, 7, 21, 1, 0, 0);
    Carbon::setTestNow($start->copy());

    $task = scheduleCommandTask('php artisan risky', '0 1 * * *');
    event(new ScheduledTaskStarting($task));

    Carbon::setTestNow($start->copy()->addMilliseconds(400));
    event(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    expect(capturedSchedule($transport)['payload']['duration_ms'])->toBe(400.0);
});

// -- AC5: telemetry ships at the end of each task, not accumulated -----------

it('flushes a schedule event at the end of each task', function () {
    $transport = bootSchedules();

    $task = scheduleCommandTask();
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    expect($transport->sent)->toHaveCount(1)
        ->and(app(EventBuffer::class)->isEmpty())->toBeTrue()
        ->and($transport->sent[0]['events'])->toHaveCount(1)
        ->and($transport->sent[0]['events'][0]['type'])->toBe('schedule');
});

it('ships each task separately rather than accumulating across schedule:run', function () {
    $transport = bootSchedules();

    $first = scheduleCommandTask('php artisan a', '0 0 * * *');
    $first->exitCode = 0;
    event(new ScheduledTaskStarting($first));
    event(new ScheduledTaskFinished($first, 0.1));

    $second = scheduleCommandTask('php artisan b', '0 0 * * *');
    event(new ScheduledTaskStarting($second));
    event(new ScheduledTaskFailed($second, new RuntimeException('boom')));

    expect($transport->sent)->toHaveCount(2)
        ->and($transport->sent[0]['events'][0]['payload']['command'])->toBe('php artisan a')
        ->and($transport->sent[0]['events'][0]['payload']['exit_code'])->toBe(0)
        ->and($transport->sent[1]['events'][0]['payload']['command'])->toBe('php artisan b')
        ->and($transport->sent[1]['events'][0]['payload']['exit_code'])->toBe(1);
});

// -- correlation: the trace id rides the envelope ---------------------------

it('rides a trace id and user_id on the envelope', function () {
    $transport = bootSchedules();

    $task = scheduleCommandTask();
    $task->exitCode = 0;

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    $event = capturedSchedule($transport);

    expect($event['trace_id'])->toBeString()
        ->and($event['trace_id'])->not->toBe('')
        ->and($event['request_id'])->toBe('')
        ->and($event)->toHaveKey('user_id');
});

// -- the package's own work is never captured -------------------------------

it('never captures a task while the package is doing its own work', function () {
    bootSchedules();

    $task = scheduleCommandTask();
    $task->exitCode = 0;

    Recursion::suppress(function () use ($task) {
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, 0.1));
    });

    expect(app(EventBuffer::class)->count())->toBe(0);
});

// -- capture is skipped entirely when disabled ------------------------------

it('registers no schedule listeners and binds nothing when schedule capture is disabled', function () {
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.batch.flush' => 'sync',
        'prism.capture.schedules' => false,
    ]);
    Recursion::reset();
    TraceContext::reset();
    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(ScheduleCapture::class);

    $before = count(app('events')->getRawListeners()[ScheduledTaskFinished::class] ?? []);
    (new PrismServiceProvider(app()))->boot();
    $after = count(app('events')->getRawListeners()[ScheduledTaskFinished::class] ?? []);

    expect($after)->toBe($before)
        ->and(app()->bound(ScheduleCapture::class))->toBeFalse();

    $task = scheduleCommandTask();
    $task->exitCode = 0;
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    expect(app(EventBuffer::class)->count())->toBe(0);
});
