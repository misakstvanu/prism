<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Support\Text;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * What a job was asked to do, captured by Prism rather than by the engine
 * (US-014).
 *
 * The capture engine's two job records carry a name, an id, a queue, a
 * connection and an outcome and say nothing about the job's *arguments* — so
 * `backup:tenant 41` and `backup:tenant 7` arrive as one row shape, and the
 * `jobs.payload` column the failed-job screen draws a panel from is empty for
 * every row a real install has ever written.
 *
 * Every case drives a **real dispatch and a real worker run** on the `database`
 * driver, and reads the events upstream's own records became. Three things
 * about that are load-bearing:
 *
 *   - **A hand-called recorder would prove nothing.** Prism's listeners are
 *     registered from `boot()` and Nightwatch's from `register()`, so
 *     upstream's fire first — and the whole question is whether a payload is in
 *     the holder by the time upstream has already written the record it belongs
 *     to. That is why the dispatch half listens to `JobQueueing` rather than
 *     `JobQueued`, and only a real dispatch says whether it worked.
 *   - **`sync` cannot be used for either half.** It raises no `JobQueued` at
 *     all, and `JobAttemptSensor` returns null outright for a `sync`
 *     connection — so a suite built on it would assert on records that were
 *     never going to exist. A real queue and a real `runNextJob` are the
 *     cheapest thing that is not a fiction.
 *   - **The payload is read off the translated event.** A key the ingest failed
 *     to stamp is a column that stays at its default with nothing anywhere
 *     saying so.
 */
beforeEach(function () {
    config(['prism.scrub' => ['password', 'token']]);

    jobPayloadQueueTable();

    // What a `queue:work` process does on startup: upstream registers its job
    // hooks from `CommandStarting`, so without this the engine writes no
    // `job-attempt` record at all and an assertion about one would be about
    // nothing. It is the console side of the register-time decision this
    // suite's own case exists for.
    event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

    app(EventBuffer::class)->clear();
});

it('carries the payload on BOTH halves of a job, scrubbed by key', function () {
    jobPayloadDispatch(['tenant' => 41, 'password' => 'secret-job-value']);

    // Read before the worker runs, because running one is where a process stops
    // being the one that dispatched: Prism clears the shared buffer at
    // `JobProcessing` so one job's events never leak into the next, which is
    // exactly what a second process would give for free.
    $payloads = ['queued' => jobPayloadEventFor('queued')];

    jobPayloadWork();

    $payloads['attempted'] = jobPayloadEventFor('attempted');

    // Both rows, because a dispatch and an attempt are two records — usually
    // written by two processes — and the failed-jobs table's detail has to have
    // the payload whichever one it found.
    foreach ($payloads as $payload) {

        // The claim: the column's key is on the event at all. This is what
        // upstream has no answer for.
        expect($payload)->toContain('"tenant":41')
            // Scrubbed BY KEY over the decoded document, which is what a JSON
            // payload buys over a pattern match.
            ->and($payload)->toContain('[REDACTED]')
            ->and($payload)->not->toContain('secret-job-value')
            // And it is still the job's own document, not a summary of it.
            ->and($payload)->toContain(JobPayloadProbeHandler::class);
    }
});

it('records no payload when the switch is off', function () {
    config(['prism.job.capture_payload' => false]);

    jobPayloadDispatch(['tenant' => 7]);

    // ABSENT, not empty — the column keeps its own `DEFAULT ''` rather than
    // being told an empty payload was observed.
    expect(jobPayloadEvent('queued'))->not->toHaveKey('payload');

    jobPayloadWork();

    expect(jobPayloadEvent('attempted'))->not->toHaveKey('payload');
});

it('caps the payload and says that it cut one', function () {
    config(['prism.job.max_payload' => 60]);

    jobPayloadDispatch(['note' => str_repeat('x', 500)]);

    $payload = jobPayloadEventFor('queued');

    // The cap bounds the payload; the marker is appended after the cut, so the
    // cap reads as the number the host configured and what is stored is never
    // a document that merely looks malformed.
    expect(strlen($payload))->toBeLessThan(60 + strlen(Text::TRUNCATED) + 1)
        ->and($payload)->toEndWith(Text::TRUNCATED);
});

it('answers each job with its own payload', function () {
    jobPayloadDispatch(['tenant' => 7]);

    expect(jobPayloadEventFor('queued'))->toContain('"tenant":7');

    app(EventBuffer::class)->clear();

    // The holder is keyed by the job id upstream's own sensors key a record
    // by, so a second dispatch is answered with its own payload rather than
    // with whatever was held last.
    Queue::connection('database')->push(JobPayloadOtherHandler::class, ['tenant' => 9]);

    expect(jobPayloadEventFor('queued'))->toContain('"tenant":9')
        ->and(jobPayloadEventFor('queued'))->not->toContain('"tenant":7');
});

it('releases a payload once it has been read', function () {
    $raw = jobPayloadDispatch(['tenant' => 7]);

    expect(jobPayloadEventFor('queued'))->toContain('"tenant":7');

    app(EventBuffer::class)->clear();

    // A second record for the SAME job, with no queue event of its own in
    // front of it — a redispatch the framework raised once, a record upstream
    // wrote twice. The entry was spent by the first, and a holder that kept it
    // would grow one entry per job for the life of a worker process.
    event(new JobQueued('database', 'default', 2, JobPayloadProbeHandler::class, $raw, null));

    expect(jobPayloadEvent('queued'))->not->toHaveKey('payload');
});

it('puts the payload on a job event and on nothing else', function () {
    jobPayloadDispatch(['tenant' => 7]);
    jobPayloadWork();

    // A log line raised inside a job is not the job, and neither is a span or
    // a query. Stamping the payload onto each of them would put the same
    // document in a dozen rows.
    $others = [];

    foreach (app(EventBuffer::class)->all() as $type => $events) {
        if ((string) $type !== 'job') {
            $others = [...$others, ...$events];
        }
    }

    expect($others)->not->toBeEmpty();

    foreach ($others as $event) {
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];

        expect($payload)->not->toHaveKey('payload');
    }
});

/**
 * The `jobs` table the `database` connection needs, created here rather than
 * through a migration because two dispatches are the whole of what this suite
 * asks of it.
 */
function jobPayloadQueueTable(): void
{
    if (Schema::hasTable('jobs')) {
        return;
    }

    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

/**
 * Dispatch onto the real `database` queue.
 *
 * A raw `Queue::push` rather than a job object, because a job object's
 * arguments reach the payload inside `data.command` — a PHP `serialize()`
 * string nothing scrubs by key — while a raw push puts them in `data` as the
 * JSON object the rule actually works on. Both shapes are captured; only this
 * one can say whether the scrub applied.
 *
 * Answers the raw payload string the framework built, which is what a test
 * needs to raise a second record for the same job by hand.
 *
 * @param  array<string, mixed>  $data
 */
function jobPayloadDispatch(array $data): string
{
    $raw = '';

    app('events')->listen(JobQueueing::class, function (JobQueueing $event) use (&$raw): void {
        $raw = (string) $event->payload;
    });

    Queue::connection('database')->push(JobPayloadProbeHandler::class, $data);

    return $raw;
}

/** Run the next queued job exactly as a worker process does. */
function jobPayloadWork(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
}

/**
 * The buffered `job` event for one phase.
 *
 * @return array<string, mixed>
 */
function jobPayloadEvent(string $phase): array
{
    /** @var array<int, array<string, mixed>> $events */
    $events = app(EventBuffer::class)->all()['job'] ?? [];

    foreach ($events as $event) {
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];

        if (($payload['phase'] ?? null) === $phase) {
            return $payload;
        }
    }

    throw new RuntimeException("No `{$phase}` job event was buffered.");
}

/** The `payload` column value on the buffered `job` event for one phase. */
function jobPayloadEventFor(string $phase): string
{
    $payload = jobPayloadEvent($phase);

    expect($payload)->toHaveKey('payload');

    return (string) $payload['payload'];
}

/** The handler a raw `Queue::push` resolves and fires. */
final class JobPayloadProbeHandler
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function fire(QueueJob $job, array $data): void
    {
        $job->delete();
    }
}

/** A second handler, so "one job's payload is not another's" has two jobs. */
final class JobPayloadOtherHandler
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function fire(QueueJob $job, array $data): void
    {
        $job->delete();
    }
}
