<?php

use Composer\InstalledVersions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;

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

it('reports the capture engine and the version installed in this tree', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // The version is read back the same way the command reads it rather than
    // spelled out here: what is asserted is that the command reports the
    // INSTALLED version, not that this tree happens to be on any given one.
    $version = InstalledVersions::getPrettyVersion('laravel/nightwatch');

    expect($version)->toBeString()->not->toBeEmpty();

    $this->artisan('prism:check')
        ->expectsOutputToContain('Capture engine')
        ->expectsOutputToContain('laravel/nightwatch '.$version)
        ->assertExitCode(0);
});

it('says out loud that no agent daemon is required', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // Nightwatch's own documentation tells an operator to run a `nightwatch:agent`
    // daemon. Prism replaces the ingest wholesale, so there is no daemon to
    // install or monitor — and an operator who goes looking for one has already
    // lost an afternoon. That is why the line is unconditional.
    $this->artisan('prism:check')
        ->expectsOutputToContain('not required')
        ->assertExitCode(0);
});

it('reports the ingest as not installed when nightwatch never registered', function () {
    // This suite registers Prism ALONE (see `TestCase`), so no `Core` is bound
    // and the swap had nothing to assign onto. The command reports that state
    // rather than claiming an in-process pipeline it cannot see.
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    expect(app()->bound(Core::class))->toBeFalse();

    $this->artisan('prism:check')
        ->expectsOutputToContain('not installed')
        ->assertExitCode(0);
});

it('reports the otel span lane, which is what gives the waterfall its nesting', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // The `ingest` line says records reach Prism; this one says whether they
    // reach it as a TREE. Set here rather than left to the provider's own
    // derivation because this suite configures Prism from inside the test —
    // long after `register()` and the `booting()` pass that write this key —
    // and what is under test is the report, not the derivation
    // (OpenTelemetryConfigTest owns that half).
    config(['opentelemetry.traces.processors' => [PrismSpanProcessor::class]]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('otel spans')
        ->assertExitCode(0);
});

it('reports the span lane as not configured when the host owns the otel config, without failing', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // The state a host that published config/opentelemetry.php is left in:
    // Prism wrote nothing, deliberately, so the processor is installed and
    // registered by nobody. Spans keep arriving from the capture engine's own
    // records — flat — so the command reports it and still exits zero.
    config(['opentelemetry.traces.processors' => []]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('installed but not registered')
        ->assertExitCode(0);
});

/**
 * The browser endpoint's line (US-011).
 *
 * Each wording gets a test of its own rather than a chain of matchers in one:
 * `expectsOutputToContain` matchers are unordered and the first declared match
 * consumes the line it matched, so two wordings asserted together can pass by
 * matching each other's line.
 *
 * Every one of them establishes the state through `bootBrowserEndpoint()` — a
 * real provider boot into a fresh route collection — rather than by setting
 * config and trusting the command to re-derive it, because what the line
 * reports is what the ROUTER will answer. A test that only set config would
 * pass against a command that had quietly stopped reading the route table.
 */
it('reports the browser endpoint as live and same-origin', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    bootBrowserEndpoint(['prism.browser.enabled' => true, 'prism.browser.origins' => []]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('POST /_prism/browser (enabled, same-origin)')
        ->assertExitCode(0);
});

it('reports the browser endpoint at the path the host renamed it to', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // The printed address comes from the route itself, so the leading and
    // trailing slashes a host copies out of a browser's address bar are gone
    // from it for the same reason they are gone from the route table.
    bootBrowserEndpoint(['prism.browser.path' => '/telemetry/from-the-browser/']);

    $this->artisan('prism:check')
        ->expectsOutputToContain('POST /telemetry/from-the-browser (enabled, same-origin)')
        ->assertExitCode(0);
});

it('lists the origins a split-origin frontend may post from', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // A frontend deployed apart from its backend fails with no symptom but a
    // browser refusing the call, so the list is read back — in the normalised
    // form a request is matched against, not the form it was written in.
    bootBrowserEndpoint(['prism.browser.origins' => [
        'https://app.example.com/',
        'HTTPS://ADMIN.EXAMPLE.COM',
    ]]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('(enabled, origins: https://app.example.com, https://admin.example.com)')
        ->assertExitCode(0);
});

it('reports the browser endpoint as disabled when only the browser block is off', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    bootBrowserEndpoint(['prism.browser.enabled' => false]);

    // Named by the variable that switched it off, because the fix is that
    // variable and not the master switch reported two lines further down.
    $this->artisan('prism:check')
        ->expectsOutputToContain('disabled (PRISM_BROWSER_ENABLED)')
        ->assertExitCode(0);
});

it('reports the browser endpoint as not registered when Prism itself is off', function () {
    configureCheck(['prism.enabled' => false]);
    Http::fake();

    bootBrowserEndpoint();

    // The disabled install still prints this line: the endpoint sits below the
    // enabled gate and above the token one, so a blank PRISM_TOKEN answers 204
    // and PRISM_ENABLED=false is the only way a configured install 404s the SDK.
    $this->artisan('prism:check')
        ->expectsOutputToContain('not registered — PRISM_ENABLED is false')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('reports the browser endpoint as not registered when the path is blank', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // Both switches on and no route: the only remaining cause, and a different
    // variable to fix, so it gets a wording of its own rather than the one the
    // browser block's switch gets.
    bootBrowserEndpoint(['prism.browser.path' => '/']);

    $this->artisan('prism:check')
        ->expectsOutputToContain('not registered — prism.browser.path is blank')
        ->assertExitCode(0);
});

it('reports the token as present without printing it', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('present')
        ->doesntExpectOutputToContain(str_repeat('a', 40))
        ->assertExitCode(0);
});

it('reports a blank token on a local host as a state, not a failure', function () {
    // The token-less local path (US-003): the hub accepts an untokened batch in
    // its own `local` environment, so there is nothing here for anyone to fix
    // and the command carries on to the live check.
    configureCheck(['prism.token' => null]);
    app()->instance('env', 'local');
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    // One wording per test: `expectsOutputToContain` matchers are unordered and
    // the first declared match consumes the line it matched.
    $this->artisan('prism:check')
        ->expectsOutputToContain('(none — local host, hub must allow untokened ingest)')
        ->assertExitCode(0);
});

it('probes with no bearer at all on the token-less local path', function () {
    // Exactly as the transport ships it: the hub's fallback is reached by a
    // MISSING bearer, so probing with an empty one would test a different thing
    // from what the client will do.
    configureCheck(['prism.token' => null]);
    app()->instance('env', 'local');
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('prism:check')->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('names the untokened batch when a hub refuses it', function () {
    // A 401 here is not "check PRISM_TOKEN" — there is none to check. What went
    // wrong is at the other end: the hub is not local, or has no workspace to
    // attribute the batch to.
    configureCheck(['prism.token' => null]);
    app()->instance('env', 'local');
    Http::fake(['prism.test/*' => Http::response([], 401)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('refused an untokened batch')
        ->assertExitCode(1);
});

it('reports no per-domain capture toggles, because there are none to honour', function () {
    // US-001 moved the "which signals" vocabulary to the capture engine and
    // dropped the `capture` block; US-021 deleted the listeners the block's
    // remaining entries would have gated. A host that published the pre-2.0 file
    // still HAS the block, which is exactly why this section had to go rather
    // than stay: printing "requests ... enabled" off a key nothing reads is a
    // report that lies to the one operator who would consult it.
    configureCheck(['prism.capture' => ['requests' => true, 'queries' => false]]);
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('prism:check')
        ->doesntExpectOutputToContain('Capture domains')
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
