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
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
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
use Misakstvanu\Prism\PrismServiceProvider;
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
 * When the default report fixture says it was sent, and — since US-008 — the
 * instant a suite that cares about timestamps freezes its clock at.
 *
 * A constant rather than a literal in two places because the two are one fact:
 * the server corrects every event in a report by the distance between `sent_at`
 * and its own clock, so a fixture whose `sent_at` and whose test clock disagree
 * describes a browser whose clock is out — which is a thing to assert
 * deliberately, never to inherit.
 */
const REPORT_SENT_AT = '2026-08-26T10:00:00.000Z';

/**
 * A well-formed browser report body (US-006), with whatever a caller wants to
 * be wrong about it merged over the top.
 *
 * It lives here rather than in one of the three files that post one because all
 * three premises need it — the route's own suite, the parser's, and the host
 * suite that proves the report's execution is never captured — and Pest loads
 * every test file into one process, where a second copy of a helper is a fatal
 * redeclare rather than a duplication anyone would notice.
 *
 * @param  array<string, mixed>  $overrides
 */
function browserReportBody(array $overrides = []): string
{
    return (string) json_encode([
        'v' => 1,
        'sdk' => ['name' => '@misakstvanu/prism-browser', 'version' => '1.0.0'],
        'sent_at' => REPORT_SENT_AT,
        'session' => ['id' => '5c1f2b3a4d5e6f708192a3b4c5d6e7f8', 'release' => '2026.08.3'],
        'events' => [],
        ...$overrides,
    ], JSON_THROW_ON_ERROR);
}

/**
 * POST a report the way the SDK does: a raw body, with the content type the
 * transport that sent it decided.
 *
 * Never `$this->post($uri, $data)` — that sends form parameters and leaves the
 * body empty, which the endpoint reads (correctly) as a body it cannot decode.
 * The default content type is `fetch`'s; a beacon's `text/plain` is passed in by
 * the tests that are about the beacon.
 *
 * @param  array<string, mixed>|string|null  $body  An override bag, or a raw body to send verbatim.
 * @param  array<string, mixed>  $server
 */
function postBrowserReport(
    array|string|null $body = null,
    string $contentType = 'application/json',
    array $server = [],
    string $uri = '/_prism/browser',
): TestResponse {
    return test()->call(
        'POST',
        $uri,
        server: ['CONTENT_TYPE' => $contentType, ...$server],
        content: is_string($body) ? $body : browserReportBody($body ?? []),
    );
}

/**
 * One posted event, with whatever a test wants to be different about it merged
 * over a well-formed default (US-007).
 *
 * Beside {@see browserReportBody()} for its reason: the report's own suite, the
 * forwarder's and the host suite that watches what leaves over the transport all
 * compose one, and Pest loads every test file into one process where a second
 * copy is a fatal redeclare.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function browserEventBody(string $type, array $overrides = []): array
{
    return [
        'type' => $type,
        'timestamp' => '2026-08-26T09:59:59.000Z',
        'trace_id' => '0123456789abcdef0123456789abcdef',
        'payload' => ['message' => 'something happened'],
        ...$overrides,
    ];
}

/**
 * Give the request under test something to lose, and report back what the
 * sampler decided about it.
 *
 * The browser endpoint answers 204 and does very little, so an execution that
 * shipped its buffer would ship an empty one and every "nothing was captured"
 * assertion would pass for the wrong reason. A listener on `RouteMatched` —
 * which fires after the whole global middleware stack, i.e. after both the
 * capture engine's sampler and Prism's refusal have had their say — is where a
 * query, a cache read, an outgoing call and a log line are driven through the
 * real `Core`, and where the sampling verdict is legible.
 *
 * Shared by the two host suites that assert on what a report's own request
 * leaves behind: the one that proves the execution is refused (US-005) and the
 * one that proves the report itself still ships (US-007).
 */
function browserReportExecution(): object
{
    $execution = new stdClass;
    $execution->sampling = null;

    app('events')->listen(RouteMatched::class, function () use ($execution): void {
        /** @var Core<covariant RequestState> $core */
        $core = app(Core::class);

        $execution->sampling = $core->sampling();

        Log::info('the browser report looked at something');

        // The cache sensor times the operation, so both halves have to fire.
        $core->cacheEvent(new RetrievingKey('redis', 'orders:5'));
        $core->cacheEvent(new CacheHit('redis', 'orders:5', ['id' => 5]));

        $core->query(new QueryExecuted(
            sql: 'select * from "orders" where "id" = ?',
            bindings: [5],
            time: 12.5,
            connection: app('db')->connection(),
        ));

        $core->outgoingRequest(
            microtime(true) - 0.05,
            microtime(true),
            new Psr7Request('GET', 'https://api.example.com/v1/things'),
            new Psr7Response(200, [], 'ok'),
        );
    });

    return $execution;
}

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

/**
 * Re-run the provider's boot against a fresh route table, so "the route is
 * registered" is a claim about THIS configuration rather than about the boot
 * Testbench already performed with the package defaults.
 *
 * Here rather than in one of the two files that call it because both the
 * endpoint's own suite (US-005) and the CORS suite beside it (US-010) need it,
 * and Pest loads every test file into one process where a helper reached for
 * across files is an invisible dependency between two premises — and a second
 * copy of one is a fatal redeclare.
 *
 * @param  array<string, mixed>  $config
 */
function bootBrowserEndpoint(array $config = []): void
{
    config($config);

    app('router')->setRoutes(new RouteCollection);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    (new PrismServiceProvider(app()))->boot();

    // What the framework itself does from a `booted` callback: a route named
    // AFTER it was added is invisible to `getByName()` until the lookups are
    // rebuilt, and re-running boot() by hand happens long after `booted` fired.
    app('router')->getRoutes()->refreshNameLookups();
}

/**
 * A route of the browser endpoint's by name, or null when this boot registered
 * none.
 *
 * Defaults to the `POST` route; the preflight (US-010) is a route of its own,
 * because it answers a different verb inside a different middleware stack.
 */
function browserRoute(string $name = PrismServiceProvider::BROWSER_ROUTE): ?Route
{
    return app('router')->getRoutes()->getByName($name);
}

/**
 * The middleware a route actually runs inside — groups expanded and exclusions
 * applied, which is the only form in which "the web group minus CSRF" is a
 * checkable claim.
 *
 * @return list<string>
 */
function browserMiddleware(?Route $route): array
{
    if ($route === null) {
        return [];
    }

    return array_values(array_filter(
        app('router')->gatherRouteMiddleware($route),
        'is_string',
    ));
}
