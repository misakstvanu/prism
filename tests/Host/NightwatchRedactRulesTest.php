<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Routing\Route;
use Laravel\Nightwatch\Clock;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Location;
use Laravel\Nightwatch\SensorManager;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\Support\Uuid;
use Laravel\Nightwatch\UserProvider;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Transport\Transport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * `prism.scrub` driven through the REAL capture engine (US-011).
 *
 * Every assertion here reads the **transmitted batch** rather than the record
 * object the callback was handed. That is the whole claim: a scrubbed value is
 * never transmitted or stored, and the only place to check "never transmitted"
 * is what the transport was given. A test that inspected the record would pass
 * for a redaction applied to a copy, applied too late, or applied to a field
 * the translator does not carry — three ways to redact nothing at all.
 *
 * The file lives under `tests/Host` so its case registers Nightwatch's provider
 * ahead of Prism's, the way a real install orders them. That ordering is load
 * bearing twice over: it is why the two config keys upstream's own request
 * sensor reads have to be pushed onto the built `SensorManager` as well as
 * written to config, and it is why the redact callbacks — which depend on no
 * ordering at all — are the half that cannot silently do nothing.
 */
beforeEach(function () {
    $this->transport = new RedactRecordingTransport;

    app()->instance(Transport::class, $this->transport);
});

// -- AC1: the scrub list reaches the keys the engine's own sensor reads -----

it("pushes the scrub list into the two keys the engine's own sensor reads", function () {
    bootPrismWithScrub(['password', 'x-tenant-secret']);

    // Upstream's own defaults survive underneath — a host relying on `_token`
    // being blanked did not ask to stop by installing Prism.
    expect(config('nightwatch.redact_payload_fields'))
        ->toContain('_token', 'password', 'x-tenant-secret')
        ->and(config('nightwatch.redact_headers'))
        ->toContain('Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN', 'x-tenant-secret');
});

it('pushes them onto the sensor nightwatch built before this provider registered', function () {
    // The config write alone is read by nobody in a real install: the sensor
    // takes these three as constructor arguments during Nightwatch's own
    // `register()`, which happens first, and never looks at config again.
    bootPrismWithScrub(['password', 'x-tenant-secret']);

    expect(sensorProperty('redactPayloadFields'))->toContain('x-tenant-secret')
        ->and(sensorProperty('redactHeaders'))->toContain('x-tenant-secret');
});

// -- AC3: request bodies are off by default --------------------------------

it('captures no request body by default', function () {
    bootPrismWithScrub(['password']);

    expect(config('nightwatch.capture_request_payload'))->toBeFalse()
        ->and(sensorProperty('captureRequestPayload'))->toBeFalse();

    captureRequest(payload: ['email' => 'a@b.test', 'password' => 'hunter2-field'], status: 500);

    // A body is recorded only on a 500 upstream, and this IS one — so what is
    // missing here is the body itself, not the occasion for it.
    expect(shippedEvent('request')['payload']['payload'] ?? '')
        ->toContain('NOT_ENABLED');
});

it('captures a request body when the host asks for one', function () {
    bootPrismWithScrub(['password'], ['request.capture_payload' => true]);

    expect(sensorProperty('captureRequestPayload'))->toBeTrue();

    captureRequest(payload: ['email' => 'a@b.test', 'password' => 'hunter2-field'], status: 500);

    $body = shippedEvent('request')['payload']['payload'] ?? '';

    // The opt-in works, the benign field survives it, and the secret does not.
    expect($body)->toContain('a@b.test')
        ->and($body)->not->toContain('hunter2-field');
});

// -- AC4: a password field and an Authorization header, on four signals -----

it('redacts a sensitive field, header and query parameter from a request', function () {
    bootPrismWithScrub(['password', 'token'], ['request.capture_payload' => true]);

    captureRequest(
        url: 'https://app.test/orders?token=hunter2-url&page=2',
        // Capitalised on purpose: upstream compares payload field names with a
        // case-sensitive `in_array`, so this one is redacted by Prism's callback
        // or not at all.
        payload: ['email' => 'a@b.test', 'Password' => 'hunter2-field'],
        headers: ['HTTP_AUTHORIZATION' => 'Bearer hunter2-header', 'HTTP_USER_AGENT' => 'PrismTest/1.0'],
        status: 500,
    );

    $shipped = shippedJson('request');

    expect($shipped)->not->toContain('hunter2-url')
        ->and($shipped)->not->toContain('hunter2-field')
        ->and($shipped)->not->toContain('hunter2-header')
        // Non-vacuous: everything benign about the same request still arrives,
        // so the three absences above are redaction rather than a record that
        // was never built.
        ->and($shipped)->toContain('page=2')
        ->and($shipped)->toContain('a@b.test')
        ->and($shipped)->toContain('PrismTest/1.0');
});

it('redacts a value written into a raw sql statement', function () {
    bootPrismWithScrub(['password']);

    captureQuery('update "users" set "password" = \'hunter2-sql\' where "id" = 1');

    $sql = shippedEvent('query')['payload']['sql'] ?? '';

    expect($sql)->not->toContain('hunter2-sql')
        ->and($sql)->toContain(Scrubber::REDACTED)
        // The statement keeps its shape, which is the whole point of redacting
        // the value rather than dropping the field.
        ->and($sql)->toContain('update "users" set');
});

it('leaves a bound placeholder alone', function () {
    // `where password = ?` carries no secret at all, and replacing the `?`
    // would take the statement's shape away from the query screen for nothing.
    bootPrismWithScrub(['password']);

    captureQuery('select * from "users" where "password" = ?');

    expect(shippedEvent('query')['payload']['sql'] ?? '')->toContain('= ?');
});

it('never ships a secret dispatched inside a job', function () {
    // Nightwatch's job records carry the job's name, id, queue, connection and
    // outcome — and no payload — so this dimension is covered by absence rather
    // than by a callback. Asserted rather than assumed: if a later story starts
    // carrying the payload, this is what says so.
    bootPrismForCommandExecution(['password']);

    captureJob(['uuid' => 'e6d1f0a2', 'displayName' => 'App\Jobs\PriceOrder', 'data' => ['password' => 'hunter2-job']]);

    expect(shippedJson('job'))->not->toContain('hunter2-job')
        // Non-vacuous: the job really was captured, both halves of it.
        ->and(shippedTypesFor('job'))->toHaveCount(2);
});

it('redacts a credential from an outgoing call', function () {
    bootPrismWithScrub(['api_key']);

    captureOutgoingCall('https://api.example.com/v1/things?api_key=hunter2-outgoing&page=2');

    $shipped = shippedJson('span');

    expect($shipped)->not->toContain('hunter2-outgoing')
        ->and($shipped)->toContain('page=2');
});

// -- The remaining record types, from the same one list --------------------

it('redacts the value half of a cache key and leaves a bare name alone', function () {
    bootPrismWithScrub(['token', 'api_key']);

    captureCacheRead('token:hunter2-cache');
    captureCacheRead('api_key');

    $shipped = shippedJson('span');

    expect($shipped)->not->toContain('hunter2-cache')
        // The name of a cache entry is not a secret, and blanking it would take
        // the key off the screen for no gain.
        ->and($shipped)->toContain('api_key');
});

it('redacts an option value from an artisan command line', function () {
    bootPrismForCommandExecution(['password']);

    captureCommand(['command' => 'list', '--password' => 'hunter2-cli']);

    $shipped = shippedJson('command');

    expect($shipped)->not->toContain('hunter2-cli')
        ->and($shipped)->toContain('--password');
});

it('redacts a value quoted in an exception message', function () {
    bootPrismWithScrub(['password']);

    app(Core::class)->report(
        new RuntimeException("Access denied for user 'root' (password: hunter2-exception)"),
        handled: true,
    );

    $shipped = shippedJson('exception');

    expect($shipped)->not->toContain('hunter2-exception')
        // The rest of the message survives, so the fault is still diagnosable.
        ->and($shipped)->toContain('Access denied for user');
});

it('redacts a value from a mail subject', function () {
    bootPrismWithScrub(['token']);

    captureMail('Your sign-in token=hunter2-mail');

    expect(shippedJson('mail'))->not->toContain('hunter2-mail');
});

it('leaves everything alone when the scrub list is empty', function () {
    // The control for the whole file: with nothing configured, nothing is
    // rewritten — so every absence above is the list being consulted rather
    // than a redactor that blanks indiscriminately.
    bootPrismWithScrub([]);

    captureQuery('update "users" set "password" = \'hunter2-sql\' where "id" = 1');

    expect(shippedEvent('query')['payload']['sql'] ?? '')->toContain('hunter2-sql');
});

/**
 * Re-run Prism's registration and boot with a given `prism.scrub` list, so the
 * derived config, the sensor reconciliation and the redact callbacks under test
 * are all the ones this test configured.
 *
 * `register()` is re-run as well as `boot()`, unlike the reject-rules suite:
 * the three keys upstream's request sensor reads are derived in
 * `configureNightwatch()`, which lives in `register()`, and a test that only
 * re-booted would assert against whatever the Testbench app happened to
 * register with.
 *
 * @param  list<string>  $scrub
 * @param  array<string, mixed>  $prism  Further `prism.*` keys, dotted.
 */
function bootPrismWithScrub(array $scrub, array $prism = []): void
{
    config(['prism.scrub' => $scrub]);

    foreach ($prism as $key => $value) {
        config(['prism.'.$key => $value]);
    }

    // The app's own boot already registered a set of callbacks over the package
    // default scrub list, and `redact*()` APPENDS rather than replaces — so
    // without this every test would run its own rules plus the defaults, and
    // "with an empty list nothing is rewritten" would be unprovable. Not a
    // production concern (a provider boots once per process), but it is exactly
    // the shape that makes a control assertion pass for the wrong reason.
    resetNightwatchRedaction();

    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    $provider = new PrismServiceProvider(app());
    $provider->register();
    $provider->boot();

    // A boot that reaches `registerCapture()` re-binds `Transport` as a
    // singleton of its own, so the recorder goes back afterwards or every
    // assertion below reads an empty list for the wrong reason.
    app()->instance(Transport::class, test()->transport);

    app(EventBuffer::class)->clear();
    test()->transport->sent = [];
}

/** One private constructor argument off the `SensorManager` the host ended up with. */
function sensorProperty(string $property): mixed
{
    return (new ReflectionProperty(SensorManager::class, $property))
        ->getValue(app(Core::class)->sensor);
}

/** Drop every redact callback currently installed on the host's `Core`. */
function resetNightwatchRedaction(): void
{
    $core = app(Core::class);

    foreach ([
        'redactExceptionCallbacks',
        'redactCacheEventCallbacks',
        'redactCommandCallbacks',
        'redactMailCallbacks',
        'redactOutgoingRequestCallbacks',
        'redactQueryCallbacks',
        'redactRequestCallbacks',
    ] as $property) {
        (new ReflectionProperty(Core::class, $property))->setValue($core, []);
    }
}

/**
 * Put a command-scoped `Core` in the container and boot Prism over it.
 *
 * A command and a worker's run of a job are executions of a different kind, and
 * upstream models that with a different state object — `CommandState`, not
 * `RequestState`. The host case forces the request shape (`NIGHTWATCH_FORCE_REQUEST`,
 * without which a lifecycle test is vacuous), and the state is fixed when the
 * `Core` is constructed, so the two command-side sensors cannot be driven on
 * it at all. Swapping the binding before Prism boots means the provider still
 * installs its own callbacks and its own ingest on the object under test.
 *
 * @param  list<string>  $scrub
 */
function bootPrismForCommandExecution(array $scrub): void
{
    $state = new CommandState(
        timestamp: microtime(true),
        trace: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        id: 'execution-5678',
        deploy: '',
        server: 'worker-1',
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: new UserProvider(
            withAuth: fn (callable $callback) => $callback(app('auth')),
            userDetailsResolverResolver: fn () => null,
            reportResolver: fn () => static fn (Authenticatable $user) => null,
        ),
        artisan: new ConsoleApplication(app(), app('events'), app()->version()),
        name: 'list',
    );
    $state->attempts = 1;

    $clock = new Clock;

    app()->instance(Core::class, new Core(
        ingest: new NullNightwatchIngest,
        sensor: nightwatchSensorManager($state, $clock, new Location(basePath: base_path(), publicPath: public_path())),
        executionState: $state,
        clock: $clock,
        uuid: new Uuid(static fn () => 'uuid'),
        config: [
            'enabled' => true,
            'sampling' => ['requests' => 1.0, 'commands' => 1.0, 'exceptions' => 1.0, 'scheduled_tasks' => 1.0],
            'filtering' => [
                'ignore_cache_events' => false,
                'ignore_mail' => false,
                'ignore_notifications' => false,
                'ignore_outgoing_requests' => false,
                'ignore_queries' => false,
            ],
        ],
    ));

    bootPrismWithScrub($scrub);
}

/**
 * Every Prism event that actually left over the transport.
 *
 * The observation point for all of this: redaction is a claim about what was
 * transmitted, and the record object the callback mutated is not evidence of it.
 *
 * @return list<array<string, mixed>>
 */
function shippedEvents(): array
{
    // The buffer ships on terminate — the ordinary path, after the response has
    // gone out.
    app()->terminate();

    $events = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            $events[] = $event;
        }
    }

    return $events;
}

/**
 * The events of one type that were shipped.
 *
 * @return list<array<string, mixed>>
 */
function shippedTypesFor(string $type): array
{
    return array_values(array_filter(
        shippedEvents(),
        static fn (array $event): bool => ($event['type'] ?? null) === $type,
    ));
}

/**
 * The first shipped event of a type, or an empty shape so a failing assertion
 * reads as "the field was not redacted" rather than as a null dereference.
 *
 * @return array<string, mixed>
 */
function shippedEvent(string $type): array
{
    return shippedTypesFor($type)[0] ?? ['payload' => []];
}

/**
 * Every shipped event of a type, as the JSON that went over the wire.
 *
 * Slashes are left unescaped so a needle written the way a URL or a user agent
 * is written (`PrismTest/1.0`) matches — `json_encode`'s default `\/` would
 * make every such assertion pass or fail for a reason that has nothing to do
 * with redaction.
 */
function shippedJson(string $type): string
{
    return (string) json_encode(shippedTypesFor($type), JSON_UNESCAPED_SLASHES);
}

/** Drive a real request record through the engine, headers, body, URL and all. */
function captureRequest(
    string $url = 'https://app.test/orders',
    array $payload = [],
    array $headers = [],
    int $status = 200,
): void {
    $request = Request::create($url, 'POST', $payload, server: $headers);

    $route = (new Route(['POST'], 'orders', ['as' => 'orders.store', 'uses' => fn () => 'ok']))
        ->bind($request);

    $request->setRouteResolver(static fn () => $route);

    app(Core::class)->request($request, new Response('boom', $status));
}

/** Drive a real query through the engine. */
function captureQuery(string $sql): void
{
    app(Core::class)->query(new QueryExecuted(
        sql: $sql,
        bindings: [],
        time: 3.5,
        connection: app('db')->connection(),
    ));
}

/** Drive a real outgoing HTTP call through the engine. */
function captureOutgoingCall(string $url): void
{
    app(Core::class)->outgoingRequest(
        microtime(true) - 0.05,
        microtime(true),
        new Psr7Request('GET', $url),
        new Psr7Response(200, [], 'ok'),
    );
}

/** Drive a real cache read through the engine; the sensor times it, so both halves fire. */
function captureCacheRead(string $key): void
{
    $core = app(Core::class);

    $core->cacheEvent(new RetrievingKey('redis', $key));
    $core->cacheEvent(new CacheHit('redis', $key, ['id' => 5]));
}

/** Drive both halves of a job's life through the engine — the dispatch and the attempt. */
function captureJob(array $payload): void
{
    $core = app(Core::class);
    $encoded = (string) json_encode($payload);
    $name = 'App\Jobs\PriceOrder';

    $core->queuedJob(new JobQueueing('redis', 'default', $name, $encoded, null));
    $core->queuedJob(new JobQueued('redis', 'default', 1, $name, $encoded, null));

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('uuid')->andReturn('e6d1f0a2');
    $job->shouldReceive('getConnectionName')->andReturn('redis');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('isReleased')->andReturnFalse();
    $job->shouldReceive('hasFailed')->andReturnFalse();

    $core->jobAttempt(new JobProcessed('redis', $job));
}

/** Drive a real command record through the engine. */
function captureCommand(array $input): void
{
    app(Core::class)->command(new ArrayInput($input), 0);
}

/** Drive a real sent-mail record through the engine; the sensor times it, so both halves fire. */
function captureMail(string $subject): void
{
    $core = app(Core::class);

    $email = (new Email)
        ->from('orders@app.test')
        ->to('customer@example.com')
        ->subject($subject)
        ->text('It is on its way.');

    $data = ['mailer' => 'smtp', '__laravel_mailable' => 'App\Mail\OrderShipped'];

    $core->mail(new MessageSending($email, $data));

    $core->mail(new MessageSent(new SentMessage(new SymfonySentMessage($email, new Envelope(
        new Address('orders@app.test'),
        [new Address('customer@example.com')],
    ))), $data));
}

/**
 * A transport that records envelopes instead of sending them. Named apart from
 * the equivalents elsewhere in the suite because Pest loads every test file
 * into one process, where a redeclared class is fatal.
 */
final class RedactRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
