<?php

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Routing\Route;
use Laravel\Nightwatch\Clock;
use Laravel\Nightwatch\Compatibility;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Location;
use Laravel\Nightwatch\SensorManager;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\Support\Uuid;
use Laravel\Nightwatch\UserProvider;
use Misakstvanu\Prism\Metrics\QueueMetrics;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\Tests\NightwatchHostTestCase;
use Misakstvanu\Prism\Tests\OpenTelemetryHostTestCase;
use Misakstvanu\Prism\Tests\TestCase;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

pest()->extend(TestCase::class)->in('Feature');

// `tests/Host` is the same package inside a host that ALSO has Nightwatch's own
// provider, registered in the order a real install produces (see
// {@see NightwatchHostTestCase}). It is a directory of its own because Pest
// assigns exactly one test case per file and refuses a second — and because the
// two premises are genuinely different: everything under `tests/Feature` proves
// the package stands up alone.
pest()->extend(NightwatchHostTestCase::class)->in('Host');

// `tests/Otel` is the same host with the span lane's own provider added, in the
// order `installed.json` forces: keepsuit, then Nightwatch, then Prism. It is a
// third directory rather than a third premise bolted onto the second because
// everything under `tests/Host` asserts the capture engine's behaviour under a
// process that has no OpenTelemetry SDK in it at all.
pest()->extend(OpenTelemetryHostTestCase::class)->in('Otel');

/**
 * Build a real `Laravel\Nightwatch\Core` the way its own service provider does,
 * standing in for the one a host application ends up with.
 *
 * A real object rather than a double on purpose: what these suites assert about
 * is the shape of an upstream class Prism reaches into (`$ingest`, `$config`,
 * `$executionState->server`, `pause()`), so a double would keep passing after
 * upstream changed any of it — which is exactly the drift there is to catch.
 *
 * A caller that needs a command-scoped execution — a job attempt or a scheduled
 * task, whose sensors take a `CommandState` and nothing else — passes one in
 * through `$executionState`, which then supersedes `$server`. One construction
 * site rather than two: the whole point of building a real `Core` here is that
 * a second, hand-rolled one would drift from it exactly as a double would.
 *
 * @param  array<string, mixed>  $config  Merged over Nightwatch's own defaults.
 * @return Core<RequestState|CommandState>
 */
function makeNightwatchCore(
    bool $enabled = true,
    string $server = 'nightwatch-default',
    ?Ingest $ingest = null,
    array $config = [],
    RequestState|CommandState|null $executionState = null,
): Core {
    $clock = new Clock;

    $state = $executionState ?? new RequestState(
        timestamp: microtime(true),
        trace: 'trace-id',
        id: 'execution-id',
        deploy: '',
        server: $server,
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: new UserProvider(
            withAuth: fn (callable $callback) => $callback(app('auth')),
            userDetailsResolverResolver: fn () => null,
            reportResolver: fn () => static fn (Authenticatable $user) => null,
        ),
    );

    return new Core(
        ingest: $ingest ?? new NullNightwatchIngest,
        sensor: new SensorManager(
            executionState: $state,
            clock: $clock,
            location: new Location(basePath: base_path(), publicPath: public_path()),
            captureExceptionSourceCode: false,
            captureRequestPayload: false,
            redactPayloadFields: [],
            redactHeaders: [],
            container: app(),
        ),
        executionState: $state,
        clock: $clock,
        uuid: new Uuid(static fn () => 'uuid'),
        config: array_replace_recursive([
            'enabled' => $enabled,
            'sampling' => [
                'requests' => 1.0,
                'commands' => 1.0,
                'exceptions' => 1.0,
                'scheduled_tasks' => 1.0,
            ],
            'filtering' => [
                'ignore_cache_events' => false,
                'ignore_mail' => false,
                'ignore_notifications' => false,
                'ignore_outgoing_requests' => false,
                'ignore_queries' => false,
            ],
        ], $config),
    );
}

/**
 * The ingest a `Core` is handed when a suite only cares about what happens
 * around it. It records nothing and reaches nothing.
 */
final class NullNightwatchIngest implements Ingest
{
    public function write(array $record): void {}

    public function writeNow(array $record): void {}

    public function ping(): void {}

    public function shouldDigest(bool $bool = true): void {}

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void {}

    public function digest(): void {}

    public function flush(): void {}
}

/**
 * An ingest that records what it was handed, so a caller's *choice* of method
 * can be asserted rather than only its eventual effect.
 *
 * The distinction is the whole of US-013: {@see SystemMetrics}
 * and {@see QueueMetrics} must reach the ingest through
 * `writeNow()` and not `write()`, because a record written into the shared
 * buffer is discarded by `flush()` whenever the execution around it was sampled
 * out — and a replica's health has nothing to do with whether one request was
 * interesting. The two lists here are kept apart for exactly that reason: a
 * collector that regressed to `write()` would still ship on a kept execution and
 * would pass every assertion made on the transport alone.
 */
final class RecordingNightwatchIngest implements Ingest
{
    /** @var list<array<string, mixed>> */
    public array $written = [];

    /** @var list<array<string, mixed>> */
    public array $writtenNow = [];

    public int $digested = 0;

    public int $flushed = 0;

    public function write(array $record): void
    {
        $this->written[] = $record;
    }

    public function writeNow(array $record): void
    {
        $this->writtenNow[] = $record;
    }

    public function ping(): void {}

    public function shouldDigest(bool $bool = true): void {}

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void {}

    public function digest(): void
    {
        $this->digested++;
    }

    public function flush(): void
    {
        $this->flushed++;
    }
}

/**
 * One **real** Nightwatch record of every type its sensors emit, keyed by the
 * record's own `t`.
 *
 * Built by driving the upstream sensors — through the same `SensorManager` a
 * host application ends up with, over real framework events — rather than by
 * hand. The field lists in those sensors are long, several fields are deferred
 * behind a `LazyValue`, and two of them are not the shape the docs suggest
 * (an exception record is `v: 3`, and every type name is hyphenated). A
 * hand-written fixture agrees with whatever the person writing it believed,
 * which is precisely the drift a translator test exists to catch.
 *
 * Sensors that need a command-scoped execution (`command`, `job-attempt`,
 * `scheduled-task`) get their own `CommandState`; the rest run against the
 * request state.
 *
 * @return array<string, array<string, mixed>>
 */
function nightwatchRecords(): array
{
    // Nightwatch feature-detects the host framework once, at boot, and several
    // sensors read those flags: without them a cache event has no store name and
    // no duration, and a queue name is never normalised. The provider is not
    // registered in this suite, so the fixtures would otherwise describe a
    // Laravel 10 host that no longer exists.
    Compatibility::boot(app());

    $clock = new Clock;
    $location = new Location(basePath: base_path(), publicPath: public_path());
    $user = new UserProvider(
        withAuth: fn (callable $callback) => $callback(app('auth')),
        userDetailsResolverResolver: fn () => null,
        reportResolver: fn () => static fn (Authenticatable $user) => null,
    );

    $requestState = new RequestState(
        timestamp: microtime(true),
        trace: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        id: 'execution-1234',
        deploy: 'deploy-7',
        server: 'web-01',
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: $user,
    );
    $requestState->queries = 3;
    $requestState->logs = 2;

    $commandState = new CommandState(
        timestamp: microtime(true),
        trace: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        id: 'execution-5678',
        deploy: 'deploy-7',
        server: 'worker-01',
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: $user,
        artisan: new ConsoleApplication(app(), app('events'), app()->version()),
        name: 'list',
    );
    $commandState->attempts = 2;

    $manager = nightwatchSensorManager($requestState, $clock, $location);
    $commandManager = nightwatchSensorManager($commandState, $clock, $location);

    $records = [
        $manager->request(...nightwatchHttpExchange()),
        $manager->exception(new RuntimeException('Order 5 could not be priced.'), true),
        $manager->log(new LogRecord(
            datetime: new DateTimeImmutable('2026-08-20 09:15:30.123456', new DateTimeZone('UTC')),
            channel: 'testing',
            level: Level::Warning,
            message: 'Disk almost full',
            context: ['free' => '2%'],
        )),
        $manager->query(new QueryExecuted(
            sql: 'select * from "orders" where "id" = ?',
            bindings: [5],
            time: 12.5,
            connection: app('db')->connection(),
        ), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 5)),
        $manager->outgoingRequest(
            microtime(true) - 0.25,
            microtime(true),
            new Psr7Request('GET', 'https://api.example.com/v1/things?page=2'),
            new Psr7Response(201, [], 'created'),
        ),
        nightwatchCacheRecord($manager),
        nightwatchMailRecord($manager),
        nightwatchNotificationRecord($manager),
        nightwatchQueuedJobRecord($manager),
        $commandManager->command(new ArrayInput(['command' => 'list']), 0),
        $commandManager->jobAttempt(new JobProcessed('redis', nightwatchQueueJobStub())),
        $commandManager->scheduledTask(new ScheduledTaskFinished(
            app(Schedule::class)->exec('php -v')->everyFiveMinutes(),
            1.234,
        )),
    ];

    $resolved = [];

    foreach ($records as $record) {
        $record = nightwatchResolveRecord($record);

        if ($record !== null) {
            $resolved[$record['t']] = $record;
        }
    }

    return $resolved;
}

/**
 * A `SensorManager` wired the way Nightwatch's own provider wires one, for
 * whichever execution state the caller needs.
 *
 * @param  RequestState|CommandState  $state
 */
function nightwatchSensorManager(object $state, Clock $clock, Location $location, bool $captureExceptionSourceCode = false): SensorManager
{
    return new SensorManager(
        executionState: $state, // @phpstan-ignore-line
        clock: $clock,
        location: $location,
        captureExceptionSourceCode: $captureExceptionSourceCode,
        captureRequestPayload: false,
        redactPayloadFields: [],
        redactHeaders: [],
        container: app(),
    );
}

/**
 * One real exception record, from the real sensor.
 *
 * Separate from {@see nightwatchRecords()} because the two want opposite answers
 * to one constructor argument: every other fixture is built with source-code
 * capture OFF (it would put a slab of this file's own text in each of them),
 * while the frames US-009 maps are precisely where the captured source has to
 * land. The default here is upstream's own default — on.
 *
 * `$basePath` is worth passing whenever the *paths* matter. This suite's own
 * files sit outside the Testbench skeleton, so with the default upstream treats
 * them as foreign code: it leaves their paths absolute and captures no source
 * for them. Naming the package root instead is what makes a record thrown from a
 * test read the way one thrown from a host's `app/` does.
 *
 * @return array<string, mixed>
 */
function nightwatchExceptionRecord(Throwable $e, ?bool $handled = false, bool $captureSourceCode = true, ?string $basePath = null): array
{
    $state = new RequestState(
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

    $manager = nightwatchSensorManager(
        $state,
        new Clock,
        new Location(basePath: $basePath ?? base_path(), publicPath: public_path()),
        captureExceptionSourceCode: $captureSourceCode,
    );

    /** @var array<string, mixed> */
    return nightwatchResolveRecord($manager->exception($e, $handled));
}

/**
 * A routed request and its response — the pair the request sensor reads. The
 * route is real so `route_path`, `route_methods` and `route_domain` come out of
 * the same machinery a host's would.
 *
 * @return array{0: Request, 1: Response}
 */
function nightwatchHttpExchange(): array
{
    $request = Request::create(
        'https://app.test/orders/5?include=lines',
        'GET',
        server: ['HTTP_USER_AGENT' => 'PrismTest/1.0'],
    );

    $route = (new Route(['GET', 'HEAD'], 'orders/{order}', ['as' => 'orders.show', 'uses' => fn () => 'ok']))
        ->bind($request);

    $request->setRouteResolver(static fn () => $route);

    return [$request, new Response('ok', 200)];
}

/**
 * A cache-hit record. The sensor times the operation, so the retrieval has to
 * be opened before it is answered — exactly as the framework fires them.
 *
 * @return array{0: object, 1: callable(): array<string, mixed>}|null
 */
function nightwatchCacheRecord(SensorManager $manager): ?array
{
    $manager->cacheEvent(new RetrievingKey('redis', 'orders:5'));

    return $manager->cacheEvent(new CacheHit('redis', 'orders:5', ['id' => 5]));
}

/**
 * A sent-mail record. Same two-phase shape as the cache sensor: the send is
 * opened, then completed.
 *
 * @return array{0: object, 1: callable(): array<string, mixed>}|null
 */
function nightwatchMailRecord(SensorManager $manager): ?array
{
    $email = (new Email)
        ->from('orders@app.test')
        ->to('customer@example.com')
        ->cc('audit@app.test')
        ->subject('Your order shipped')
        ->text('It is on its way.');

    $data = ['mailer' => 'smtp', '__laravel_mailable' => 'App\\Mail\\OrderShipped'];

    $manager->mail(new MessageSending($email, $data));

    $sent = new SentMessage(new SymfonySentMessage($email, new Envelope(
        new Address('orders@app.test'),
        [new Address('customer@example.com')],
    )));

    return $manager->mail(new MessageSent($sent, $data));
}

/**
 * A sent-notification record.
 *
 * @return array{0: object, 1: callable(): array<string, mixed>}|null
 */
function nightwatchNotificationRecord(SensorManager $manager): ?array
{
    $notifiable = new stdClass;
    $notification = new PrismTestNotification;

    $manager->notification(new NotificationSending($notifiable, $notification, 'mail'));

    return $manager->notification(new NotificationSent($notifiable, $notification, 'mail'));
}

/**
 * A queued-job record. The sensor measures how long the dispatch took, so the
 * queueing event has to precede the queued one.
 *
 * @return array{0: object, 1: callable(): array<string, mixed>}|null
 */
function nightwatchQueuedJobRecord(SensorManager $manager): ?array
{
    $payload = json_encode([
        'uuid' => 'e6d1f0a2-1c4b-4f52-9f3a-77c9a1b2c3d4',
        'displayName' => 'App\\Jobs\\PriceOrder',
    ]);

    $manager->queuedJob(new JobQueueing('redis', 'default', 'App\\Jobs\\PriceOrder', $payload, null));

    return $manager->queuedJob(new JobQueued('redis', 'default', 1, 'App\\Jobs\\PriceOrder', $payload, null));
}

/**
 * A worked queue job, standing in for the driver-specific job object the queue
 * worker hands to `JobProcessed`. Only the handful of accessors the job-attempt
 * sensor reads are implemented, which is what that event's untyped `$job`
 * property allows.
 */
function nightwatchQueueJobStub(): object
{
    return new class
    {
        public function resolveName(): string
        {
            return 'App\\Jobs\\PriceOrder';
        }

        /** @return array<string, mixed> */
        public function payload(): array
        {
            return ['uuid' => 'e6d1f0a2-1c4b-4f52-9f3a-77c9a1b2c3d4'];
        }

        public function uuid(): string
        {
            return 'e6d1f0a2-1c4b-4f52-9f3a-77c9a1b2c3d4';
        }

        public function getConnectionName(): string
        {
            return 'redis';
        }

        public function getQueue(): string
        {
            return 'default';
        }

        public function isReleased(): bool
        {
            return false;
        }

        public function hasFailed(): bool
        {
            return false;
        }
    };
}

/**
 * The record array out of whatever a sensor returned. Half of them answer the
 * array directly; the other half answer `[$record, $resolver]`, where the
 * resolver is what actually builds it — and, as US-018 depends on, what bumps
 * the execution's own counter for that signal.
 *
 * @param  array<mixed>|null  $result
 * @return array<string, mixed>|null
 */
function nightwatchResolveRecord(?array $result): ?array
{
    if ($result === null) {
        return null;
    }

    if (isset($result[1]) && is_callable($result[1])) {
        /** @var array<string, mixed> */
        return ($result[1])();
    }

    /** @var array<string, mixed> */
    return $result;
}

/** A notification with nothing in it — the sensor records only its class and channel. */
final class PrismTestNotification extends Notification
{
    //
}
