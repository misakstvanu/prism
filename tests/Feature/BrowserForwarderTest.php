<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Misakstvanu\Prism\Browser\BrowserForwarder;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Transport\Transport;

/**
 * What the browser endpoint puts on the wire (US-007).
 *
 * The route and its refusals are `BrowserRouteTest`'s and `BrowserReportTest`'s;
 * everything here is about the one thing those two deliberately stop short of —
 * an accepted report becoming events in a batch of its own and leaving over the
 * transport every other Prism signal leaves over.
 *
 * Two properties carry the story and both are asserted against the recording
 * transport rather than against the forwarder's internals, because both are
 * claims about what a workspace receives: **the events are exactly what
 * survived the filter, unrewritten** (a browser event's trace is the page's,
 * its `request_id` belongs to nobody, and a `runtime` says which half of an
 * incident a row is), and **the batch is a private one** — the shared buffer is
 * untouched, which is what keeps the report alive through a forwarding request
 * the client has asked not to be sampled.
 *
 * The third property is US-008's, and it is the other way round: **four fields
 * are the backend's answer rather than the page's.** The signed-in user, the
 * client address and the user agent are read off the request that carried the
 * report — a report is an unauthenticated write, so what it says about who sent
 * it is a value anyone can set to anyone — and every event's timestamp is
 * corrected against the report's own `sent_at`, because a device clock that is
 * days out lands rows in a day partition retention drops on sight.
 *
 * The clock is frozen at the fixture's `sent_at`, so "the browser's clock is
 * right" is the default here and a skew is something a test opts into. Without
 * it every assertion in the file would be read against the wall clock, which is
 * hours from the fixture and would make the correction fire in tests that are
 * not about it.
 */
beforeEach(function () {
    // The `web` group encrypts cookies, so a route inside it needs a key and
    // the Testbench skeleton ships none.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('prism-key-32-byt', 2))]);

    // The named limiter counts in the cache; a per-test array store is what
    // stops one test's posts deciding another's verdict.
    config(['cache.default' => 'array']);

    // The instant `browserReportBody()` says the report was sent at, so the
    // default fixture describes a browser whose clock agrees with the server's
    // and nothing is corrected. Laravel's own tearDown puts the real clock back.
    Carbon::setTestNow(REPORT_SENT_AT);

    bootBrowserForwarding();
});

it('ships every accepted event as one Prism event', function () {
    postBrowserReport(['events' => [
        browserEventBody('exception', ['payload' => ['class' => 'TypeError', 'message' => 'x is not a function']]),
        browserEventBody('log', ['payload' => ['level' => 'error', 'message' => 'checkout failed']]),
        browserEventBody('page_view', ['payload' => ['url' => 'https://shop.test/cart', 'kind' => 'load']]),
    ]])->assertNoContent();

    expect(forwardedTypes())->toBe(['exception', 'log', 'page_view']);
});

it('stamps the browser runtime on the two signals whose table has that column', function () {
    postBrowserReport(['events' => [
        browserEventBody('exception'),
        browserEventBody('log'),
        browserEventBody('page_view'),
    ]])->assertNoContent();

    $events = forwardedByType();

    expect($events['exception']['payload']['runtime'])->toBe('browser')
        ->and($events['log']['payload']['runtime'])->toBe('browser')
        // `page_views` has no such column, and an unknown payload key is dropped
        // silently by the insert rather than reported anywhere.
        ->and($events['page_view']['payload'])->not->toHaveKey('runtime');
});

it('stamps the runtime whatever the page claimed it was', function () {
    // The endpoint is open to the internet: a row asserting it came from PHP
    // would land among the backend's own on the Errors screen.
    postBrowserReport(['events' => [
        browserEventBody('exception', ['payload' => ['runtime' => 'php']]),
    ]])->assertNoContent();

    expect(forwardedByType()['exception']['payload']['runtime'])->toBe('browser');
});

it('keeps the page trace id rather than rewriting it to the forwarding request', function () {
    // The whole reason this path does not go through PrismIngest. The trace was
    // minted in the browser and travelled to the backend as a `traceparent`; the
    // POST that carried the report is not part of that story.
    postBrowserReport(['events' => [
        browserEventBody('exception', ['trace_id' => 'aaaaaaaabbbbbbbbccccccccdddddddd']),
    ]])->assertNoContent();

    expect(forwardedEvents()[0]['trace_id'])->toBe('aaaaaaaabbbbbbbbccccccccdddddddd');
});

it('files a browser event under no backend execution and no server-side user', function () {
    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(forwardedEvents()[0]['request_id'])->toBe('')
        ->and(forwardedEvents()[0]['user_id'])->toBeNull();
});

it('carries the event timestamp the page wrote, in the pipeline wire format', function () {
    postBrowserReport(['events' => [
        browserEventBody('log', ['timestamp' => '2026-08-26T09:15:30.250Z']),
    ]])->assertNoContent();

    expect(forwardedEvents()[0]['timestamp'])->toBe('2026-08-26T09:15:30.250000+00:00');
});

it('ships what survived the filter and nothing else', function () {
    postBrowserReport(['events' => [
        browserEventBody('exception'),
        // Three refusals from US-006, each of which must cost only itself.
        browserEventBody('metric'),
        browserEventBody('log', ['timestamp' => 'the other day']),
        browserEventBody('page_view', ['payload' => ['not', 'an', 'object']]),
    ]])->assertNoContent();

    expect(forwardedTypes())->toBe(['exception']);
});

it('ships nothing at all for a report with no usable events', function () {
    postBrowserReport(['events' => [browserEventBody('metric')]])->assertNoContent();

    // An envelope of nothing is not a cheaper way to say nothing — it is a
    // request to the workspace per page that reported one filtered-out error.
    expect(forwardedEvents())->toBeEmpty();
});

it('ships through a batch of its own, never the shared buffer', function () {
    // The shared buffer is emptied by discardRejectedExecution() before the
    // terminating drain, because the forwarding request is one Prism asked not
    // to be sampled — so a report buffered there would be discarded every time.
    // That failure needs a `Core` to show up, which this premise has none of, so
    // the claim is made the other way round here: something is already in the
    // shared buffer, and the report's own envelope must not contain it.
    app(EventBuffer::class)->add('log', [
        'type' => 'log',
        'payload' => ['message' => 'a line the host logged before the report arrived'],
    ]);

    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(forwardedEnvelope()['events'])->toHaveCount(1)
        ->and(forwardedEnvelope()['events'][0]['type'])->toBe('exception');
});

it('rides the ordinary envelope, so the workspace knows which install reported', function () {
    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(forwardedEnvelope())
        ->toMatchArray(['v' => 1, 'app' => 'demo', 'env' => 'production', 'replica' => 'web-1']);
});

it('follows prism.batch.flush, so a large report is queued like any other batch', function () {
    Queue::fake();

    config(['prism.batch.queue_threshold' => 1]);

    postBrowserReport(['events' => [
        browserEventBody('exception'),
        browserEventBody('log'),
    ]])->assertNoContent();

    Queue::assertPushed(SendBatchJob::class);

    expect(forwardedEvents())->toBeEmpty();
});

it('buffers nothing and ships nothing when the install has no token', function () {
    // The route still answers — a 404 reads to the SDK as a routing mistake —
    // but there is no pipeline behind it, so the report is read and dropped.
    bootBrowserForwarding(['prism.token' => null]);

    expect(app()->bound(BrowserForwarder::class))->toBeFalse();

    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(forwardedEvents())->toBeEmpty()
        ->and(app(EventBuffer::class)->count())->toBe(0);
});

it('ships the identity the backend resolved rather than the one the page claimed', function () {
    // The endpoint is an unauthenticated write by design — an anonymous visitor
    // hitting a JavaScript error is the report most worth having — so a `user`
    // in the body is a value anyone can set to anyone. The request that carried
    // it went through the host's `web` group with its session, and that is the
    // identity a workspace is shown.
    actingAsReporter(7);

    postBrowserReport([
        'user' => ['id' => '99'],
        'events' => [browserEventBody('exception')],
    ])->assertNoContent();

    expect(forwardedEvents()[0]['user_id'])->toBe('7');
});

it('ships no user at all when the request carried no session', function () {
    postBrowserReport([
        'user' => ['id' => '99'],
        'events' => [browserEventBody('exception')],
    ])->assertNoContent();

    expect(forwardedEvents()[0]['user_id'])->toBeNull();
});

it('takes the page hint only where the host has said to trust it', function () {
    // The escape hatch for a token-authenticated SPA whose report carries no
    // session: there is no server-side answer to be had, so a claimed identity
    // is better than none on an install that has already decided attribution is
    // not something it trusts.
    bootBrowserForwarding(['prism.browser.trust_client_user' => true]);

    postBrowserReport([
        'user' => ['id' => '99'],
        'events' => [browserEventBody('exception')],
    ])->assertNoContent();

    expect(forwardedEvents()[0]['user_id'])->toBe('99');
});

it('prefers the backend answer even where the page hint is trusted', function () {
    // Turning the hint on can never *replace* a real identity with a claimed
    // one — it only fills a blank.
    bootBrowserForwarding(['prism.browser.trust_client_user' => true]);

    actingAsReporter(7);

    postBrowserReport([
        'user' => ['id' => '99'],
        'events' => [browserEventBody('exception')],
    ])->assertNoContent();

    expect(forwardedEvents()[0]['user_id'])->toBe('7');
});

it('reads the guard the configuration names', function () {
    // Set on the named guard alone, never through actingAs(), which also makes
    // it the default — under which the two halves below would be one assertion
    // made twice.
    config(['auth.guards.reporting' => ['driver' => 'session', 'provider' => 'users']]);

    Auth::guard('reporting')->setUser(new BrowserForwarderReporter(7));

    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(forwardedEvents()[0]['user_id'])->toBeNull();

    // A fresh recorder comes with the re-boot, so this is the second post's own
    // first event rather than the pair's second.
    bootBrowserForwarding(['prism.browser.guard' => 'reporting']);

    postBrowserReport(['events' => [browserEventBody('exception')]])->assertNoContent();

    expect(forwardedEvents()[0]['user_id'])->toBe('7');
});

it('writes the client address and the user agent the request carried', function () {
    postBrowserReport(
        ['events' => [browserEventBody('exception')]],
        server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Safari/605.1'],
    )->assertNoContent();

    expect(forwardedEvents()[0]['payload']['context'])
        ->toMatchArray(['ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1']);
});

it('enriches a log line the same way, because logs hold both halves of an incident too', function () {
    postBrowserReport(
        ['events' => [browserEventBody('log', ['payload' => ['level' => 'error', 'message' => 'checkout failed']])]],
        server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Firefox/141.0'],
    )->assertNoContent();

    expect(forwardedEvents()[0]['payload']['context'])
        ->toMatchArray(['ip' => '203.0.113.7', 'user_agent' => 'Firefox/141.0']);
});

it('writes over whatever the page said about the client', function () {
    // A page can put anything in its own copy of these; the request cannot.
    postBrowserReport(
        ['events' => [browserEventBody('exception', ['payload' => [
            'message' => 'boom',
            'context' => ['ip' => '10.0.0.1', 'user_agent' => 'Prism/1.0 (definitely a browser)'],
        ]])]],
        server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Chrome/141.0'],
    )->assertNoContent();

    expect(forwardedEvents()[0]['payload']['context'])
        ->toMatchArray(['ip' => '203.0.113.7', 'user_agent' => 'Chrome/141.0']);
});

it('keeps every other key the page put in its context', function () {
    // The enrichment is two keys, not a replacement: the url, the release and
    // the viewport are what make an error legible and only the page knows them.
    postBrowserReport(['events' => [browserEventBody('exception', ['payload' => [
        'message' => 'boom',
        'context' => ['url' => 'https://shop.test/cart', 'release' => '2026.08.3'],
    ]])]])->assertNoContent();

    expect(forwardedEvents()[0]['payload']['context'])
        ->toMatchArray(['url' => 'https://shop.test/cart', 'release' => '2026.08.3']);
});

it('gives a page view the user agent as a column of its own and no context', function () {
    // `page_views` is the browser's own execution: it names `user_agent` as a
    // column and has neither a `context` nor an `ip`, and an unknown payload key
    // is dropped by the insert silently rather than reported anywhere.
    postBrowserReport(
        ['events' => [browserEventBody('page_view', ['payload' => ['url' => 'https://shop.test/cart', 'kind' => 'load']])]],
        server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Chrome/141.0'],
    )->assertNoContent();

    expect(forwardedEvents()[0]['payload'])
        ->toMatchArray(['user_agent' => 'Chrome/141.0'])
        ->not->toHaveKey('context')
        ->not->toHaveKey('ip');
});

it('makes a header safe to insert, which nothing else on this path does', function () {
    // The one door into the pipeline a JSON parser has not already walked
    // through. ClickHouse rejects a NUL byte outright — and rejects the whole
    // insert, not the row — on a queue, long after the 204 that accepted it.
    postBrowserReport(
        ['events' => [browserEventBody('exception')]],
        server: ['HTTP_USER_AGENT' => "Chrome\0/141.0".str_repeat('zx', 400)],
    )->assertNoContent();

    $userAgent = forwardedEvents()[0]['payload']['context']['user_agent'];

    expect($userAgent)->toStartWith('Chrome/141.0')
        ->and(str_contains($userAgent, "\0"))->toBeFalse()
        ->and(strlen($userAgent))->toBe(512);
});

it('corrects an event timestamp against the report the browser sent it in', function (
    string $sentAt,
    string $eventAt,
    string $expected,
) {
    postBrowserReport([
        'sent_at' => $sentAt,
        'events' => [browserEventBody('log', ['timestamp' => $eventAt])],
    ])->assertNoContent();

    expect(forwardedEvents()[0]['timestamp'])->toBe($expected);
})->with([
    // The clock the suite is frozen at is REPORT_SENT_AT, so a `sent_at` equal
    // to it is a browser that agrees with the server.
    'a clock that agrees is left alone' => [
        REPORT_SENT_AT, '2026-08-26T09:59:50.000Z', '2026-08-26T09:59:50.000000+00:00',
    ],
    // Inside the tolerance the offset is not clock error — it is the batching
    // delay and the flight time — so shifting by it would add noise.
    'a clock three seconds behind is inside the tolerance' => [
        '2026-08-26T09:59:57.000Z', '2026-08-26T09:59:50.000Z', '2026-08-26T09:59:50.000000+00:00',
    ],
    'a clock exactly five seconds behind is still inside it' => [
        '2026-08-26T09:59:55.000Z', '2026-08-26T09:59:50.000Z', '2026-08-26T09:59:50.000000+00:00',
    ],
    'a millisecond past the tolerance is corrected' => [
        '2026-08-26T09:59:54.999Z', '2026-08-26T09:59:50.000Z', '2026-08-26T09:59:55.001000+00:00',
    ],
    'a clock an hour behind is dragged forward' => [
        '2026-08-26T09:00:00.000Z', '2026-08-26T08:59:50.000Z', '2026-08-26T09:59:50.000000+00:00',
    ],
    'a clock an hour ahead is dragged back' => [
        '2026-08-26T11:00:00.000Z', '2026-08-26T10:59:50.000Z', '2026-08-26T09:59:50.000000+00:00',
    ],
    // An event cannot have happened after the report carrying it was sent, so
    // one that is still ahead once the shift has run is a value that is nonsense
    // rather than a clock that is out. It becomes now — which is the rule that
    // has to apply to a report well inside the tolerance too, where no shift ran.
    'an event later than a report that was sent on time is clamped' => [
        REPORT_SENT_AT, '2026-08-26T10:00:30.000Z', '2026-08-26T10:00:00.000000+00:00',
    ],
    'an event still ahead after the correction is clamped' => [
        '2026-08-26T11:00:00.000Z', '2026-08-26T11:00:30.000Z', '2026-08-26T10:00:00.000000+00:00',
    ],
]);

it('moves every event in a report by the same offset, so it still reads in order', function () {
    // The gaps are what make a report legible — this happened, then ten seconds
    // later that did. One offset for the whole report keeps them; correcting
    // each event against something of its own would not.
    postBrowserReport([
        'sent_at' => '2026-08-26T09:00:00.000Z',
        'events' => [
            browserEventBody('log', ['timestamp' => '2026-08-26T08:59:40.000Z']),
            browserEventBody('log', ['timestamp' => '2026-08-26T08:59:50.000Z']),
        ],
    ])->assertNoContent();

    expect(array_column(forwardedEvents(), 'timestamp'))->toBe([
        '2026-08-26T09:59:40.000000+00:00',
        '2026-08-26T09:59:50.000000+00:00',
    ]);
});

// -- US-009: nothing on the scrub list reaches the wire ---------------------

it('scrubs a password and a token out of a posted report', function () {
    // The story's own claim, end to end and in one test: the three places a
    // credential arrives from a page — a key in an error's context, a key in a
    // breadcrumb, a query parameter in an address — and none of the three
    // values on the wire.
    postBrowserReport(['events' => [
        browserEventBody('exception', ['payload' => [
            'class' => 'TypeError',
            'message' => 'checkout failed',
            'context' => ['password' => 'hunter2', 'url' => 'https://shop.test/checkout?token=abc'],
            'breadcrumbs' => [
                ['category' => 'custom', 'message' => 'signed in', 'data' => ['password' => 'hunter2']],
            ],
        ]]),
        browserEventBody('page_view', ['payload' => ['url' => 'https://shop.test/checkout?token=abc']]),
    ]])->assertNoContent();

    $wire = (string) json_encode(forwardedEvents());

    expect($wire)->not->toContain('hunter2')
        ->and($wire)->not->toContain('token=abc')
        ->and($wire)->not->toContain('token%3Dabc');

    // Non-vacuity: the report really did arrive and really did carry those keys.
    $events = forwardedByType();

    expect($events['exception']['payload']['context'])->toHaveKey('password')
        ->and($events['exception']['payload']['breadcrumbs'][0]['data'])->toHaveKey('password')
        ->and($events['page_view']['payload']['url'])->toContain('token=');
});

it('scrubs the values the request itself supplied, not only the ones the page sent', function () {
    // `prism.scrub` is a promise about what leaves the process. The user agent
    // is written over the page's copy by the enrichment (US-008), so a rule that
    // ran before it would govern the value that was thrown away and not the one
    // that ships.
    bootBrowserForwarding(['prism.scrub' => ['user_agent']]);

    postBrowserReport(
        ['events' => [browserEventBody('log'), browserEventBody('page_view')]],
        server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (secret build)'],
    )->assertNoContent();

    $events = forwardedByType();

    expect($events['log']['payload']['context']['user_agent'])->toBe('[REDACTED]')
        ->and($events['page_view']['payload']['user_agent'])->toBe('[REDACTED]');
});

it('reuses whatever list the host wrote rather than a list of its own', function () {
    // A field name only this host would think of. Nothing in the package knows
    // it, so it can only be redacted by `prism.scrub` really reaching here.
    bootBrowserForwarding(['prism.scrub' => ['gift_code']]);

    postBrowserReport(['events' => [browserEventBody('log', ['payload' => [
        'message' => 'redeeming',
        'context' => ['gift_code' => 'XMAS-2026', 'plan' => 'team'],
    ]])]])->assertNoContent();

    $context = forwardedEvents()[0]['payload']['context'];

    expect($context['gift_code'])->toBe('[REDACTED]')
        // And the default list is not silently underneath it: an empty-ish
        // config replaces, it does not extend.
        ->and($context['plan'])->toBe('team');
});

/**
 * Boot the provider against a fresh route table with a configured install, then
 * install a transport that records instead of sending.
 *
 * The order is the load-bearing part: `registerCapture()` rebinds `Transport`
 * on every boot, so a recorder installed first is replaced by a real
 * `HttpTransport` and the suite silently tests nothing but that no exception
 * escaped.
 *
 * @param  array<string, mixed>  $config
 */
function bootBrowserForwarding(array $config = []): void
{
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
        'prism.batch.flush' => 'terminate',
        'prism.batch.queue_threshold' => 0,
        ...$config,
    ]);

    app('router')->setRoutes(new RouteCollection);
    app()->forgetInstance(PrismServiceProvider::ACTIVE);

    // Unbound, not merely forgotten: `bound()` is what the controller asks, and
    // a singleton left registered would answer yes for an install that has no
    // pipeline behind it.
    app()->offsetUnset(BrowserForwarder::class);

    (new PrismServiceProvider(app()))->boot();

    app('router')->getRoutes()->refreshNameLookups();

    test()->transport = new BrowserForwarderRecordingTransport;

    app()->instance(Transport::class, test()->transport);
}

/**
 * Every event that left over the transport, flattened out of its envelopes in
 * the order it was shipped — minus the replica heartbeat.
 *
 * `replica_metric` is the one signal with no capture engine behind it: a
 * fixed-cadence sample of the process, shipped through `writeNow()` precisely so
 * it survives an execution the sampler rejected (US-013). It rides the
 * terminating flush of every request in this suite, says nothing about the
 * report under test, and is meant to escape it — so filtering it here is what
 * lets "nothing shipped" mean "nothing about this report shipped".
 *
 * @return list<array<string, mixed>>
 */
function forwardedEvents(): array
{
    $events = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if (($event['type'] ?? '') !== 'replica_metric') {
                $events[] = $event;
            }
        }
    }

    return $events;
}

/**
 * The same, as types alone.
 *
 * @return list<string>
 */
function forwardedTypes(): array
{
    return array_map(
        static fn (array $event): string => (string) ($event['type'] ?? ''),
        forwardedEvents(),
    );
}

/**
 * The same, keyed by type — for the tests that ask one question of each of the
 * three signals a page may report.
 *
 * @return array<string, array<string, mixed>>
 */
function forwardedByType(): array
{
    $events = [];

    foreach (forwardedEvents() as $event) {
        $events[(string) ($event['type'] ?? '')] = $event;
    }

    return $events;
}

/**
 * The first envelope that carried a browser signal, for the one assertion about
 * the envelope rather than about its contents. Found rather than indexed,
 * because the heartbeat ships in an envelope of its own and when it does so is
 * the cadence's business.
 *
 * @return array<string, mixed>
 */
function forwardedEnvelope(): array
{
    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if (($event['type'] ?? '') !== 'replica_metric') {
                return $envelope;
            }
        }
    }

    return [];
}

/**
 * Sign someone in on the guard the endpoint reads by default.
 *
 * A stub rather than an Eloquent model: nothing here retrieves a user, it only
 * asks the guard for an id, and a real model would want a table for a question
 * that is answered before any query.
 */
function actingAsReporter(int|string $id): void
{
    test()->actingAs(new BrowserForwarderReporter($id));
}

/**
 * A transport that records envelopes instead of sending them. Uniquely named
 * because Pest loads every test file into one process, where a redeclared class
 * is fatal rather than a duplication anyone would notice.
 */
final class BrowserForwarderRecordingTransport implements Transport
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
 * The one thing this suite needs a user to be: something with an id. Uniquely
 * named for the reason the transport above is.
 */
final class BrowserForwarderReporter implements Authenticatable
{
    public function __construct(private readonly int|string $id) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
