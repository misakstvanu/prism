<?php

use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Transport\Transport;

/**
 * Reconfigure the app with full credentials and re-boot so registerCapture()
 * wires the Scrubber singleton from the current `prism.scrub` config. Named
 * apart from the other test files' boot helpers so Pest — which loads every
 * test file into one process — never redeclares a global function.
 */
function bootScrubbing(): void
{
    config([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
    ]);

    app()->forgetInstance(PrismServiceProvider::ACTIVE);
    app()->forgetInstance(EventBuffer::class);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(Scrubber::class);

    (new PrismServiceProvider(app()))->boot();
}

/** The default scrub list uses the exact keys the AC enumerates. */
function defaultScrubber(): Scrubber
{
    return new Scrubber(config('prism.scrub'));
}

// -- AC1: the default list covers every documented sensitive key ------------

it('redacts every default sensitive key', function () {
    $scrubber = defaultScrubber();

    $required = [
        'password', 'password_confirmation', 'token', 'api_key', 'secret',
        'authorization', 'cookie', 'credit_card', 'cvv', 'ssn',
    ];

    // Build one flat payload holding every required key with a real-looking
    // secret, plus a benign field that must survive.
    $payload = ['keep_me' => 'visible'];
    foreach ($required as $key) {
        $payload[$key] = 'super-secret-value';
    }

    $clean = $scrubber->scrub($payload);

    foreach ($required as $key) {
        expect($clean)->toHaveKey($key, Scrubber::REDACTED);
    }

    // The key is preserved and only the value redacted, so the shape stays
    // legible; the non-sensitive field is untouched.
    expect($clean)->toHaveKey('keep_me', 'visible')
        ->and(array_keys($clean))->toBe(array_keys($payload));
});

it('matches keys case-insensitively', function () {
    $clean = defaultScrubber()->scrub([
        'Password' => 'a',
        'API_KEY' => 'b',
        'Authorization' => 'c',
        'Cookie' => 'd',
    ]);

    expect($clean)->toBe([
        'Password' => Scrubber::REDACTED,
        'API_KEY' => Scrubber::REDACTED,
        'Authorization' => Scrubber::REDACTED,
        'Cookie' => Scrubber::REDACTED,
    ]);
});

// -- AC2: recursion through nested arrays and objects -----------------------

it('redacts recursively through nested arrays', function () {
    $clean = defaultScrubber()->scrub([
        'user' => [
            'name' => 'Ada',
            'password' => 'hunter2',
            'credentials' => [
                'api_key' => 'ak_live_123',
                'label' => 'primary',
            ],
        ],
        'items' => [
            ['sku' => 'A1', 'token' => 't-1'],
            ['sku' => 'B2', 'token' => 't-2'],
        ],
    ]);

    expect($clean['user']['name'])->toBe('Ada')
        ->and($clean['user']['password'])->toBe(Scrubber::REDACTED)
        ->and($clean['user']['credentials']['api_key'])->toBe(Scrubber::REDACTED)
        ->and($clean['user']['credentials']['label'])->toBe('primary')
        ->and($clean['items'][0]['token'])->toBe(Scrubber::REDACTED)
        ->and($clean['items'][0]['sku'])->toBe('A1')
        ->and($clean['items'][1]['token'])->toBe(Scrubber::REDACTED);
});

it('redacts recursively through nested objects', function () {
    $credentials = new stdClass;
    $credentials->secret = 'sh-1';
    $credentials->label = 'primary';

    $clean = defaultScrubber()->scrub([
        'account' => (object) [
            'id' => 7,
            'password' => 'hunter2',
            'credentials' => $credentials,
        ],
    ]);

    expect($clean['account'])->toBeObject()
        ->and($clean['account']->id)->toBe(7)
        ->and($clean['account']->password)->toBe(Scrubber::REDACTED)
        ->and($clean['account']->credentials->secret)->toBe(Scrubber::REDACTED)
        ->and($clean['account']->credentials->label)->toBe('primary');
});

it('redacts a whole nested structure when its key is sensitive', function () {
    // A matched key redacts wholesale — the value's type does not matter, so a
    // secret hidden in an array or object under a sensitive key never leaks.
    $clean = defaultScrubber()->scrub([
        'token' => ['access' => 'a', 'refresh' => 'b'],
        'secret' => (object) ['k' => 'v'],
    ]);

    expect($clean['token'])->toBe(Scrubber::REDACTED)
        ->and($clean['secret'])->toBe(Scrubber::REDACTED);
});

// -- AC3: the shape stays legible -------------------------------------------

it('preserves keys and non-sensitive values so the shape stays legible', function () {
    $payload = [
        'method' => 'POST',
        'path' => '/checkout',
        'body' => ['email' => 'a@b.test', 'password' => 'hunter2'],
    ];

    $clean = defaultScrubber()->scrub($payload);

    expect($clean)->toBe([
        'method' => 'POST',
        'path' => '/checkout',
        'body' => ['email' => 'a@b.test', 'password' => Scrubber::REDACTED],
    ]);
});

// -- AC2: applies to each captured surface ----------------------------------

it('scrubs request bodies, query strings, headers, bindings and job payloads', function () {
    $scrubber = defaultScrubber();

    // request body
    expect($scrubber->scrub(['email' => 'a@b.test', 'password' => 'p']))
        ->toBe(['email' => 'a@b.test', 'password' => Scrubber::REDACTED]);

    // query string
    expect($scrubber->scrub(['q' => 'shoes', 'api_key' => 'ak']))
        ->toBe(['q' => 'shoes', 'api_key' => Scrubber::REDACTED]);

    // headers (each a list of values under the header name)
    expect($scrubber->scrub(['Accept' => ['application/json'], 'Cookie' => ['sid=abc']]))
        ->toBe(['Accept' => ['application/json'], 'Cookie' => Scrubber::REDACTED]);

    // query bindings
    expect($scrubber->scrub(['id' => 5, 'ssn' => '111-22-3333']))
        ->toBe(['id' => 5, 'ssn' => Scrubber::REDACTED]);

    // job payload
    expect($scrubber->scrub(['job' => 'App\\Jobs\\Charge', 'cvv' => '123']))
        ->toBe(['job' => 'App\\Jobs\\Charge', 'cvv' => Scrubber::REDACTED]);
});

// -- AC4: the list is additive via config -----------------------------------

it('scrubs a custom key added through config', function () {
    // A field specific to the host app, added to the default list.
    config(['prism.scrub' => array_merge((array) config('prism.scrub'), ['x_internal_secret'])]);

    bootScrubbing();

    $scrubber = app(Scrubber::class);

    $clean = $scrubber->scrub([
        'x_internal_secret' => 'leak-me',
        'password' => 'still-scrubbed', // a default key still applies
        'safe' => 'ok',
    ]);

    expect($clean)->toBe([
        'x_internal_secret' => Scrubber::REDACTED,
        'password' => Scrubber::REDACTED,
        'safe' => 'ok',
    ]);
});

it('wires the Scrubber as a singleton once capture is active', function () {
    bootScrubbing();

    expect(app()->bound(Scrubber::class))->toBeTrue()
        ->and(app(Scrubber::class))->toBe(app(Scrubber::class));
});

// -- AC5: an Authorization: Bearer header never reaches a payload -----------

it('never lets an Authorization Bearer header appear in a serialized payload', function () {
    $scrubber = defaultScrubber();

    $bearer = 'Bearer prism_live_'.str_repeat('z', 40);

    // Shaped like Symfony's header bag: name => list of values, nested inside a
    // request-shaped event the way a capture listener would collect it.
    $event = [
        'method' => 'GET',
        'headers' => [
            'Accept' => ['application/json'],
            'Authorization' => [$bearer],
            'X-Request-Id' => ['req-1'],
        ],
    ];

    $clean = $scrubber->scrub($event);
    $serialized = json_encode($clean);

    expect($clean['headers']['Authorization'])->toBe(Scrubber::REDACTED)
        ->and($serialized)->not->toContain($bearer)
        ->and($serialized)->not->toContain('prism_live_')
        // the surrounding, non-sensitive shape survives
        ->and($clean['headers']['Accept'])->toBe(['application/json'])
        ->and($clean['method'])->toBe('GET');
});
