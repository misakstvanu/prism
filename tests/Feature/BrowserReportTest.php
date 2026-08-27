<?php

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Misakstvanu\Prism\Browser\BrowserEvent;
use Misakstvanu\Prism\Browser\BrowserReport;
use Misakstvanu\Prism\Browser\BrowserText;

/**
 * What the browser endpoint accepts, and what it does to it (US-006).
 *
 * The endpoint is open to the internet by construction — an anonymous visitor
 * hitting a JavaScript error is the report most worth having — so every claim
 * here is about a body someone hostile, or merely broken, can send. Three kinds
 * of rule, and the tests are grouped by which kind a rule is:
 *
 *   - **Refusals**, which cost the host as little as possible and are ordered by
 *     what they cost to detect (a declared length before a body, a decode before
 *     an understanding, an envelope before its events).
 *   - **Drops**, which are per event: a page in a bad state is the page worth
 *     hearing from, and one malformed entry must not cost the good ones beside
 *     it.
 *   - **Truncations**, which are per field: a 40 KB message is a real error
 *     reported verbosely, and losing it because it was long would lose exactly
 *     the errors that have the most to say.
 *
 * The statuses are asserted over HTTP because the ordering between them is the
 * controller's; everything below that is asserted against the parser directly,
 * where a payload can be read back rather than inferred from a 204 the endpoint
 * answers to everything it accepts.
 */
beforeEach(function () {
    // The named limiter counts in the cache, and the `web` group encrypts
    // cookies. Neither is configured in the Testbench skeleton.
    config([
        'cache.default' => 'array',
        'app.key' => 'base64:'.base64_encode(str_repeat('prism-key-32-byt', 2)),
    ]);

    app(HttpKernelContract::class);
});

it('accepts a well-formed report with an empty answer', function () {
    postBrowserReport(['events' => [browserEventFixture()]])->assertNoContent();
});

it('accepts a beacon, whose body is text/plain and cannot be anything else', function () {
    // `navigator.sendBeacon` cannot set a header, so the transport that survives
    // a page being closed — the one carrying the last report of a session —
    // always arrives as text/plain. A parse keyed on the content type would work
    // in every hand test and silently drop exactly those reports.
    postBrowserReport(['events' => [browserEventFixture()]], contentType: 'text/plain')
        ->assertNoContent();
});

it('refuses a post whose declared length is over the byte cap', function () {
    // The cap is comfortably wider than the body, so the only thing over it is
    // what the request SAYS it is about to send — which is what the host is
    // spared reading. Refusing on the body alone would pass this test with the
    // header ignored, so the two caps have to disagree for the claim to mean
    // anything.
    config(['prism.browser.max_bytes' => 8192]);

    postBrowserReport(server: ['CONTENT_LENGTH' => '999999'])->assertStatus(413);

    postBrowserReport()->assertNoContent();
});

it('refuses a body over the byte cap', function () {
    config(['prism.browser.max_bytes' => 128]);

    postBrowserReport()->assertStatus(413);
});

it('refuses a batch holding more events than the cap', function () {
    config(['prism.browser.max_events' => 2]);

    postBrowserReport(['events' => array_fill(0, 3, browserEventFixture())])->assertStatus(413);

    postBrowserReport(['events' => array_fill(0, 2, browserEventFixture())])->assertNoContent();
});

it('counts the events as posted, not the ones that survive', function () {
    // Otherwise a page could post ten thousand malformed events and pay for
    // none of them: the cap exists to bound what one post costs the host, and
    // filtering ten thousand events to discover there were too many is the cost
    // it exists to avoid.
    config(['prism.browser.max_events' => 2]);

    postBrowserReport(['events' => array_fill(0, 3, ['type' => 'nonsense'])])->assertStatus(413);
});

it('refuses a body it cannot decode', function (string $body) {
    postBrowserReport($body)->assertStatus(400);
})->with([
    'truncated json' => '{"v": 1, "events": [',
    'not json at all' => 'events=1&type=exception',
    'empty' => '',
    'whitespace' => "  \n ",
]);

it('refuses a body it can decode that is not a version 1 report', function (array|string $body) {
    postBrowserReport($body)->assertStatus(422);
})->with([
    'a bare scalar' => '"hello"',
    'null' => 'null',
    'a list' => '[]',
    'another version' => [['v' => 2]],
    'no version' => [['v' => null]],
    'no sent_at' => [['sent_at' => null]],
    'a blank sent_at' => [['sent_at' => '   ']],
    'an unreadable sent_at' => [['sent_at' => 'the day before yesterday-ish']],
    'no events' => [['events' => null]],
    'events that are not a list' => [['events' => 'one']],
]);

it('reads the envelope a page sends', function () {
    $report = browserReport([
        'session' => ['id' => 'session-1', 'release' => '2026.08.3'],
        'user' => ['id' => 42],
    ]);

    expect($report->sessionId)->toBe('session-1')
        ->and($report->release)->toBe('2026.08.3')
        // A number and a string mean the same thing on the other side of the
        // wire, where a user id is a primary key and JSON has one number type.
        ->and($report->userId)->toBe('42')
        ->and($report->sentAt->format('Y-m-d\TH:i:s'))->toBe('2026-08-26T10:00:00');
});

it('reads a report that names no session or user', function () {
    // Both are optional on the wire: a page can report before it has a session
    // id (private mode makes `sessionStorage` throw), and the user hint is a
    // hint the host has to opt into believing at all.
    $report = browserReport(['session' => null, 'user' => null]);

    expect($report->sessionId)->toBe('')
        ->and($report->release)->toBe('')
        ->and($report->userId)->toBeNull();
});

it('drops an event it cannot make a row out of, and counts it', function (array $event) {
    $report = browserReport(['events' => [browserEventFixture(), $event]])->filter();

    expect($report->events)->toHaveCount(1)
        ->and($report->dropped())->toBe(1)
        // The good event beside it is untouched: one malformed entry never
        // costs the report it arrived in.
        ->and($report->events[0]->type)->toBe('exception');
})->with([
    'an unknown type' => [browserEventFixture(['type' => 'metric'])],
    'no type' => [browserEventFixture(['type' => null])],
    'an unreadable timestamp' => [browserEventFixture(['timestamp' => 'about a minute ago-ish'])],
    'a numeric timestamp' => [browserEventFixture(['timestamp' => 1756202400000])],
    'no timestamp' => [browserEventFixture(['timestamp' => null])],
    'a payload that is not an object' => [browserEventFixture(['payload' => 'boom'])],
    'a payload that is a list' => [browserEventFixture(['payload' => ['boom']])],
    'not an object at all' => [['nope']],
]);

it('accepts every signal a page may report', function (string $type) {
    $report = browserReport(['events' => [browserEventFixture(['type' => $type])]])->filter();

    expect($report->events)->toHaveCount(1)
        ->and($report->dropped())->toBe(0)
        ->and($report->events[0]->type)->toBe($type);
})->with(BrowserEvent::TYPES);

it('accepts an event whose payload is empty', function () {
    // `{}` and `[]` are the same value once decoded, so an empty payload cannot
    // be told from an empty list — and an event with nothing in it is useless
    // rather than malformed.
    $report = browserReport(['events' => [browserEventFixture(['payload' => []])]])->filter();

    expect($report->events)->toHaveCount(1)
        ->and($report->dropped())->toBe(0);
});

it('keeps a W3C trace id', function () {
    expect(browserEvent(['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736'])->traceId)
        ->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('blanks a trace id that is not one, rather than dropping the event', function (mixed $traceId) {
    // A trace id a page composed keys the browser error to the backend request
    // it triggered (US-019). One that is not a W3C id keys nothing — but it says
    // nothing *wrong* about the error either, and an unkeyed browser error still
    // lands on the Errors screen where a dropped one lands nowhere.
    $event = browserEvent(['trace_id' => $traceId]);

    expect($event->traceId)->toBe('')
        ->and($event->type)->toBe('exception');
})->with([
    'uppercase hex' => '4BF92F3577B34DA6A3CE929D0E0E4736',
    'too short' => '4bf92f3577b34da6a3ce929d0e0e473',
    'not hex' => 'zzf92f3577b34da6a3ce929d0e0e4736',
    'the reserved all-zero id' => '00000000000000000000000000000000',
    'a number' => 12345,
    'missing' => null,
]);

it('truncates a long message rather than refusing the event', function () {
    $event = browserEvent(['payload' => ['message' => str_repeat('a', 10_000)]]);

    expect(strlen($event->payload['message']))->toBe(4096);
});

it('keeps the first fifty frames and the first fifty breadcrumbs', function () {
    $event = browserEvent(['payload' => [
        'frames' => array_fill(0, 80, ['file' => 'app.js', 'line' => 1]),
        'breadcrumbs' => array_fill(0, 80, ['type' => 'click']),
    ]]);

    expect($event->payload['frames'])->toHaveCount(50)
        ->and($event->payload['breadcrumbs'])->toHaveCount(50);
});

it('truncates a url and a referrer', function () {
    $event = browserEvent(['type' => 'page_view', 'payload' => [
        'url' => 'https://app.test/?q='.str_repeat('a', 5000),
        'referrer' => 'https://app.test/from?q='.str_repeat('b', 5000),
    ]]);

    expect(strlen($event->payload['url']))->toBe(2048)
        ->and(strlen($event->payload['referrer']))->toBe(2048);
});

it('cuts a context down to eight kilobytes of still-valid JSON', function (array $context) {
    $event = browserEvent(['payload' => ['context' => $context]]);

    $encoded = json_encode($event->payload['context']);

    expect(strlen((string) $encoded))->toBeLessThanOrEqual(8192)
        ->and(json_decode((string) $encoded, true))->toBeArray();
})->with([
    'one enormous value' => [['url' => 'https://app.test/orders', 'blob' => str_repeat('a', 40_000)]],
    'many ordinary values' => [array_fill_keys(
        array_map(static fn (int $i): string => "key-{$i}", range(1, 40)),
        str_repeat('v', 500),
    )],
]);

it('gives up the biggest key, not the last one', function () {
    // The keys the SDK adds — the url, the session, the release — are what make
    // an error legible, and a page's own slab of text is what made the context
    // too big. Dropping in insertion order would keep the slab and lose all four.
    $event = browserEvent(['payload' => ['context' => [
        'blob' => str_repeat('a', 40_000),
        'url' => 'https://app.test/orders',
        'session_id' => 'session-1',
    ]]]);

    expect($event->payload['context'])->toBe([
        'url' => 'https://app.test/orders',
        'session_id' => 'session-1',
    ]);
});

it('leaves a context that fits exactly as the page wrote it', function () {
    $context = ['url' => 'https://app.test/orders', 'viewport' => '1440x900', 'attempts' => 3];

    expect(browserEvent(['payload' => ['context' => $context]])->payload['context'])->toBe($context);
});

it('strips NUL bytes from every string in a payload, keys included', function () {
    // A NUL survives a JSON round trip perfectly well and is refused by the
    // datastore at the far end of the pipeline — which rejects the whole insert
    // rather than the row, on a queue, long after this request was answered.
    $event = browserEvent(['payload' => [
        'message' => "boom\0ed",
        'context' => ["ke\0y" => "val\0ue"],
    ]]);

    expect($event->payload['message'])->toBe('boomed')
        ->and($event->payload['context'])->toBe(['key' => 'value']);
});

it('strips invalid UTF-8, which reaches the payload from the server side', function () {
    // Not through the JSON body — `json_decode` refuses a malformed sequence
    // outright — but through the enrichment the host adds (US-008): a
    // `User-Agent` header is a byte string an old client can put anything in.
    expect(BrowserText::clean("caf\xC3\xA9 \xB1\xB2 bar"))->toBe('café  bar')
        ->and(mb_check_encoding(BrowserText::clean("\xC3\x28"), 'UTF-8'))->toBeTrue();
});

it('never cuts a multi-byte character in half', function () {
    // The cap is in bytes because a column's cost is bytes, but a cut through
    // the middle of a character produces exactly the invalid UTF-8 the rule
    // above exists to keep out.
    $cut = BrowserText::truncate(str_repeat('é', 100), 51);

    expect(strlen($cut))->toBe(50)
        ->and(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($cut, 'UTF-8'))->toBe(25);
});

it('cuts a long identifier rather than refusing the report', function () {
    $report = browserReport(['session' => ['id' => str_repeat('s', 1000), 'release' => 'r']]);

    expect(strlen($report->sessionId))->toBe(256);
});

/**
 * One well-formed event, with whatever a caller wants to be wrong about it
 * merged over the top. A `null` override removes the key, which is how the
 * "missing" cases below are written.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function browserEventFixture(array $overrides = []): array
{
    return array_filter([
        'type' => 'exception',
        'timestamp' => '2026-08-26T09:59:58.250Z',
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'payload' => ['class' => 'TypeError', 'message' => 'x is not a function'],
        ...$overrides,
    ], static fn (mixed $value): bool => $value !== null);
}

/**
 * The parsed report, asserted on directly: the endpoint answers 204 to
 * everything it accepts, so what it accepted is not readable from the response.
 *
 * @param  array<string, mixed>  $overrides
 */
function browserReport(array $overrides = []): BrowserReport
{
    $report = BrowserReport::tryParse(browserReportBody($overrides));

    expect($report)->toBeInstanceOf(BrowserReport::class);

    /** @var BrowserReport $report */
    return $report;
}

/**
 * The one accepted event out of a report carrying only it.
 *
 * @param  array<string, mixed>  $overrides
 */
function browserEvent(array $overrides = []): BrowserEvent
{
    $events = browserReport(['events' => [browserEventFixture($overrides)]])->filter()->events;

    expect($events)->toHaveCount(1);

    return $events[0];
}
