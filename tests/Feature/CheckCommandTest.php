<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Set full, valid credentials so the command reaches the live connectivity
 * check; individual tests override one value to exercise a failure path. The
 * command reads config at runtime, so no provider re-boot is needed — unlike
 * the capture-wiring suites, `prism:check` is registered unconditionally and
 * carries no listeners to re-register.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureCheck(array $overrides = []): void
{
    config(array_merge([
        'prism.enabled' => true,
        'prism.token' => 'prism_live_'.str_repeat('a', 40),
        'prism.app' => 'demo',
        'prism.endpoint' => 'https://prism.test/api/ingest',
        'prism.environment' => 'production',
        'prism.replica' => 'web-1',
    ], $overrides));
}

it('passes when config is valid and the endpoint accepts the test event', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1, 'rejected' => 0], 202)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('accepted a test event')
        ->assertExitCode(0);

    // A single well-formed envelope carrying one `log` event went out with the
    // bearer token (AC1/AC2).
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://prism.test/api/ingest'
            && $request->hasHeader('Authorization', 'Bearer prism_live_'.str_repeat('a', 40))
            && $request['v'] === 1
            && $request['app'] === 'demo'
            && count($request['events']) === 1
            && $request['events'][0]['type'] === 'log';
    });
});

it('reports which capture domains are enabled', function () {
    configureCheck(['prism.capture.queries' => false]);
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // Each asserted substring must land on a distinct output line: Laravel's
    // command-output mock routes one `doWrite` call to a single expectation, so
    // two substrings on the same line would compete for it. "requests" is the
    // enabled line, "disabled" is the (queries) disabled line, and the header is
    // its own line — none collide.
    $this->artisan('prism:check')
        ->expectsOutputToContain('Capture domains')
        ->expectsOutputToContain('requests')
        ->expectsOutputToContain('disabled')
        ->assertExitCode(0);
});

it('fails with an actionable message when PRISM_TOKEN is missing', function () {
    configureCheck(['prism.token' => null]);
    Http::fake();

    $this->artisan('prism:check')
        ->expectsOutputToContain('PRISM_TOKEN')
        ->assertExitCode(1);

    // No live send is attempted when the configuration itself is invalid.
    Http::assertNothingSent();
});

it('fails with an actionable message when PRISM_APP is missing', function () {
    configureCheck(['prism.app' => null]);
    Http::fake();

    $this->artisan('prism:check')
        ->expectsOutputToContain('PRISM_APP')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('fails when the endpoint is not a valid URL', function () {
    configureCheck(['prism.endpoint' => 'not a url']);
    Http::fake();

    $this->artisan('prism:check')
        ->expectsOutputToContain('PRISM_ENDPOINT')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('fails when the endpoint rejects the token', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response('', 401)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('rejected the token')
        ->assertExitCode(1);
});

it('fails when the ingest endpoint is unreachable', function () {
    configureCheck();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('prism:check')
        ->expectsOutputToContain('Could not reach the ingest endpoint')
        ->assertExitCode(1);
});

it('fails when a 2xx response stores no event', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 0, 'rejected' => 1], 202)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('stored no event')
        ->assertExitCode(1);
});

it('fails on an unexpected server error', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['message' => 'boom'], 500)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('HTTP 500')
        ->assertExitCode(1);
});

it('warns but succeeds when the workspace is over quota', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response([
        'message' => 'Monthly event quota exceeded.',
        'reason' => 'quota_exceeded',
    ], 429)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('over its monthly event quota')
        ->assertExitCode(0);
});

it('reports disabled and succeeds without sending when Prism is off', function () {
    configureCheck(['prism.enabled' => false]);
    Http::fake();

    $this->artisan('prism:check')
        ->expectsOutputToContain('disabled')
        ->assertExitCode(0);

    // A deliberate opt-out has nothing to verify against the network.
    Http::assertNothingSent();
});
