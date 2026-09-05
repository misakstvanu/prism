<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Misakstvanu\Prism\Http\BodyRecorder;
use Misakstvanu\Prism\Transport\Transport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an HTTP exchange carried, captured by Prism rather than by the engine.
 *
 * The capture engine has no answer for either half — its request record
 * serialises a payload only for a 500 response, into a field with no Prism
 * column, and it has no notion of a response body at all. So both are read by
 * Prism's own middleware while the response is in hand, held until the engine
 * finally writes its `request` record from `terminate()`, and stamped onto the
 * event there.
 *
 * **Every assertion drives a real request through the real HTTP kernel**, and
 * reads the **transmitted batch**. Both halves of that matter. A test that
 * called the recorder directly would pass for a middleware that is registered
 * nowhere, for one prepended instead of pushed, and for a recorder the ingest
 * never reads — and the whole point of the design is that three separate things
 * have to line up across a request's full lifecycle. A test that inspected the
 * recorder after the fact would pass for a body that never reached the wire.
 */
beforeEach(function () {
    $this->transport = new BodyRecordingTransport;

    // The application's own boot has already installed the real transport as a
    // singleton, so the recorder goes in afterwards — a lifecycle test that
    // quietly ran against a real `HttpTransport` would read as "no telemetry
    // was produced" rather than as a broken double.
    app()->instance(Transport::class, $this->transport);

    config(['prism.scrub' => ['password', 'token']]);
});

it('records both bodies of a JSON exchange, scrubbed by key', function () {
    Route::post('/prism-body-probe', fn () => response()->json([
        'id' => 42,
        'email' => 'a@b.test',
        'token' => 'secret-response-value',
    ]));

    $this->postJson('/prism-body-probe', [
        'email' => 'a@b.test',
        'password' => 'secret-request-value',
    ])->assertOk();

    $payload = shippedRequestPayload();

    // The request body: the benign field survives, the secret does not — and it
    // is redacted BY KEY, which is the whole reason a structured body is
    // decoded rather than pattern-matched as text.
    expect($payload['request_body'] ?? '')
        ->toContain('a@b.test')
        ->and($payload['request_body'] ?? '')->toContain('[REDACTED]')
        ->and($payload['request_body'] ?? '')->not->toContain('secret-request-value');

    // The response body, which the engine cannot see at all: the same list
    // applies on the way out, so an API answering with a credential does not
    // put one in the console.
    expect($payload['response_body'] ?? '')
        ->toContain('42')
        ->and($payload['response_body'] ?? '')->not->toContain('secret-response-value');
});

it('records a form body and names an upload without its contents', function () {
    Route::post('/prism-form-probe', fn () => response('saved', 200, ['Content-Type' => 'text/plain']));

    $this->post('/prism-form-probe', [
        'title' => 'quarterly report',
        'password' => 'secret-form-value',
        'report' => UploadedFile::fake()->createWithContent('q3.csv', 'row,with,contents'),
    ])->assertOk();

    $body = shippedRequestPayload()['request_body'] ?? '';

    expect($body)->toContain('quarterly report')
        ->and($body)->not->toContain('secret-form-value')
        ->and(shippedRequestPayload()['response_body'] ?? '')->toBe('saved');

    // An upload is NAMED and SIZED, never read. A body that silently omitted
    // the half of the request that mattered would be worse than one that says
    // what it left out — and one that included the file would put an arbitrary
    // number of megabytes in a column.
    expect($body)->toContain('q3.csv')
        ->and($body)->toContain('_prism_files')
        ->and($body)->not->toContain('row,with,contents');
});

it('records the body of a request that faulted', function () {
    // The occasion the engine's own payload exists for, and the one a reader
    // most wants: by the time this middleware's `$next` returns, the exception
    // has already been rendered into a response, so a 500 is an ordinary
    // exchange here rather than a special case.
    Route::post('/prism-fault-probe', function (): never {
        throw new RuntimeException('probe blew up');
    });

    $this->postJson('/prism-fault-probe', ['email' => 'a@b.test'])->assertStatus(500);

    expect(shippedRequestPayload()['request_body'] ?? '')->toContain('a@b.test');
});

it('records the header bag of every request, scrubbed by key', function () {
    // The scrub list is what decides, and it decides BY KEY — which is the
    // whole reason the bag is read here rather than taken off the engine's own
    // record, whose redaction says `[47 bytes redacted]` where every other
    // value Prism stores says `[REDACTED]`.
    config(['prism.scrub' => ['authorization', 'cookie']]);

    Route::get('/prism-header-probe', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

    // A 200, deliberately: the engine serialises its own payload only for a
    // 500, and that rule has never governed headers. A request that was
    // addressed wrongly and answered 200 is exactly what this panel answers.
    $this->withHeaders([
        'Authorization' => 'Bearer secret-header-value',
        'Cookie' => 'session=secret-cookie-value',
        'X-Request-Origin' => 'checkout',
    ])->get('/prism-header-probe')->assertOk();

    $headers = shippedRequestPayload()['request_headers'] ?? '';

    expect($headers)->toContain('x-request-origin')
        ->and($headers)->toContain('checkout')
        ->and($headers)->not->toContain('secret-header-value')
        ->and($headers)->not->toContain('secret-cookie-value');

    // Decoded rather than matched as text, because what has to hold is that
    // the KEY was answered — a substring assertion would pass for a bag whose
    // `[REDACTED]` belonged to some other header entirely.
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($headers, true);

    expect($decoded['authorization'] ?? null)->toBe('[REDACTED]')
        ->and($decoded['cookie'] ?? null)->toBe('[REDACTED]')
        // A name that carried one value is flattened back to a string; the bag
        // is for a reader, not for a machine.
        ->and($decoded['x-request-origin'] ?? null)->toBe('checkout');
});

it('records no headers when the switch is off', function () {
    config(['prism.request.capture_headers' => false]);

    Route::get('/prism-header-off-probe', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

    $this->withHeaders(['X-Request-Origin' => 'checkout'])
        ->get('/prism-header-off-probe')
        ->assertOk();

    // ABSENT, not empty — the column keeps its own `DEFAULT ''` rather than
    // being told an empty bag was observed.
    expect(shippedRequestPayload())->not->toHaveKey('request_headers');
});

it('records the query string beside the path, not inside it', function () {
    // A column of its own: `path` is the dimension the Stream tab filters and
    // scans by, so a query mixed into it would make one endpoint a different
    // value on every request. And whatever `prism.scrub` covers is already gone
    // — the engine's own redact callback rewrites the record's URL pair by pair
    // while it builds it, which is work that had no consequence until this
    // column existed to keep the result.
    Route::get('/prism-query-probe', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

    $this->get('/prism-query-probe?page=2&token=secret-query-value')->assertOk();

    $payload = shippedRequestPayload();

    expect($payload['path'] ?? '')->toBe('/prism-query-probe')
        ->and($payload['query_string'] ?? '')->toContain('page=2')
        ->and($payload['query_string'] ?? '')->not->toContain('secret-query-value');
});

it('caps each body and says that it cut one', function () {
    config(['prism.request.max_body' => 40]);

    Route::post('/prism-cap-probe', fn () => response(str_repeat('y', 500), 200, [
        'Content-Type' => 'text/plain',
    ]));

    $this->postJson('/prism-cap-probe', ['note' => str_repeat('x', 500)])->assertOk();

    $payload = shippedRequestPayload();

    foreach (['request_body', 'response_body'] as $key) {
        // The cap bounds the body; the marker is appended after it, so what is
        // stored is never a document that merely looks malformed.
        expect(strlen((string) ($payload[$key] ?? '')))
            ->toBeLessThan(40 + strlen(BodyRecorder::TRUNCATED) + 1)
            ->and($payload[$key] ?? '')->toEndWith(BodyRecorder::TRUNCATED);
    }
});

it('records no body for a content type the list does not name', function () {
    // `text/html` is deliberately off the default list: a rendered page is the
    // largest and least diagnostic thing an application produces.
    Route::post('/prism-html-probe', fn () => response('<h1>hello</h1>', 200, [
        'Content-Type' => 'text/html',
    ]));

    $this->post('/prism-html-probe', ['title' => 'kept'])->assertOk();

    $payload = shippedRequestPayload();

    expect($payload['request_body'] ?? '')->toContain('kept')
        ->and($payload)->not->toHaveKey('response_body');
});

it('records nothing for a streamed response, whose body does not exist yet', function () {
    Route::post('/prism-stream-probe', fn () => new StreamedResponse(
        static fn () => print 'streamed',
        200,
        ['Content-Type' => 'text/plain'],
    ));

    $this->post('/prism-stream-probe', ['title' => 'kept'])->assertOk();

    expect(shippedRequestPayload())->not->toHaveKey('response_body');
});

it('records neither body when both switches are off', function () {
    config([
        'prism.request.capture_body' => false,
        'prism.request.capture_response' => false,
    ]);

    Route::post('/prism-off-probe', fn () => response()->json(['id' => 42]));

    $this->postJson('/prism-off-probe', ['email' => 'a@b.test'])->assertOk();

    $payload = shippedRequestPayload();

    // ABSENT, not empty: a key that is not in the payload leaves the column at
    // its own `DEFAULT ''` rather than asserting an empty body was observed.
    expect($payload)->not->toHaveKey('request_body')
        ->and($payload)->not->toHaveKey('response_body');
});

it('puts the bodies on the request event and on nothing else', function () {
    Route::post('/prism-scope-probe', function () {
        Log::info('probe line');

        return response()->json(['id' => 42]);
    });

    $this->postJson('/prism-scope-probe', ['email' => 'a@b.test'])->assertOk();

    $others = [];

    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] !== 'request') {
                $others[] = $event;
            }
        }
    }

    // A log line inside a request is not the request, and neither is a span or
    // a query. Stamping a body onto each of them would put the same hundred
    // kilobytes in a dozen rows.
    expect($others)->not->toBeEmpty();

    foreach ($others as $event) {
        expect($event['payload'])->not->toHaveKey('request_body')
            ->and($event['payload'])->not->toHaveKey('response_body');
    }
});

/**
 * The payload of the one `request` event the last exchange shipped.
 *
 * @return array<string, mixed>
 */
function shippedRequestPayload(): array
{
    foreach (test()->transport->sent as $envelope) {
        foreach ($envelope['events'] as $event) {
            if ($event['type'] === 'request') {
                return $event['payload'];
            }
        }
    }

    throw new RuntimeException('No request event was shipped.');
}

/** Records the batches handed to it instead of sending them. */
final class BodyRecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $envelope): bool
    {
        $this->sent[] = $envelope;

        return true;
    }
}
