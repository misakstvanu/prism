<?php

use Illuminate\Contracts\Console\Kernel;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Console\Bench\BenchResult;
use Misakstvanu\Prism\Console\Bench\BenchTransport;
use Misakstvanu\Prism\Console\Bench\LegacyCapturePath;
use Misakstvanu\Prism\Console\BenchCaptureCommand;

/**
 * Read a private constant off the benchmark command (US-023).
 *
 * The four configurations are declared as data, and what makes each of them the
 * thing it claims to be is the environment beside it — a wrong variable there
 * produces a configuration that boots fine, measures something real and answers
 * the wrong question, which is the one failure a benchmark cannot survive. So
 * the table is asserted directly rather than through a run.
 */
function benchConstant(string $name): mixed
{
    return (new ReflectionClass(BenchCaptureCommand::class))->getConstant($name);
}

it('registers the benchmark command whatever the install looks like', function () {
    config(['prism.enabled' => false, 'prism.token' => null]);

    expect(array_key_exists('prism:bench:capture', $this->app[Kernel::class]->all()))
        ->toBeTrue('the benchmark has to be runnable on a disabled install — three of its four configurations are one');
});

describe('the four configurations', function () {
    it('declares exactly the four the story compares', function () {
        expect(array_keys(benchConstant('PROFILES')))->toBe(['off', 'legacy', 'nightwatch', 'otel']);
    });

    it('switches the capture engine off at its own variable for the baseline', function () {
        // Prism derives `nightwatch.enabled` from `prism.enabled`, but upstream
        // registers first and snapshots its config while doing so — so
        // PRISM_ENABLED=false alone leaves 38 hooks registered and a paused
        // Core. Only upstream's own variable produces a true zero.
        foreach (['off', 'legacy'] as $profile) {
            expect(benchConstant('PROFILES')[$profile]['env'])->toBe([
                'PRISM_ENABLED' => 'false',
                'NIGHTWATCH_ENABLED' => 'false',
                'PRISM_OTEL_ENABLED' => 'false',
            ]);
        }
    });

    it('separates the two engine configurations by the span lane alone', function () {
        expect(benchConstant('PROFILES')['nightwatch']['env']['PRISM_OTEL_ENABLED'])->toBe('false');
        expect(benchConstant('PROFILES')['otel']['env']['PRISM_OTEL_ENABLED'])->toBe('true');

        foreach (['PRISM_ENABLED', 'NIGHTWATCH_ENABLED'] as $variable) {
            expect(benchConstant('PROFILES')['nightwatch']['env'][$variable])
                ->toBe(benchConstant('PROFILES')['otel']['env'][$variable], "{$variable} must not differ between the two engine configurations");
        }
    });

    it('reconstructs the old client for exactly one configuration', function () {
        $legacy = array_keys(array_filter(benchConstant('PROFILES'), static fn (array $p): bool => $p['legacy']));

        expect($legacy)->toBe(['legacy']);
    });

    it('forces the request shape, a fixed sampling rate and an in-process cache on every child', function () {
        $shared = benchConstant('SHARED_ENV');

        // Without this the engine decides, at register time, that an artisan
        // command does not serve requests and wires the console sensors — every
        // figure would then describe a request path nothing was watching.
        expect($shared['NIGHTWATCH_FORCE_REQUEST'])->toBe('1');

        // A sampled-out execution is discarded whole, so a rate left to the
        // host's .env would let one configuration measure capture and another
        // measure the sampler refusing it.
        expect($shared['PRISM_SAMPLE_REQUESTS'])->toBe('1.0');

        // The replica-metrics interval throttle re-asks the cache on every
        // flush in any process that did not win it — a network round trip per
        // request against a real store, which measured larger than everything
        // the benchmark compares and moved with whoever held the lock.
        expect($shared['CACHE_STORE'])->toBe('array');
    });
});

describe('a measurement crossing the process boundary', function () {
    it('round-trips every field', function () {
        $result = new BenchResult(
            profile: 'otel',
            label: 'Nightwatch + OTel spans',
            measured: true,
            skipReason: null,
            requests: 250,
            meanUs: 6543.21,
            medianUs: 6400.0,
            p95Us: 8100.5,
            peakMemoryBytes: 41_000_000,
            eventsPerRequest: 35.0,
            bufferDropped: 3,
            translateDropped: 1,
            bufferCapacity: 1000,
            protobufTouched: false,
        );

        expect(BenchResult::fromArray($result->toArray()))->toEqual($result);
    });

    it('reads a malformed answer back as a skipped row, never as a zero', function () {
        $result = BenchResult::fromArray(['profile' => 'legacy']);

        expect($result->measured)->toBeFalse();
        expect($result->meanUs)->toBe(0.0);
    });

    it('keeps a skipped configuration apart from one that measured nothing', function () {
        $skipped = BenchResult::skipped('legacy', 'old client (pre-2.0)', 'no git');

        expect($skipped->measured)->toBeFalse();
        expect($skipped->skipReason)->toBe('no git');
    });
});

describe('the transport the benchmark ships into', function () {
    it('counts events without sending anything', function () {
        $transport = new BenchTransport;

        expect($transport->send(['events' => [['type' => 'log'], ['type' => 'query']]]))->toBeTrue();
        $transport->send(['events' => [['type' => 'span']]]);

        expect($transport->envelopes())->toBe(2);
        expect($transport->events())->toBe(3);
    });

    it('forgets the warm-up', function () {
        $transport = new BenchTransport;
        $transport->send(['events' => [['type' => 'log']]]);
        $transport->reset();

        expect($transport->events())->toBe(0);
        expect($transport->envelopes())->toBe(0);
    });

    it('survives an envelope with no events at all', function () {
        $transport = new BenchTransport;
        $transport->send(['v' => 1]);

        expect($transport->events())->toBe(0);
    });
});

describe('the old client', function () {
    it('names the commit that deleted it, so the baseline cannot move', function () {
        // A branch-relative expression would name a different tree after every
        // commit, and a baseline that quietly moves is worse than none.
        expect(LegacyCapturePath::REF)->toMatch('/^[0-9a-f]{40}\^$/');
    });

    it('prints an export command naming both halves of the old capture path', function () {
        $command = LegacyCapturePath::exportCommand('/tmp/legacy', LegacyCapturePath::REF);

        expect($command)->toContain('packages/prism/src/Capture');
        // The span stack went with the listeners and is what they push onto —
        // exporting the directory alone produces a fatal on the first request.
        expect($command)->toContain('packages/prism/src/Support/SpanStack.php');
        expect($command)->toContain(LegacyCapturePath::REF);
    });

    it('refuses to invent sources when there is no repository to export from', function () {
        $target = sys_get_temp_dir().'/prism-bench-missing-'.bin2hex(random_bytes(4));

        [$directory, $problem] = LegacyCapturePath::materialise(sys_get_temp_dir(), $target, LegacyCapturePath::REF);

        expect($directory)->toBeNull();
        expect($problem)->toContain('not a git repository');
    });

    it('uses an already-populated directory as-is', function () {
        $target = sys_get_temp_dir().'/prism-bench-'.bin2hex(random_bytes(4));
        mkdir($target.'/Capture', 0o755, recursive: true);

        [$directory, $problem] = LegacyCapturePath::materialise('/nonexistent', $target, LegacyCapturePath::REF);

        expect($directory)->toBe($target);
        expect($problem)->toBeNull();

        rmdir($target.'/Capture');
        rmdir($target);
    });
});

describe('the buffer still bounds what one request can cost', function () {
    it('drops and counts a span-heavy request rather than growing', function () {
        // AC5. The span lane can emit an unbounded number of spans for one
        // request — a loop issuing queries is all it takes — and the whole
        // point of `prism.batch.size` is that memory is bounded by the buffer
        // rather than by whatever the request happened to do.
        $buffer = new EventBuffer(capacity: 50);

        for ($i = 0; $i < 500; $i++) {
            $buffer->add('span', ['payload' => ['span_id' => (string) $i]]);
        }

        expect($buffer->count())->toBe(50);
        expect($buffer->dropped())->toBe(450);

        // And the drop is reported rather than silent: the count survives the
        // drain, which is what puts it on the benchmark's own report.
        expect($buffer->flush()->dropped)->toBe(450);
    });

    it('reports the capacity a run was bounded by, not just what it dropped', function () {
        // A dropped count with no capacity beside it cannot be acted on — 450
        // dropped against a capacity of 50 is a workload to reconsider, against
        // a capacity of 100000 it is a bug.
        $result = new BenchResult('otel', 'Nightwatch + OTel spans', true, bufferDropped: 12, bufferCapacity: 1000);

        expect($result->toArray())->toHaveKeys(['buffer_dropped', 'buffer_capacity']);
    });
});
