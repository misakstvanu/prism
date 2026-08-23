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

it('reports the token as present without printing it', function () {
    configureCheck();
    Http::fake(['prism.test/*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('prism:check')
        ->expectsOutputToContain('present')
        ->doesntExpectOutputToContain(str_repeat('a', 40))
        ->assertExitCode(0);
});

it('reports no per-domain capture toggles, because there are none to honour', function () {
    // Which signals are captured is the capture engine's vocabulary (US-001),
    // and US-021 deleted the listeners a `prism.capture` block would have
    // gated — so the command prints no such section even when a host has
    // written the keys anyway. Printing "requests ... enabled" off a key
    // nothing reads is a report that lies to the one operator consulting it.
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
