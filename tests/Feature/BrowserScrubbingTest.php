<?php

use Misakstvanu\Prism\Browser\BrowserScrubber;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Support\Scrubber;

/**
 * `prism.scrub` applied to a browser payload (US-009).
 *
 * The rules themselves are asserted elsewhere and deliberately so —
 * `ScrubbingTest` owns which keys the {@see Scrubber} answers and how deep it
 * goes, and `NightwatchRedactRulesTest` owns what `redactText()` and
 * `redactUrl()` do to a string. Nothing here restates either of them. What is
 * left, and what this file is, is the one thing that *is* new: the map from a
 * browser payload's shape onto those two rules, where a key that nobody thought
 * to name is a secret that leaves.
 *
 * The wiring — that the forwarder really calls this, on the enriched payload,
 * last — is `BrowserForwarderTest`'s and `BrowserForwardingTest`'s, because a
 * map that is never consulted passes every test in this file.
 */

/**
 * The scrubber under test, built from the package's own default list so the
 * fixtures below use keys a real install really redacts. Named apart from the
 * other files' helpers: Pest loads every test file into one process.
 */
function browserScrubber(): BrowserScrubber
{
    /** @var array<int, string> $keys */
    $keys = config('prism.scrub');

    return new BrowserScrubber(new Scrubber($keys), new RedactRules(new Scrubber($keys), array_values($keys)));
}

// -- The three shapes a browser payload is made of --------------------------

it('redacts a sensitive key anywhere in an error context, however deep', function () {
    // An error's context is the SDK's own page facts plus whatever the host put
    // there with `setContext()`, so it is a bag of arbitrary shape — the same
    // problem a request body is, and answered by the same implementation.
    $payload = browserScrubber()->scrub([
        'message' => 'checkout failed',
        'context' => [
            'url' => 'https://shop.test/checkout',
            'password' => 'hunter2',
            'order' => ['api_key' => 'sk_live_9', 'total' => 4200],
        ],
    ]);

    expect($payload['context']['password'])->toBe(Scrubber::REDACTED)
        ->and($payload['context']['order']['api_key'])->toBe(Scrubber::REDACTED)
        ->and($payload['context']['order']['total'])->toBe(4200)
        ->and($payload['context']['url'])->toBe('https://shop.test/checkout');
});

it('redacts a log line context, which is where a failed call reports its target', function () {
    $payload = browserScrubber()->scrub([
        'message' => 'GET /api/orders → 503',
        'level' => 'error',
        'channel' => 'http',
        'context' => [
            'method' => 'GET',
            'url' => 'https://shop.test/api/orders?token=abc&page=2',
            'status' => 503,
        ],
    ]);

    expect($payload['context']['url'])->toBe('https://shop.test/api/orders?token=%5BREDACTED%5D&page=2')
        // The rest of the call is what makes the line readable and none of it is
        // a secret; a rule that blanked the bag wholesale would cost the screen
        // the only thing it had to say.
        ->and($payload['context']['status'])->toBe(503)
        ->and($payload['context']['method'])->toBe('GET');
});

it('rewrites a query string rather than blanking the address it is part of', function () {
    // The point of `redactUrl()` over a keyed rule: a token in a query string is
    // not a key of the bag, it is a fragment of one of its values — and the rest
    // of the address is what says which page an error happened on.
    $payload = browserScrubber()->scrub([
        'url' => 'https://shop.test/checkout?token=abc&step=2',
        'referrer' => 'https://shop.test/cart?access_token=xyz',
        'path' => '/checkout',
        'kind' => 'load',
    ]);

    expect($payload['url'])->toBe('https://shop.test/checkout?token=%5BREDACTED%5D&step=2')
        ->and($payload['referrer'])->toBe('https://shop.test/cart?access_token=%5BREDACTED%5D')
        ->and($payload['path'])->toBe('/checkout');
});

it('redacts a name = value pair written into a message', function () {
    // A `console.error` printing what it was about to send is the ordinary way a
    // credential ends up in free text, and it is the same problem an artisan
    // command line is — so it is the same rule.
    $payload = browserScrubber()->scrub([
        'message' => 'refusing to retry with password=hunter2',
    ]);

    expect($payload['message'])->toBe('refusing to retry with password='.Scrubber::REDACTED);
});

// -- Breadcrumbs ------------------------------------------------------------

it('redacts a breadcrumb data bag and the addresses inside it', function () {
    $payload = browserScrubber()->scrub([
        'message' => 'x is not a function',
        'breadcrumbs' => [
            ['category' => 'navigation', 'message' => 'navigated', 'data' => [
                'from' => 'https://shop.test/login?token=abc',
                'to' => 'https://shop.test/account',
            ]],
            ['category' => 'http', 'message' => 'GET /api/me', 'data' => [
                'method' => 'GET',
                'url' => 'https://shop.test/api/me?access_token=xyz',
                'status' => 200,
            ]],
            ['category' => 'custom', 'message' => 'signed in', 'data' => [
                'password' => 'hunter2',
                'plan' => 'team',
            ]],
        ],
    ]);

    expect($payload['breadcrumbs'][0]['data']['from'])->toBe('https://shop.test/login?token=%5BREDACTED%5D')
        ->and($payload['breadcrumbs'][0]['data']['to'])->toBe('https://shop.test/account')
        ->and($payload['breadcrumbs'][1]['data']['url'])->toBe('https://shop.test/api/me?access_token=%5BREDACTED%5D')
        ->and($payload['breadcrumbs'][1]['data']['status'])->toBe(200)
        ->and($payload['breadcrumbs'][2]['data']['password'])->toBe(Scrubber::REDACTED)
        ->and($payload['breadcrumbs'][2]['data']['plan'])->toBe('team');
});

it('redacts a breadcrumb message, which is where a navigation carries its address', function () {
    // A navigation crumb renders as `from → to`, so the address is *inside* the
    // text rather than named by a key — which is the free-text rule's case, not
    // the URL rule's.
    $payload = browserScrubber()->scrub([
        'breadcrumbs' => [
            ['category' => 'navigation', 'message' => '/login?token=abc → /account'],
        ],
    ]);

    expect($payload['breadcrumbs'][0]['message'])->toBe('/login?token='.Scrubber::REDACTED.' → /account');
});

it('carries a breadcrumb it cannot read rather than losing the ones beside it', function () {
    // Same stance as every other rule on this path: a page in a bad state is the
    // page worth hearing from, and one malformed crumb must not cost the report.
    $payload = browserScrubber()->scrub([
        'breadcrumbs' => ['not an object', ['category' => 'custom', 'data' => ['token' => 'abc']]],
    ]);

    expect($payload['breadcrumbs'][0])->toBe('not an object')
        ->and($payload['breadcrumbs'][1]['data']['token'])->toBe(Scrubber::REDACTED);
});

// -- What must NOT move -----------------------------------------------------

it('leaves the frames and the file the fingerprint is hashed from alone', function () {
    // A browser error's group is `class | location | message template` (US-004),
    // and the location comes from the first non-vendor frame. A file path is an
    // address in a bundle rather than a credential, so redacting one would move
    // every group it touched and buy nothing.
    $frames = [['file' => 'https://shop.test/assets/app-a1b2c3.js?v=2', 'line' => 4, 'function' => 'checkout']];

    $payload = browserScrubber()->scrub([
        'class' => 'TypeError',
        'file' => 'https://shop.test/assets/app-a1b2c3.js?v=2',
        'frames' => $frames,
    ]);

    expect($payload['frames'])->toBe($frames)
        ->and($payload['file'])->toBe('https://shop.test/assets/app-a1b2c3.js?v=2')
        ->and($payload['class'])->toBe('TypeError');
});

it('changes nothing when the host scrubs nothing', function () {
    // An empty list has to mean "leave it alone", not "there is no list, so
    // apply the defaults" — a host that emptied `prism.scrub` said something.
    $empty = new BrowserScrubber(new Scrubber([]), new RedactRules(new Scrubber([]), []));

    $payload = [
        'message' => 'refusing to retry with password=hunter2',
        'url' => 'https://shop.test/checkout?token=abc',
        'context' => ['password' => 'hunter2'],
        'breadcrumbs' => [['data' => ['url' => 'https://shop.test/api/me?token=abc']]],
    ];

    expect($empty->scrub($payload))->toBe($payload);
});
