<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\Jobs\DrainSpoolJob;
use Misakstvanu\Prism\Otel\SpanFlush;
use Misakstvanu\Prism\Transport\Transport;

/**
 * A transport double for the spool suite: records what it is handed, can be made
 * to fail, and can run a side effect mid-send so a flush landing while a drain is
 * still sending can be reproduced deterministically.
 */
class SpoolTestTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public bool $result = true;

    /** @var (callable(): void)|null */
    public $onSend = null;

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        if ($this->onSend !== null) {
            ($this->onSend)();
        }

        return $this->result;
    }
}

/** A flusher over the given buffer, with the real spool collaborators. */
function spoolFlusher(EventBuffer $buffer, Transport $transport): Flusher
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

/** A buffer holding one log event, tagged so a batch can be told from another. */
function spoolBuffer(string $tag): EventBuffer
{
    $buffer = new EventBuffer;
    $buffer->add('log', ['message' => $tag, 'timestamp' => 't']);

    return $buffer;
}

/** Flush one tagged batch through the spool. */
function spoolOne(string $tag, Transport $transport): void
{
    spoolFlusher(spoolBuffer($tag), $transport)->flush();
}

/** Run a drain the way the queue would, under the given pending-marker owner. */
function runDrain(Transport $transport, ?string $owner = null): void
{
    (new DrainSpoolJob($owner))->handle(
        app(SpoolScheduler::class),
        app(BatchSpool::class),
        $transport,
    );
}

/** The owner token of the most recently dispatched drain job. */
function lastDrainOwner(): ?string
{
    /** @var DrainSpoolJob|null $job */
    $job = Queue::pushed(DrainSpoolJob::class)->last();

    return $job?->owner;
}

beforeEach(function () {
    config([
        'prism.app' => 'demo',
        'prism.environment' => 'testing',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'spool',
        'prism.batch.queue' => null,
        // Testbench defaults the queue to `sync`, on which a "deferred" drain
        // would run inline and defeat the point of spooling — the flusher
        // declines to spool there, so the suite needs a real queue driver.
        'queue.default' => 'database',
    ]);

    Queue::fake();
});

it('spools a finished batch instead of sending it, and arms exactly one drain', function () {
    $transport = new SpoolTestTransport;

    spoolOne('a', $transport);
    spoolOne('b', $transport);
    spoolOne('c', $transport);

    // Nothing went to the network from the process that captured it.
    expect($transport->sent)->toBeEmpty()
        ->and(app(BatchSpool::class)->pending())->toBe(3);

    // Three flushes, one drain: the debounce window is what turns a burst of
    // requests into a single ingest POST.
    Queue::assertPushed(DrainSpoolJob::class, 1);
});

it('ships every spooled batch in one drain and leaves the spool empty', function () {
    $transport = new SpoolTestTransport;

    spoolOne('a', $transport);
    spoolOne('b', $transport);

    runDrain($transport, lastDrainOwner());

    expect($transport->sent)->toHaveCount(2)
        ->and(app(BatchSpool::class)->pending())->toBe(0);

    // The envelope survives the round trip through the cache intact — the drain
    // ships exactly what the producing process built.
    expect($transport->sent[0]['v'])->toBe(1)
        ->and($transport->sent[0]['app'])->toBe('demo')
        ->and($transport->sent[0]['replica'])->toBe('web-1')
        ->and($transport->sent[0]['events'])->toBe([
            ['message' => 'a', 'timestamp' => 't', 'type' => 'log'],
        ]);
});

it('arms a fresh drain for a batch spooled while a drain is still sending', function () {
    $transport = new SpoolTestTransport;

    spoolOne('first', $transport);
    $owner = lastDrainOwner();

    // A request finishing mid-drain: its terminal flush spools after the running
    // drain has already claimed. Because the drain releases the pending marker
    // before it claims, this flush arms a drain of its own rather than assuming
    // the running one will collect it.
    $transport->onSend = function () use ($transport): void {
        spoolOne('late', $transport);
    };

    runDrain($transport, $owner);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['events'][0]['message'])->toBe('first')
        ->and(app(BatchSpool::class)->pending())->toBe(1);

    Queue::assertPushed(DrainSpoolJob::class, 2);
});

it('hands a spooled batch to exactly one claimer', function () {
    $transport = new SpoolTestTransport;
    $spool = app(BatchSpool::class);

    spoolOne('a', $transport);

    // Two drains racing: the index is cleared under the same lock that guards
    // every append, so the batch cannot be sent twice.
    expect($spool->claim())->toHaveCount(1)
        ->and($spool->claim())->toBeEmpty();
});

it('re-spools a batch the drain could not send and arms another drain', function () {
    $transport = new SpoolTestTransport;
    $transport->result = false;

    spoolOne('a', $transport);
    runDrain($transport, lastDrainOwner());

    // The batch is back on the spool with a drain scheduled for it: an outage
    // costs a delay, not the telemetry.
    expect(app(BatchSpool::class)->pending())->toBe(1);
    Queue::assertPushed(DrainSpoolJob::class, 2);
});

it('discards a batch once it has burned through its send attempts', function () {
    config(['prism.batch.spool.max_attempts' => 2]);

    $transport = new SpoolTestTransport;
    $transport->result = false;

    spoolOne('a', $transport);

    runDrain($transport, lastDrainOwner());
    expect(app(BatchSpool::class)->pending())->toBe(1);

    runDrain($transport, lastDrainOwner());

    // A permanently unreachable endpoint stops costing queue work rather than
    // cycling the same payload forever.
    expect(app(BatchSpool::class)->pending())->toBe(0)
        ->and($transport->sent)->toHaveCount(2);
});

it('sends inline instead of spooling when the queue connection is sync', function () {
    config(['queue.default' => 'sync']);

    $transport = new SpoolTestTransport;

    spoolOne('a', $transport);

    expect($transport->sent)->toHaveCount(1)
        ->and(app(BatchSpool::class)->pending())->toBe(0);

    Queue::assertNothingPushed();
});

it('sends inline rather than dropping a batch when the index lock is held', function () {
    config(['prism.batch.spool.lock_wait' => 1]);

    $store = Cache::store()->getStore();
    $lock = $store->lock('prism:spool:index', 30);

    expect($lock->get())->toBeTrue();

    $transport = new SpoolTestTransport;

    spoolOne('a', $transport);

    // Declining to spool always falls back to the normal send path — a batch is
    // never lost between the two.
    expect($transport->sent)->toHaveCount(1);

    $lock->forceRelease();

    // And nothing was left indexed by the failed append.
    expect(app(BatchSpool::class)->pending())->toBe(0);
});

it('drops the oldest batches once the spool is at capacity', function () {
    config(['prism.batch.spool.max_batches' => 2]);

    $transport = new SpoolTestTransport;

    spoolOne('oldest', $transport);
    spoolOne('middle', $transport);
    spoolOne('newest', $transport);

    $spool = app(BatchSpool::class);

    expect($spool->pending())->toBe(2);

    $claimed = $spool->claim();

    expect($claimed)->toHaveCount(2)
        ->and($claimed[0]->envelope['events'][0]['message'])->toBe('middle')
        ->and($claimed[1]->envelope['events'][0]['message'])->toBe('newest');
});

it('does not spool anything when the buffer is empty', function () {
    $transport = new SpoolTestTransport;

    spoolFlusher(new EventBuffer, $transport)->flush();

    expect(app(BatchSpool::class)->pending())->toBe(0)
        ->and($transport->sent)->toBeEmpty();

    Queue::assertNothingPushed();
});
