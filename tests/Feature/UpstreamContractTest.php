<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessed;
use Laravel\Nightwatch\Clock;
use Laravel\Nightwatch\Compatibility;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Location;
use Laravel\Nightwatch\SensorManager;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\UserProvider;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RecordTranslator;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * The upstream surface this package is built on, pinned so a `composer update`
 * fails loudly instead of quietly emptying a screen (US-022).
 *
 * Prism no longer captures anything itself. It stands on two engines and
 * reaches into both, and the load-bearing half of what it reaches into carries
 * **no compatibility promise at all**:
 *
 *   - `Laravel\Nightwatch\Contracts\Ingest` is marked `@internal`, and
 *     {@see PrismIngest} implements it. A method added to it is a fatal error
 *     on the next request; a method removed from it is a code path that stops
 *     being called, silently.
 *   - `Core::$ingest` is marked `@internal` too, and the whole "no agent
 *     daemon" architecture is one assignment to it. Made `readonly`, or
 *     re-typed, or made private, and the swap stops happening — leaving
 *     Nightwatch talking to a socket nobody is listening on.
 *   - The **record shape** is a plain array with no schema anywhere. Rename a
 *     field and {@see RecordTranslator} maps a null: the event still ships, the
 *     column is still written, and it holds an empty string. Nothing errors.
 *   - The per-request counters increment **inside the lazy resolver**, which is
 *     the entire reason US-018 drops a superseded record in Prism's own ingest
 *     rather than through one of the engine's `reject*` callbacks. Move that
 *     `++` out of the closure and a request that ran forty queries reports
 *     none.
 *
 * The `@api` half — `sample`, `report`, the `redact*` and `reject*` callbacks —
 * is the safer one and is asserted anyway: `@api` is a promise about intent,
 * not a guarantee of signature.
 *
 * **A red build here is not a bug in Prism**; it is an upstream release that
 * moved something Prism reads. Each failure names the exact method, property,
 * record field or version that moved: translate it where Prism reads it, then
 * update the pin below to the new shape.
 */

/** What a failure in this file means, said the same way every time. */
function upstreamContractGuide(string $what): string
{
    return $what.' — an upstream release has moved something Prism reads. '
        .'Translate it where Prism reads it, then update the pin in this file.';
}

/**
 * A command-scoped execution state, for the two sensors that take one and
 * nothing else (a job attempt and a scheduled task).
 *
 * Uniquely named because Pest loads every test file into one process.
 */
function upstreamContractCommandState(): CommandState
{
    return new CommandState(
        timestamp: microtime(true),
        trace: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        id: 'execution-5678',
        deploy: 'deploy-7',
        server: 'worker-01',
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: new UserProvider(
            withAuth: fn (callable $callback) => $callback(app('auth')),
            userDetailsResolverResolver: fn () => null,
            reportResolver: fn () => static fn (Authenticatable $user) => null,
        ),
        artisan: new ConsoleApplication(app(), app('events'), app()->version()),
        name: 'list',
    );
}

/**
 * A request-scoped `SensorManager` over a state the caller keeps a handle on,
 * so the counters that state carries can be read before and after a resolver
 * runs.
 */
function upstreamContractSensorManager(RequestState $state): SensorManager
{
    return nightwatchSensorManager($state, new Clock, new Location(basePath: base_path(), publicPath: public_path()));
}

/** A fresh request state, with every counter at zero. */
function upstreamContractRequestState(): RequestState
{
    return new RequestState(
        timestamp: microtime(true),
        trace: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        id: 'execution-1234',
        deploy: 'deploy-7',
        server: 'web-01',
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: new UserProvider(
            withAuth: fn (callable $callback) => $callback(app('auth')),
            userDetailsResolverResolver: fn () => null,
            reportResolver: fn () => static fn (Authenticatable $user) => null,
        ),
    );
}

/*
|--------------------------------------------------------------------------
| The @internal seam: the ingest contract and the property it hangs on
|--------------------------------------------------------------------------
*/

it('pins the seven methods Contracts\Ingest declares', function () {
    $declared = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(Ingest::class))->getMethods(),
    );

    sort($declared);

    // Exactly these, and no others. A method ADDED upstream is a fatal error
    // the first time Nightwatch calls it on PrismIngest; a method REMOVED is a
    // path Prism still implements that nothing will ever reach again.
    expect($declared)->toBe([
        'digest', 'flush', 'ping', 'shouldDigest', 'shouldDigestWhenBufferIsFull', 'write', 'writeNow',
    ], upstreamContractGuide('Contracts\Ingest no longer declares exactly the seven methods PrismIngest implements'));
});

it('pins PrismIngest against every signature the ingest contract declares', function () {
    $contract = new ReflectionClass(Ingest::class);
    $ours = new ReflectionClass(PrismIngest::class);

    expect($ours->implementsInterface(Ingest::class))->toBeTrue(
        upstreamContractGuide('PrismIngest no longer implements Contracts\Ingest'),
    );

    foreach ($contract->getMethods() as $method) {
        $name = $method->getName();

        expect($ours->hasMethod($name))->toBeTrue(
            upstreamContractGuide("PrismIngest does not declare {$name}()"),
        );

        $mine = $ours->getMethod($name);

        expect($mine->isPublic())->toBeTrue(
            upstreamContractGuide("PrismIngest::{$name}() is no longer public"),
        );

        expect((string) $mine->getReturnType())->toBe(
            (string) $method->getReturnType(),
            upstreamContractGuide("PrismIngest::{$name}() no longer returns what the contract declares"),
        );

        expect($mine->getNumberOfParameters())->toBe(
            $method->getNumberOfParameters(),
            upstreamContractGuide("PrismIngest::{$name}() no longer takes what the contract declares"),
        );

        foreach ($method->getParameters() as $index => $parameter) {
            expect((string) $mine->getParameters()[$index]->getType())->toBe(
                (string) $parameter->getType(),
                upstreamContractGuide("PrismIngest::{$name}() parameter #{$index} is typed differently from the contract"),
            );
        }
    }
});

it('pins Core::$ingest as a public, mutable, interface-typed property', function () {
    $property = (new ReflectionClass(Core::class))->getProperty('ingest');

    // The whole "no agent daemon" architecture is one assignment to this
    // property, made from `PrismServiceProvider::registerCapture()` inside an
    // `app->booted()` callback. Each of these four facts is separately load
    // bearing, and each fails silently: a private or readonly property throws
    // where the swap happens (inside a `try` that logs at debug level), and a
    // narrowed type would refuse PrismIngest outright.
    expect($property->isPublic())->toBeTrue(
        upstreamContractGuide('Core::$ingest is no longer public, so Prism cannot swap its own ingest in'),
    );

    expect($property->isReadOnly())->toBeFalse(
        upstreamContractGuide('Core::$ingest is now readonly, so Prism cannot swap its own ingest in'),
    );

    expect($property->isStatic())->toBeFalse(
        upstreamContractGuide('Core::$ingest is now static'),
    );

    expect((string) $property->getType())->toBe(
        Ingest::class,
        upstreamContractGuide('Core::$ingest is no longer typed to Contracts\Ingest'),
    );
});

/*
|--------------------------------------------------------------------------
| The @api surface Prism drives
|--------------------------------------------------------------------------
*/

it('pins the Core methods Prism calls', function (string $method, int $required) {
    $core = new ReflectionClass(Core::class);

    expect($core->hasMethod($method))->toBeTrue(
        upstreamContractGuide("Core::{$method}() is gone"),
    );

    $reflected = $core->getMethod($method);

    expect($reflected->isPublic())->toBeTrue(
        upstreamContractGuide("Core::{$method}() is no longer public"),
    );

    expect($reflected->getNumberOfRequiredParameters())->toBe(
        $required,
        upstreamContractGuide("Core::{$method}() no longer takes {$required} required argument(s)"),
    );
})->with([
    // Sampling — how Prism refuses a whole execution (`prism.ignore.paths`,
    // `.commands`, `.jobs`), and how the package's own suite drives the
    // discard-vs-send branch of `finishExecution()`.
    ['sample', 0],
    ['dontSample', 0],
    ['sampling', 0],

    // The manual report behind `Prism::captureException()`, and the identity
    // and suppression hooks.
    ['report', 1],
    ['user', 1],
    ['ignore', 1],

    // The runtime kill switch. Hooks cannot be un-registered, so `pause()` is
    // the only thing that silences a Nightwatch which registered before Prism
    // decided it should be off.
    ['pause', 0],
    ['resume', 0],
    ['paused', 0],

    // `prism.scrub`, said in the capture engine's vocabulary. Seven, not six:
    // upstream grew `redactCommands` beside the rest, and Prism registers a
    // callback on every one of them.
    ['redactCacheEvents', 1],
    ['redactCommands', 1],
    ['redactExceptions', 1],
    ['redactMail', 1],
    ['redactOutgoingRequests', 1],
    ['redactQueries', 1],
    ['redactRequests', 1],

    // `prism.ignore.*`, likewise. `rejectCacheKeys` takes a list of REGEXES
    // rather than a callback, which is why a raw `prism:*` pattern silences
    // precisely nothing.
    ['rejectCacheEvents', 1],
    ['rejectCacheKeys', 1],
    ['rejectMail', 1],
    ['rejectNotifications', 1],
    ['rejectOutgoingRequests', 1],
    ['rejectQueries', 1],
    ['rejectQueuedJobs', 1],
]);

it('pins the reject and redact hooks as callback-taking, so a rule is still consulted per record', function (string $method) {
    $parameter = (new ReflectionClass(Core::class))->getMethod($method)->getParameters()[0];

    expect((string) $parameter->getType())->toBe(
        'callable',
        upstreamContractGuide("Core::{$method}() no longer takes a callable"),
    );
})->with([
    'redactCacheEvents', 'redactCommands', 'redactExceptions', 'redactMail',
    'redactOutgoingRequests', 'redactQueries', 'redactRequests',
    'rejectCacheEvents', 'rejectMail', 'rejectNotifications',
    'rejectOutgoingRequests', 'rejectQueries', 'rejectQueuedJobs',
]);

/*
|--------------------------------------------------------------------------
| The record shape
|--------------------------------------------------------------------------
*/

it('hands a record to the ingest for every signal a real execution produces', function () {
    Compatibility::boot(app());

    $requestIngest = new RecordingNightwatchIngest;
    $requestState = upstreamContractRequestState();

    /** @var Core<RequestState> $core */
    $core = makeNightwatchCore(ingest: $requestIngest, executionState: $requestState);

    [$request, $response] = nightwatchHttpExchange();

    $core->request($request, $response);
    $core->query(new QueryExecuted(
        sql: 'select * from "orders" where "id" = ?',
        bindings: [5],
        time: 12.5,
        connection: app('db')->connection(),
    ));
    $core->log(new LogRecord(
        datetime: new DateTimeImmutable('2026-08-20 09:15:30.123456', new DateTimeZone('UTC')),
        channel: 'testing',
        level: Level::Warning,
        message: 'Disk almost full',
        context: ['free' => '2%'],
    ));
    // `handled: true` on purpose: an unhandled exception goes out through
    // `writeNow()`, and the branch this test is about is the buffered one.
    $core->report(new RuntimeException('Order 5 could not be priced.'), handled: true);

    $commandIngest = new RecordingNightwatchIngest;

    /** @var Core<CommandState> $commandCore */
    $commandCore = makeNightwatchCore(ingest: $commandIngest, executionState: upstreamContractCommandState());

    $commandCore->jobAttempt(new JobProcessed('redis', nightwatchQueueJobStub()));
    $commandCore->scheduledTask(new ScheduledTaskFinished(
        app(Schedule::class)->exec('php -v')->everyFiveMinutes(),
        1.234,
    ));

    $written = [...$requestIngest->written, ...$commandIngest->written];

    $types = array_map(static fn (array $record): mixed => $record['t'] ?? null, $written);

    sort($types);

    // Six signals in, six records out. Non-vacuous in both directions: a sensor
    // that stopped emitting drops a name from this list, and a Core entry point
    // that stopped writing empties the recording ingest entirely.
    expect($types)->toBe(
        ['exception', 'job-attempt', 'log', 'query', 'request', 'scheduled-task'],
        upstreamContractGuide('a real execution no longer hands the ingest one record per signal'),
    );

    // The globals every record carries, whatever its type — the four Prism's
    // envelope is built from plus the two that identify the deploy. `user` and
    // `execution_id` are deliberately NOT here: see the next test.
    foreach ($written as $record) {
        foreach (['v', 't', 'timestamp', 'deploy', 'server', 'trace_id'] as $global) {
            expect(array_key_exists($global, $record))->toBeTrue(
                upstreamContractGuide("a {$record['t']} record no longer carries '{$global}'"),
            );
        }

        expect(is_float($record['timestamp']))->toBeTrue(
            upstreamContractGuide("a {$record['t']} record's timestamp is no longer a float microtime"),
        );

        expect($record['server'])->toBeIn(
            ['web-01', 'worker-01'],
            upstreamContractGuide("a {$record['t']} record no longer names the execution's own server"),
        );
    }
});

it('pins which record types carry a user and which carry an execution id', function () {
    $records = nightwatchRecords();

    // A `user` is the authenticated identity of whoever caused the signal.
    // A command and a scheduled task have no such thing, and their records say
    // so by omission rather than by a null — which is why the translator reads
    // it with a `??` and why `user_id` is Nullable server-side.
    $withUser = [];
    $withExecutionId = [];

    foreach ($records as $t => $record) {
        if (array_key_exists('user', $record)) {
            $withUser[] = $t;
        }

        if (array_key_exists('execution_id', $record)) {
            $withExecutionId[] = $t;
        }
    }

    sort($withUser);
    sort($withExecutionId);

    expect($withUser)->toBe([
        'cache-event', 'exception', 'job-attempt', 'log', 'mail',
        'notification', 'outgoing-request', 'query', 'queued-job', 'request',
    ], upstreamContractGuide('the set of record types carrying a user has changed'));

    // The four missing names are the four records that ARE an execution — a
    // request, a command, a job attempt and a scheduled task — which is why
    // PrismIngest stamps a request record's `request_id` from the execution
    // state rather than from the record.
    expect($withExecutionId)->toBe([
        'cache-event', 'exception', 'log', 'mail',
        'notification', 'outgoing-request', 'query', 'queued-job',
    ], upstreamContractGuide('the set of record types carrying an execution id has changed'));
});

it('pins every per-type field the translator reads', function () {
    $records = nightwatchRecords();

    foreach (upstreamContractFields() as $t => $fields) {
        expect(array_key_exists($t, $records))->toBeTrue(
            upstreamContractGuide("Nightwatch no longer emits a '{$t}' record"),
        );

        foreach ($fields as $field) {
            expect(array_key_exists($field, $records[$t]))->toBeTrue(
                upstreamContractGuide("a {$t} record no longer carries '{$field}', which RecordTranslator reads"),
            );
        }
    }
});

it('pins the record version each type is at, per type', function () {
    $records = nightwatchRecords();

    $versions = [];

    foreach ($records as $t => $record) {
        $versions[$t] = $record['v'];
    }

    ksort($versions);

    // Versioned PER RECORD TYPE, and they are not all 1: an exception record
    // is at 3 where every other record is at 1. A flat `v === 1` gate — the
    // obvious reading — therefore drops every exception the host ever reports,
    // silently and forever. RecordTranslator::VERSIONS is this table, and a
    // bump on either side must move both.
    expect($versions)->toBe([
        'cache-event' => 1,
        'command' => 1,
        'exception' => 3,
        'job-attempt' => 1,
        'log' => 1,
        'mail' => 1,
        'notification' => 1,
        'outgoing-request' => 1,
        'query' => 1,
        'queued-job' => 1,
        'request' => 1,
        'scheduled-task' => 1,
    ], upstreamContractGuide('a record type has been re-versioned upstream'));
});

it('still translates a real record of every type into a Prism event', function () {
    $translator = new RecordTranslator;

    foreach (nightwatchRecords() as $t => $record) {
        $event = $translator->translate($record);

        // The end-to-end form of the version gate: a bumped `v` upstream makes
        // `translate()` answer null, which on the wire is a signal that simply
        // stops arriving. Nothing errors, no count moves anywhere a person
        // looks, and the screen for that signal goes quiet.
        expect($event !== null)->toBeTrue(
            upstreamContractGuide("RecordTranslator no longer translates a real '{$t}' record"),
        );
    }
});

/*
|--------------------------------------------------------------------------
| The behavioural property US-018 rests on
|--------------------------------------------------------------------------
*/

it('pins the per-execution counters to the inside of the lazy resolver', function () {
    Compatibility::boot(app());

    $state = upstreamContractRequestState();
    $manager = upstreamContractSensorManager($state);

    // A query, an outgoing call and a cache read: the three signals whose
    // sensors return `[$record, $resolver]` and bump the execution's own
    // counter INSIDE the resolver.
    $query = $manager->query(new QueryExecuted(
        sql: 'select * from "orders" where "id" = ?',
        bindings: [5],
        time: 12.5,
        connection: app('db')->connection(),
    ), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 5));

    $outgoing = $manager->outgoingRequest(
        microtime(true) - 0.25,
        microtime(true),
        new Request('GET', 'https://api.example.com/v1/things?page=2'),
        new Response(201, [], 'created'),
    );

    $manager->cacheEvent(new RetrievingKey('redis', 'orders:5'));
    $cache = $manager->cacheEvent(new CacheHit('redis', 'orders:5', ['id' => 5]));

    expect($cache !== null)->toBeTrue(
        upstreamContractGuide('the cache sensor no longer emits a record for a completed retrieval'),
    );

    // Building the record has moved nothing. This is the half that matters:
    // a `reject*` callback returns BEFORE the resolver runs, so rejecting one
    // of these signals upstream would also take away the counter — which is
    // why US-018 drops a superseded record in Prism's own ingest instead.
    expect([$state->queries, $state->outgoingRequests, $state->cacheEvents])->toBe(
        [0, 0, 0],
        upstreamContractGuide('a per-execution counter now increments when the record is BUILT, not when it is resolved'),
    );

    nightwatchResolveRecord($query);
    nightwatchResolveRecord($outgoing);
    nightwatchResolveRecord($cache);

    expect([$state->queries, $state->outgoingRequests, $state->cacheEvents])->toBe(
        [1, 1, 1],
        upstreamContractGuide('a per-execution counter no longer increments inside the lazy resolver'),
    );
});

/*
|--------------------------------------------------------------------------
| The span lane's seam
|--------------------------------------------------------------------------
*/

it('pins keepsuit’s traces.processors slot', function () {
    /** @var array<string, mixed> $defaults */
    $defaults = require dirname(__DIR__, 2).'/vendor/keepsuit/laravel-opentelemetry/config/opentelemetry.php';

    /** @var array<string, mixed> $traces */
    $traces = $defaults['traces'] ?? [];

    // The slot Prism adds `PrismSpanProcessor` to. Registering through it —
    // rather than by replacing the `TracerProvider` — is what leaves a host
    // free to publish its own OTel config and export to its own collector.
    expect(array_key_exists('processors', $traces))->toBeTrue(
        upstreamContractGuide('keepsuit no longer offers a traces.processors slot'),
    );

    expect(is_array($traces['processors']))->toBeTrue(
        upstreamContractGuide('keepsuit’s traces.processors slot is no longer a list'),
    );

    $provider = (string) file_get_contents(
        dirname(__DIR__, 2).'/vendor/keepsuit/laravel-opentelemetry/src/LaravelOpenTelemetryServiceProvider.php',
    );

    // A key in the published config that nothing reads would be a slot in name
    // only — the failure mode a config-level assertion cannot see.
    expect(str_contains($provider, 'opentelemetry.traces.processors'))->toBeTrue(
        upstreamContractGuide('keepsuit’s service provider no longer reads opentelemetry.traces.processors'),
    );

    expect(str_contains($provider, 'SpanProcessorInterface'))->toBeTrue(
        upstreamContractGuide('keepsuit no longer type-checks a configured processor against SpanProcessorInterface'),
    );
});

it('pins SpanProcessorInterface and the processor Prism registers through it', function () {
    $declared = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(SpanProcessorInterface::class))->getMethods(),
    );

    sort($declared);

    // `onStart` is the one that is easy to lose: it is where a trace's origin
    // is remembered (a span's `offset_ms` has no zero point without it) and
    // where the OTel trace id is published for every other signal to adopt.
    expect($declared)->toBe(
        ['forceFlush', 'onEnd', 'onStart', 'shutdown'],
        upstreamContractGuide('SpanProcessorInterface no longer declares exactly the four methods PrismSpanProcessor implements'),
    );

    $ours = new ReflectionClass(PrismSpanProcessor::class);

    expect($ours->implementsInterface(SpanProcessorInterface::class))->toBeTrue(
        upstreamContractGuide('PrismSpanProcessor no longer implements SpanProcessorInterface'),
    );

    foreach ((new ReflectionClass(SpanProcessorInterface::class))->getMethods() as $method) {
        $name = $method->getName();
        $mine = $ours->getMethod($name);

        expect((string) $mine->getReturnType())->toBe(
            (string) $method->getReturnType(),
            upstreamContractGuide("PrismSpanProcessor::{$name}() no longer returns what the interface declares"),
        );

        foreach ($method->getParameters() as $index => $parameter) {
            expect((string) $mine->getParameters()[$index]->getType())->toBe(
                (string) $parameter->getType(),
                upstreamContractGuide("PrismSpanProcessor::{$name}() parameter #{$index} is typed differently from the interface"),
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| The ranges that decide when the above can move underneath us
|--------------------------------------------------------------------------
*/

it('pins both engines to a caret range', function (string $package) {
    /** @var array{require: array<string, string>} $composer */
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    $constraint = $composer['require'][$package] ?? null;

    // Every assertion in this file is a claim about ONE major version of an
    // engine. A range that admits the next one — `*`, or a `>=` with no ceiling
    // — would let a breaking release install itself, and the pins above would
    // then be describing a package that is no longer there.
    expect(is_string($constraint) && str_starts_with($constraint, '^'))->toBeTrue(
        upstreamContractGuide("{$package} is not pinned to a caret range in packages/prism/composer.json"),
    );
})->with([
    'laravel/nightwatch',
    'keepsuit/laravel-opentelemetry',
]);

/**
 * Every record field {@see RecordTranslator} reads, by record type.
 *
 * This is the list whose drift is silent. The translator reads each of these
 * with a `?? null` — which is right, because a record is a plain array and a
 * missing field must never be fatal — so a renamed field upstream produces an
 * event that still ships, a column that is still written, and a value that is
 * an empty string or a zero. Nothing anywhere reports it. The Requests screen
 * simply starts showing blank routes.
 *
 * The seven execution-stage durations and the per-request counters are in the
 * `request` list even though the translator does not restate them: they ride
 * the passthrough into columns named exactly like them, and the server drops a
 * payload key that matches no column **silently**
 * (`input_format_skip_unknown_fields`).
 *
 * @return array<string, list<string>>
 */
function upstreamContractFields(): array
{
    return [
        'request' => [
            'method', 'url', 'route_path', 'status_code', 'duration', 'peak_memory_usage',
            'ip', 'headers',
            // US-007's columns, straight off the passthrough.
            'bootstrap', 'before_middleware', 'action', 'render', 'after_middleware',
            'sending', 'terminating',
            'queries', 'logs', 'cache_events', 'jobs_queued', 'mail', 'notifications',
            'outgoing_requests', 'lazy_loads', 'hydrated_models',
        ],
        'exception' => ['class', 'message', 'file', 'line', 'trace', 'handled'],
        'log' => ['level', 'message', 'context'],
        'query' => ['sql', 'connection', 'duration'],
        'job-attempt' => [
            'name', 'queue', 'connection', 'job_id', 'status', 'attempt', 'duration',
            'exception_preview',
        ],
        'queued-job' => ['name', 'queue', 'connection', 'job_id'],
        'scheduled-task' => ['name', 'cron', 'status', 'duration', 'peak_memory_usage', 'server'],
        // `class`, `name`, `command` and `exit_code` already read correctly and
        // ride the passthrough into the `commands` table's own columns.
        'command' => ['class', 'name', 'command', 'exit_code', 'duration', 'peak_memory_usage'],
        'mail' => ['duration', 'failed'],
        'notification' => ['duration', 'failed'],
        'cache-event' => ['type', 'store', 'key', 'duration'],
        'outgoing-request' => ['method', 'host', 'url', 'duration', 'status_code'],
    ];
}
