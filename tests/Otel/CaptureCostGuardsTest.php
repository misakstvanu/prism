<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Transport\Transport;

/**
 * The two claims the capture benchmark makes about *cost* rather than about
 * speed (US-023), asserted in the host where all three providers are registered
 * in the order a real install produces.
 *
 * They are here rather than beside the command because neither is a property of
 * the command: one is a property of the buffer under a real span-producing
 * request, and the other is a property of the whole process after one.
 */
beforeEach(function () {
    // A deliberately tiny buffer, so a single request can overrun it. Both
    // singletons are forgotten and the ingest is re-installed the way the
    // provider installs it — an ingest still holding the previous buffer would
    // leave this asserting the default capacity of 1000, which nothing here
    // could reach.
    config(['prism.batch.size' => costGuardCapacity()]);

    app()->forgetInstance(PrismIngest::class);
    app()->forgetInstance(EventBuffer::class);

    $this->transport = new CostGuardRecordingTransport;
    app()->instance(Transport::class, $this->transport);

    app()->make(Core::class)->ingest = app()->make(PrismIngest::class);
});

it('bounds what one span-heavy request can hold, however many spans it makes', function () {
    // AC5. The span lane emits a span per query, and nothing stops a route
    // running a hundred of them — so `prism.batch.size` is the only thing
    // standing between a pathological request and a buffer the size of its
    // whole trace.
    Route::get('/prism-cost-guard-probe', function () {
        for ($i = 0; $i < 40; $i++) {
            DB::select('select ? as n', [$i]);
        }

        return 'ok';
    });

    $this->get('/prism-cost-guard-probe')->assertOk();

    $buffer = app()->make(EventBuffer::class);

    // Two mechanisms bound the memory and the buffer is allowed either: a
    // sampled execution ships early when it fills (so several batches leave,
    // each within the capacity), a sampled-out one drops the excess and counts
    // it. What must never happen is the buffer growing to fit the request.
    expect($buffer->count())->toBeLessThanOrEqual(costGuardCapacity());

    foreach ($this->transport->sent as $envelope) {
        expect(count($envelope['events']))->toBeLessThanOrEqual(costGuardCapacity());
    }

    // Non-vacuous: the request really did produce more telemetry than the
    // buffer can hold at once, so the bound above was actually exercised.
    expect(costGuardShippedEvents($this->transport->sent) + $buffer->count() + $buffer->dropped())
        ->toBeGreaterThan(costGuardCapacity());
});

it('drops and counts rather than growing when the excess cannot be shipped', function () {
    // The sampled-out half of the same rule, driven directly because an
    // execution the sampler rejected discards its buffer at the end and there
    // would be nothing left to look at. `shouldDigestWhenBufferIsFull(false)`
    // is exactly what `Core::sample()` sets for a rejected execution.
    $buffer = app()->make(EventBuffer::class);
    $ingest = app()->make(PrismIngest::class);
    $ingest->shouldDigestWhenBufferIsFull(false);

    for ($i = 0; $i < costGuardCapacity() * 4; $i++) {
        $ingest->write([
            'v' => 1,
            't' => 'query',
            'timestamp' => microtime(true),
            'execution_id' => 'exec-1',
            'trace_id' => str_repeat('a', 32),
            'sql' => 'select 1',
            'duration' => 100,
            'connection' => 'sqlite',
        ]);
    }

    expect($buffer->count())->toBe(costGuardCapacity());
    expect($buffer->dropped())->toBe(costGuardCapacity() * 3);
    expect($this->transport->sent)->toBeEmpty();
});

it('never exercises google/protobuf, so no span leaves as OTLP', function () {
    // AC6. The three exporters are pinned to upstream's own "null" names, which
    // is what keeps a PSR-18 client from ever being discovered — but a pin is a
    // config value, and this is the consequence a config value is supposed to
    // have. A protobuf class is loaded on the first byte an OTLP exporter
    // serialises, so its absence after a request that produced a whole
    // waterfall is the assertable form of "nothing was exported".
    Route::get('/prism-cost-guard-otlp-probe', function () {
        DB::select('select 1 as one');

        return 'ok';
    });

    $this->get('/prism-cost-guard-otlp-probe')->assertOk();

    expect(costGuardShippedEvents($this->transport->sent))->toBeGreaterThan(0);

    $loaded = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'Google\\Protobuf\\')
            || str_starts_with($class, 'Opentelemetry\\Proto\\'),
    ));

    expect($loaded)->toBe([]);

    // Non-vacuous, and asserted only AFTER the check above — the package IS in
    // the tree (the OTLP exporter requires it), so "no protobuf class is
    // loaded" is a statement about what ran rather than about what is
    // installed. Asking `class_exists()` here would load one and poison the
    // assertion for every later test in this process.
    expect(InstalledVersions::isInstalled('google/protobuf'))
        ->toBeTrue('if protobuf were absent the assertion above could not fail');
});

/** The buffer capacity these tests run against. */
function costGuardCapacity(): int
{
    return 12;
}

/**
 * How many events reached the transport across every batch. Uniquely named
 * because Pest loads every test file into one process.
 *
 * @param  list<array<string, mixed>>  $sent
 */
function costGuardShippedEvents(array $sent): int
{
    $total = 0;

    foreach ($sent as $envelope) {
        $total += count($envelope['events'] ?? []);
    }

    return $total;
}

final class CostGuardRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }
}
