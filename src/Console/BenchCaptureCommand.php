<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\State\RequestState;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Console\Bench\BenchResult;
use Misakstvanu\Prism\Console\Bench\BenchTransport;
use Misakstvanu\Prism\Console\Bench\LegacyCapturePath;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Otel\SpanLane;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\TraceContext;
use Misakstvanu\Prism\Transport\Transport;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Times the host's request lifecycle under four capture configurations, so the
 * cost of replacing the capture engine is measured rather than assumed
 * (US-023): **off** (nothing captures — the baseline every other figure is
 * quoted as an addition to), **legacy** (the pre-epic client out of git,
 * {@see LegacyCapturePath} — the number the change has to beat), **nightwatch**
 * (the engine alone, feeding Prism's buffer) and **otel** (the engine plus the
 * OpenTelemetry span lane, what a default install runs). Each is its own
 * process, not an optimisation: which engines listen is decided in `register()`,
 * hooks cannot be un-registered, and the ingest swap, reject callbacks and span
 * processor are installed before any command runs, so four in one would be four
 * names for one. It spawns itself per configuration (`--profile=`), parses the
 * one JSON line each child prints and compares here; a child times one synthetic
 * route through the *real* HTTP kernel doing fixed work capture is expensive for
 * — queries (the query sensor's `debug_backtrace()` per query, the single most
 * expensive thing in the path), cache reads and writes, log lines — response
 * never sent, delivery replaced by {@see BenchTransport}, per-execution state
 * reset each iteration (scoped instances forgotten, `prepareForRequest()`). The
 * verdict is a gate, not a note: a configuration adding more wall-clock per
 * request than the old client exits non-zero, because a regression recorded in
 * a table nobody blocks on ships.
 */
class BenchCaptureCommand extends Command
{
    /** @var string */
    protected $signature = 'prism:bench:capture
        {--requests=200 : Request lifecycles timed per configuration}
        {--warmup=25 : Untimed lifecycles run first, to warm the opcode cache and the connection}
        {--rounds=3 : Times the whole sweep is repeated; each configuration reports its median round}
        {--queries=8 : Database queries the benchmarked request runs}
        {--cache=8 : Cache reads and writes the benchmarked request performs}
        {--logs=1 : Log lines the benchmarked request writes}
        {--profiles= : Comma-separated subset of off,legacy,nightwatch,otel}
        {--tolerance=0 : Percent by which a configuration may exceed the old client before it fails}
        {--legacy-ref= : The git ref the old client is exported from}
        {--legacy-source= : A directory already holding the old client sources}
        {--markdown : Print the report as a markdown table, ready for the README}
        {--profile= : INTERNAL — measure one configuration in this process and print its JSON}';

    /** @var string */
    protected $description = 'Benchmark the per-request cost of the capture path against the old client.';

    /**
     * The configurations, and the environment each is produced by.
     * `NIGHTWATCH_ENABLED=false` makes "off" a true zero rather than "hooks
     * registered, then paused": the engine registers before Prism in every real
     * install, reading its own config as it does, so answering its own variable
     * is the only way to have none of its hooks. `NIGHTWATCH_FORCE_REQUEST` is
     * the other side — the engine decides once, at register time, whether this
     * process serves requests, and under an artisan command the answer is no,
     * which would wire the console sensors and leave the request path untouched.
     *
     * @var array<string, array{label: string, env: array<string, string>, legacy: bool}>
     */
    private const PROFILES = [
        'off' => [
            'label' => 'capture off',
            'env' => ['PRISM_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PRISM_OTEL_ENABLED' => 'false'],
            'legacy' => false,
        ],
        'legacy' => [
            'label' => 'old client (pre-2.0)',
            'env' => ['PRISM_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PRISM_OTEL_ENABLED' => 'false'],
            'legacy' => true,
        ],
        'nightwatch' => [
            'label' => 'Nightwatch only',
            'env' => ['PRISM_ENABLED' => 'true', 'NIGHTWATCH_ENABLED' => 'true', 'PRISM_OTEL_ENABLED' => 'false'],
            'legacy' => false,
        ],
        'otel' => [
            'label' => 'Nightwatch + OTel spans',
            'env' => ['PRISM_ENABLED' => 'true', 'NIGHTWATCH_ENABLED' => 'true', 'PRISM_OTEL_ENABLED' => 'true'],
            'legacy' => false,
        ],
    ];

    /**
     * Environment every child gets whatever its configuration, so the four are
     * compared on one delivery strategy and one sampling rate: a sampled-out
     * execution is discarded whole, so leaving the rate to the host's `.env`
     * would have one configuration measure capture and another measure the
     * sampler refusing it. **`CACHE_STORE=array` is load-bearing**, as this
     * command's own first run found: {@see SystemMetrics} throttles its interval
     * across a replica's processes with an atomic cache `add()` and only the
     * process that *wins* it records the sample in memory, so every other re-asks
     * the cache on every flush — against the host's Redis a round trip per
     * request at 1.19 ms, dwarfing every figure being compared and landing
     * wherever the interval lock was held, so two identical runs disagreed by 3x.
     * That is the cache backend's latency, not capture's, and neither engine's
     * (it predates the epic, unchanged by it), so the benchmark takes the backend
     * out and says so.
     */
    private const SHARED_ENV = [
        'NIGHTWATCH_FORCE_REQUEST' => '1',
        'PRISM_TOKEN' => 'prism-bench',
        'PRISM_APP' => 'prism-bench',
        'PRISM_FLUSH_STRATEGY' => 'terminate',
        'PRISM_QUEUE_THRESHOLD' => '0',
        'PRISM_SAMPLE_REQUESTS' => '1.0',
        'CACHE_STORE' => 'array',
    ];

    /** The line a child prints its measurement on, and the parent reads it back off. */
    private const MARKER = 'PRISM_BENCH_RESULT ';

    /** The route the benchmarked workload lives on. */
    private const ROUTE = '_prism/bench-capture';

    /** The throwaway database connection the workload's queries run against. */
    private const CONNECTION = 'prism_bench';

    /**
     * Namespace prefixes whose presence proves a span left as OTLP: a class from
     * either is loaded on the first byte an OTLP exporter serialises, and
     * `google/protobuf` is in the tree anyway (the exporter requires it), so
     * absence of the *package* would prove nothing where absence of the *class*
     * after a run of thousands of spans proves the exporter was never reached.
     */
    private const PROTOBUF_NAMESPACES = ['Google\\Protobuf', 'Opentelemetry\\Proto'];

    public function handle(): int
    {
        $profile = $this->option('profile');

        if (is_string($profile) && $profile !== '') {
            return $this->measureOne($profile);
        }

        return $this->runComparison();
    }

    // ---------------------------------------------------------------- parent

    /** Spawn one child per configuration, collect their answers and report. */
    private function runComparison(): int
    {
        $requested = $this->requestedProfiles();

        if ($requested === []) {
            $this->components->error('No known configuration selected. Choose from: '.implode(', ', array_keys(self::PROFILES)).'.');

            return self::FAILURE;
        }

        $artisan = base_path('artisan');

        if (! is_file($artisan)) {
            $this->components->error("No artisan file at {$artisan} — the benchmark runs each configuration in its own process and needs one.");

            return self::FAILURE;
        }

        [$legacyDirectory, $legacyProblem] = $this->resolveLegacySources($requested);

        $this->newLine();
        $this->line('<options=bold>Prism capture benchmark</>');
        $this->line(sprintf(
            '  %d requests x %d rounds each (%d warm-up), per request: %d queries, %d cache operations, %d log lines',
            $this->intOption('requests'), max(1, $this->intOption('rounds')), $this->intOption('warmup'),
            $this->intOption('queries'), $this->intOption('cache'), $this->intOption('logs'),
        ));
        $this->newLine();

        $rounds = max(1, $this->intOption('rounds'));

        /** @var array<string, list<BenchResult>> $rows */
        $rows = [];

        // Round-robin, not one configuration at a time: this box's own load
        // moves the same measurement by 40% between two runs a second apart, so
        // finishing one before the next would charge the machine's drift to
        // whichever was running and flap the gate on ambient load, not the code
        // under test. Interleaving spreads that drift over all four; the median
        // round then discards the excursions rather than averaging them in.
        for ($round = 0; $round < $rounds; $round++) {
            if ($rounds > 1) {
                $this->line(sprintf('  <fg=gray>round %d/%d</>', $round + 1, $rounds));
            }

            foreach ($requested as $name) {
                if (self::PROFILES[$name]['legacy'] && $legacyDirectory === null) {
                    $rows[$name][] = BenchResult::skipped($name, self::PROFILES[$name]['label'], $legacyProblem ?? 'sources unavailable');

                    continue;
                }

                $rows[$name][] = $this->runChild($name, $artisan, $legacyDirectory);
            }
        }

        $results = array_map($this->medianRound(...), $rows);

        $this->report($results);

        return $this->verdict($results);
    }

    /**
     * Run one configuration in its own process and parse the result back. Its
     * whole stdout is kept when it fails: a configuration that cannot even boot
     * is a real finding (a missing extension, a fatal in the reconstructed old
     * client), and swallowing it would leave an unexplained blank row.
     */
    private function runChild(string $name, string $artisan, ?string $legacyDirectory): BenchResult
    {
        $profile = self::PROFILES[$name];

        $arguments = [
            PHP_BINARY, $artisan, $this->getName() ?? 'prism:bench:capture',
            '--profile='.$name,
            '--requests='.$this->intOption('requests'),
            '--warmup='.$this->intOption('warmup'),
            '--queries='.$this->intOption('queries'),
            '--cache='.$this->intOption('cache'),
            '--logs='.$this->intOption('logs'),
        ];

        if ($legacyDirectory !== null) {
            $arguments[] = '--legacy-source='.$legacyDirectory;
        }

        $process = new Process(
            $arguments,
            base_path(),
            array_merge(self::SHARED_ENV, $profile['env']),
            null,
            600.0,
        );

        $this->components->task(sprintf('%-24s', $profile['label']), static function () use ($process): bool {
            $process->run();

            return $process->isSuccessful();
        });

        $answer = $this->parseChildOutput($process->getOutput());

        if ($answer !== null) {
            // Under -v, what was live in the child: a silent failure to install
            // looks exactly like a genuinely cheap configuration.
            if ($this->output->isVerbose() && is_array($answer['state'] ?? null)) {
                $this->line('    <fg=gray>'.json_encode($answer['state']).'</>');
            }

            return BenchResult::fromArray($answer);
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        $this->newLine();
        $this->line("  <fg=red>{$name} produced no measurement:</>");
        $this->line('  '.str_replace("\n", "\n  ", $output === '' ? '(no output)' : $output));
        $this->newLine();

        return BenchResult::skipped($name, $profile['label'], 'the child process produced no measurement');
    }

    /**
     * The middle round by wall-clock — the whole round, not a field-by-field
     * median: mixing one round's timing with another's event count would report
     * a request that never happened. A configuration that failed in any round is
     * skipped rather than quoted from the rounds that did run.
     *
     * @param  list<BenchResult>  $rounds
     */
    private function medianRound(array $rounds): BenchResult
    {
        $measured = array_values(array_filter($rounds, static fn (BenchResult $r): bool => $r->measured));

        if (count($measured) !== count($rounds)) {
            foreach ($rounds as $round) {
                if (! $round->measured) {
                    return $round;
                }
            }
        }

        usort($measured, static fn (BenchResult $a, BenchResult $b): int => $a->meanUs <=> $b->meanUs);

        return $measured[intdiv(count($measured), 2)];
    }

    /**
     * Read the one marker line a child prints, ignoring everything else it wrote.
     *
     * @return array<string, mixed>|null
     */
    private function parseChildOutput(string $output): ?array
    {
        foreach (array_reverse(explode("\n", $output)) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, self::MARKER)) {
                continue;
            }

            $decoded = json_decode(substr($line, strlen(self::MARKER)), true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Where the old client's sources are, or why they are not available.
     *
     * @param  list<string>  $requested
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveLegacySources(array $requested): array
    {
        $wanted = array_filter($requested, static fn (string $name): bool => self::PROFILES[$name]['legacy']);

        if ($wanted === []) {
            return [null, null];
        }

        $explicit = $this->option('legacy-source');

        if (is_string($explicit) && $explicit !== '') {
            return is_dir($explicit.'/Capture')
                ? [$explicit, null]
                : [null, "{$explicit} does not hold the old client's sources"];
        }

        $ref = $this->option('legacy-ref');
        $ref = is_string($ref) && $ref !== '' ? $ref : LegacyCapturePath::REF;
        $target = storage_path('app/prism-bench/legacy');

        [$directory, $problem] = LegacyCapturePath::materialise(base_path(), $target, $ref);

        if ($directory !== null) {
            return [$directory, null];
        }

        $this->newLine();
        $this->components->warn("The old client could not be exported from git ({$problem}).");
        $this->line('  Export it once from a shell that has git, then run this again:');
        $this->line('    '.LegacyCapturePath::exportCommand($target, $ref));
        $this->newLine();

        return [null, $problem];
    }

    /** @return list<string> */
    private function requestedProfiles(): array
    {
        $option = $this->option('profiles');

        if (! is_string($option) || trim($option) === '') {
            return array_keys(self::PROFILES);
        }

        $names = array_map(trim(...), explode(',', $option));

        return array_values(array_filter($names, static fn (string $name): bool => isset(self::PROFILES[$name])));
    }

    // ---------------------------------------------------------------- report

    /**
     * Print the comparison, every capture figure quoted as what it *adds* to the
     * same workload with nothing capturing: the absolute numbers are a property
     * of this machine, the added ones a property of the change.
     *
     * @param  array<string, BenchResult>  $results
     */
    private function report(array $results): void
    {
        $baseline = $results['off'] ?? null;
        $rows = [];

        foreach ($results as $result) {
            if (! $result->measured) {
                $rows[] = [$result->label, 'skipped', '—', '—', '—', $result->skipReason ?? ''];

                continue;
            }

            $rows[] = [
                $result->label,
                $this->formatMicroseconds($result->meanUs),
                $this->addedWallClock($result, $baseline),
                $this->addedMemory($result, $baseline),
                number_format($result->eventsPerRequest, 1),
                $this->notes($result),
            ];
        }

        $headers = ['configuration', 'per request', 'added', 'added peak mem', 'events/req', 'notes'];

        if ($this->option('markdown')) {
            $this->newLine();
            $this->line('| '.implode(' | ', $headers).' |');
            $this->line('|'.str_repeat(' --- |', count($headers)));

            foreach ($rows as $row) {
                $this->line('| '.implode(' | ', $row).' |');
            }

            $this->newLine();

            return;
        }

        $this->newLine();
        $this->table($headers, $rows);
    }

    private function addedWallClock(BenchResult $result, ?BenchResult $baseline): string
    {
        if ($baseline === null || ! $baseline->measured || $result->profile === 'off') {
            return '—';
        }

        return $this->formatMicroseconds($result->meanUs - $baseline->meanUs, signed: true);
    }

    private function addedMemory(BenchResult $result, ?BenchResult $baseline): string
    {
        if ($baseline === null || ! $baseline->measured || $result->profile === 'off') {
            return '—';
        }

        $added = $result->peakMemoryBytes - $baseline->peakMemoryBytes;

        return sprintf('%+.0f kB', $added / 1024);
    }

    /** The per-row footnotes: anything that would change how a figure is read. */
    private function notes(BenchResult $result): string
    {
        $notes = [];

        if ($result->bufferDropped > 0) {
            $notes[] = "buffer dropped {$result->bufferDropped} (capacity {$result->bufferCapacity})";
        }

        if ($result->translateDropped > 0) {
            $notes[] = "untranslated {$result->translateDropped}";
        }

        if ($result->protobufTouched) {
            $notes[] = 'PROTOBUF LOADED';
        }

        return implode(', ', $notes);
    }

    private function formatMicroseconds(float $microseconds, bool $signed = false): string
    {
        $format = $signed ? '%+.2f ms' : '%.2f ms';

        return sprintf($format, $microseconds / 1000);
    }

    /**
     * The gate (AC4). Two things fail it, neither a matter of degree: costing
     * the host more wall-clock per request than the old client did, and any sign
     * a span left the process as OTLP. A requested-but-unmeasurable old client
     * fails too: the comparison is the point, and three numbers with nothing to
     * compare against under exit zero is how a regression is recorded as a note.
     *
     * @param  array<string, BenchResult>  $results
     */
    private function verdict(array $results): int
    {
        $failures = [];

        foreach ($results as $result) {
            if ($result->measured && $result->protobufTouched) {
                $failures[] = "{$result->label} loaded a protobuf class — a span left this process as OTLP.";
            }
        }

        $legacy = $results['legacy'] ?? null;
        $baseline = $results['off'] ?? null;

        if ($legacy !== null && ! $legacy->measured) {
            $failures[] = 'The old client could not be measured, so the regression gate did not run. '
                .'Export its sources (see above), or ask for the other configurations explicitly with --profiles.';
        }

        if ($legacy?->measured && $baseline?->measured) {
            $tolerance = 1 + max(0.0, (float) $this->option('tolerance')) / 100;
            $legacyAdded = $legacy->meanUs - $baseline->meanUs;

            foreach ($results as $name => $result) {
                if (! $result->measured || in_array($name, ['off', 'legacy'], true)) {
                    continue;
                }

                $added = $result->meanUs - $baseline->meanUs;

                if ($added > $legacyAdded * $tolerance) {
                    $failures[] = sprintf(
                        '%s adds %s per request against the old client\'s %s — a regression, not a note. '
                        .'Attribute it before acting: re-run with --queries/--cache/--logs varied one at a '
                        .'time, which is what says whether the cost is fixed per request or per signal, and '
                        .'which signal. See "Capture cost" in packages/prism/README.md.',
                        $result->label,
                        $this->formatMicroseconds($added, signed: true),
                        $this->formatMicroseconds($legacyAdded, signed: true),
                    );
                }
            }
        }

        if ($failures === []) {
            $this->components->info($legacy?->measured && $baseline?->measured
                ? 'The capture path is no more expensive per request than the old client.'
                : 'No configuration failed, but the old client was not among those measured — nothing was compared.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        return self::FAILURE;
    }

    // ----------------------------------------------------------------- child

    /** Measure one configuration in this process and print it as JSON. */
    private function measureOne(string $profile): int
    {
        if (! isset(self::PROFILES[$profile])) {
            $this->components->error("Unknown configuration \"{$profile}\".");

            return self::FAILURE;
        }

        try {
            $result = $this->measure($profile);
        } catch (Throwable $e) {
            $this->components->error("{$profile}: {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})");

            return self::FAILURE;
        }

        $this->line(self::MARKER.json_encode($result->toArray() + ['state' => $this->state($profile)]));

        return self::SUCCESS;
    }

    /** Drive the workload `warmup + requests` times, reducing the timed half to one result. */
    private function measure(string $profile): BenchResult
    {
        $application = $this->laravel;
        $transport = new BenchTransport;

        if ($application->bound(PrismServiceProvider::ACTIVE)) {
            $application->instance(Transport::class, $transport);
        }

        if (self::PROFILES[$profile]['legacy']) {
            $this->installLegacy($transport);
        }

        $this->prepareWorkload();

        /** @var Kernel $kernel */
        $kernel = $application->make(HttpKernelContract::class);

        $core = $application->bound(Core::class) ? $application->make(Core::class) : null;
        $requestScoped = $core instanceof Core && $core->executionState instanceof RequestState && ! $core->paused();

        $warmup = max(0, $this->intOption('warmup'));
        $requests = max(1, $this->intOption('requests'));
        $durations = [];
        $peak = 0;

        $buffer = $application->bound(EventBuffer::class) ? $application->make(EventBuffer::class) : null;
        $ingest = $application->bound(PrismIngest::class) ? $application->make(PrismIngest::class) : null;
        $bufferDropped = 0;
        $translateDropped = 0;

        for ($iteration = 0; $iteration < $warmup + $requests; $iteration++) {
            $timed = $iteration >= $warmup;

            if ($timed && $iteration === $warmup) {
                $transport->reset();
            }

            $request = Request::create('/'.self::ROUTE, 'GET');

            // The reset a long-lived runtime performs between requests: without
            // it the engine's global middleware — a `scoped` binding holding a
            // `hasHandledRequest` flag — samples the first request and waves the
            // rest through, timing one captured request and N-1 uncaptured ones.
            $application->forgetScopedInstances();
            TraceContext::reset();
            Recursion::reset();

            if ($requestScoped) {
                $core->prepareForRequest($request);
            } else {
                memory_reset_peak_usage();
            }

            $start = hrtime(true);
            $response = $kernel->handle($request);

            // Read the drop counters HERE, between the request and the flush:
            // both are "since the last drain" and terminate is about to drain,
            // so a count read afterwards is always zero and the report would say
            // a run dropped nothing however much it threw away.
            if ($timed) {
                $bufferDropped += $buffer?->dropped() ?? 0;
                $translateDropped += $ingest?->dropped() ?? 0;
            }

            $kernel->terminate($request, $response);
            $elapsed = hrtime(true) - $start;

            if ($iteration === 0 && $response->getStatusCode() !== 200) {
                throw new RuntimeException(
                    "the benchmark route answered {$response->getStatusCode()}, so nothing measured here would mean anything",
                );
            }

            if ($timed) {
                $durations[] = $elapsed;
                $peak = max($peak, memory_get_peak_usage());
            }
        }

        sort($durations);

        return new BenchResult(
            profile: $profile,
            label: self::PROFILES[$profile]['label'],
            measured: true,
            skipReason: null,
            requests: $requests,
            meanUs: array_sum($durations) / count($durations) / 1000,
            medianUs: $durations[intdiv(count($durations), 2)] / 1000,
            p95Us: $durations[min(count($durations) - 1, (int) floor(count($durations) * 0.95))] / 1000,
            peakMemoryBytes: $peak,
            eventsPerRequest: $transport->events() / $requests,
            bufferDropped: $bufferDropped,
            translateDropped: $translateDropped,
            bufferCapacity: $buffer?->capacity() ?? 0,
            protobufTouched: $this->protobufTouched(),
        );
    }

    /** Bring the pre-2.0 client back and wire it up. */
    private function installLegacy(BenchTransport $transport): void
    {
        $directory = $this->option('legacy-source');

        if (! is_string($directory) || ! is_dir($directory.'/Capture')) {
            throw new RuntimeException('the old client\'s sources were not supplied — pass --legacy-source');
        }

        LegacyCapturePath::autoload($directory);
        LegacyCapturePath::install($this->laravel, $transport);
    }

    /**
     * Register the benchmarked route and the throwaway datastore behind it.
     * Queries run against the benchmark's own in-memory SQLite connection and
     * the cache operations against the array store, not the host's: the figure
     * measured is what *capturing* a query costs, where a real connection would
     * fold the network and the planner into every row. Both still raise the
     * framework events every engine listens for, which is all capture sees.
     */
    private function prepareWorkload(): void
    {
        config([
            'database.connections.'.self::CONNECTION => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        $connection = DB::connection(self::CONNECTION);
        $connection->statement('create table if not exists prism_bench_rows (id integer primary key, payload text)');

        for ($id = 1; $id <= 8; $id++) {
            $connection->statement('insert or replace into prism_bench_rows (id, payload) values (?, ?)', [$id, "row {$id}"]);
        }

        $queries = max(0, $this->intOption('queries'));
        $cacheOperations = max(0, $this->intOption('cache'));
        $logLines = max(0, $this->intOption('logs'));

        Route::get(self::ROUTE, static function () use ($queries, $cacheOperations, $logLines) {
            $connection = DB::connection(self::CONNECTION);

            for ($i = 0; $i < $queries; $i++) {
                $connection->select('select id, payload from prism_bench_rows where id = ?', [$i % 8 + 1]);
            }

            $store = Cache::store('array');

            for ($i = 0; $i < $cacheOperations; $i++) {
                $key = 'bench:key:'.$i;
                $store->get($key);
                $store->put($key, $i, 60);
            }

            for ($i = 0; $i < $logLines; $i++) {
                Log::info('prism capture benchmark', ['iteration' => $i]);
            }

            return response('ok');
        });
    }

    /**
     * Whether any protobuf class has loaded in this process (AC6), read off
     * `get_declared_classes()` and not from whether the package is installed —
     * it is, the OTLP exporter requires it, and the claim worth checking is that
     * nothing ever reached for it.
     */
    private function protobufTouched(): bool
    {
        foreach (get_declared_classes() as $class) {
            foreach (self::PROTOBUF_NAMESPACES as $namespace) {
                if (str_starts_with($class, $namespace.'\\')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What was live in this process, so a report row is audited rather than
     * trusted: a silent install failure looks exactly like a cheap configuration.
     *
     * @return array<string, bool|string>
     */
    private function state(string $profile): array
    {
        $application = $this->laravel;

        $core = $application->bound(Core::class) ? $application->make(Core::class) : null;

        return [
            'profile' => $profile,
            'prism_pipeline' => $application->bound(PrismServiceProvider::ACTIVE),
            'nightwatch_enabled' => $core instanceof Core && $core->enabled() && ! $core->paused(),
            'prism_ingest' => $core instanceof Core && $core->ingest instanceof PrismIngest,
            'span_processor' => $application->bound(SpanLane::class) && $application->make(SpanLane::class)->registered(),
            'legacy_capture' => class_exists('Misakstvanu\\Prism\\Capture\\CaptureRequests', autoload: false),
        ];
    }

    private function intOption(string $name): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : 0;
    }
}
