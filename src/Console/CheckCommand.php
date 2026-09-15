<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Browser\BrowserCors;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Otel\SpanLane;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Credentials;
use Misakstvanu\Prism\Support\Recursion;
use Throwable;

/**
 * One-shot preflight for `php artisan prism:check` (US-052) — five checks before a team trusts the client:
 *   1. Configuration. PRISM_TOKEN and PRISM_APP present, endpoint a valid URL, each problem naming the
 *      exact variable at fault.
 *   2. Capture engine (US-005). Which engine, which version, whether Prism's own in-process ingest sits
 *      over it, that no agent daemon is required, plus the browser endpoint (US-011).
 *   3. Delivery. How a batch leaves, and under `spool` whether its cache store and queue are in place.
 *   4. Connectivity + token. One test event POSTed to the configured ingest endpoint with the bearer token,
 *      so unreachable host, rejected token and accepted event are three distinct, clearly-labelled outcomes.
 *   5. Acceptance. The `{ accepted }` count confirms the event was stored, not merely that the POST was 2xx.
 * Registered unconditionally (even for a disabled or token-less install) so it can diagnose a broken one, and
 * exits non-zero on any failure, so it works as a CI/deploy gate (AC4). A deliberate `PRISM_ENABLED=false` is
 * reported and treated as success: an opt-out is not a misconfiguration.
 */
class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'prism:check';

    /** @var string */
    protected $description = 'Verify the Prism client is configured and can reach the ingest endpoint.';

    /**
     * Prism's OpenTelemetry span processor as a string, not an import: the report has to work where the span
     * lane is not there at all — `keepsuit/laravel-opentelemetry` taken out of the tree, or a host that
     * published `config/opentelemetry.php` and therefore registers the processor itself or not at all.
     */
    private const SPAN_PROCESSOR = 'Misakstvanu\\Prism\\Otel\\PrismSpanProcessor';

    public function handle(Repository $config, BatchSpool $spool): int
    {
        $this->newLine();
        $this->line('<options=bold>Prism install verification</>');
        $this->newLine();

        // A deliberate opt-out — the master switch off, nothing captured or sent, nothing to verify. Report
        // and succeed: a disabled install is a choice, not a fault, and failing CI on it would be surprising.
        if (! $config->get('prism.enabled')) {
            $this->warn('  Prism is disabled — PRISM_ENABLED=false.');
            $this->line('  No telemetry is captured or sent. Set PRISM_ENABLED=true to enable it.');
            $this->newLine();

            // Printed anyway (US-011): every other check below is about what this process captures and a
            // disabled install captures nothing, but the endpoint is registered inside the host's own route
            // table — switching Prism off turns an SDK's post into a 404, where nobody here is looking.
            $this->reportBrowserEndpoint($config);
            $this->newLine();

            return self::SUCCESS;
        }

        $problems = $this->validateConfiguration($config);

        $this->reportCaptureEngine($config);
        $this->reportDelivery($config, $spool);

        // A configuration fault means the live checks cannot even run (no token to authenticate with, no
        // endpoint to reach). Report every problem at once so they are fixed in one pass, and exit non-zero.
        if ($problems !== []) {
            $this->reportProblems($problems);

            return self::FAILURE;
        }

        return $this->sendTestEvent($config);
    }

    /**
     * One actionable message per configuration problem, each naming the variable or condition at fault
     * (AC3); an empty list means the configuration is sound.
     *
     * @return list<string>
     */
    private function validateConfiguration(Repository $config): array
    {
        $problems = [];

        // A blank token is fatal everywhere except a `local` host, where a hub in the same environment
        // accepts token-less ingest (US-003) — a state on the `token` line below, not a problem to fix here.
        if (! Credentials::usable($config, $this->laravel->environment())) {
            $problems[] = 'PRISM_TOKEN is not set. Mint an ingest-scoped token in the Prism console '
                .'(Settings → API tokens) and set PRISM_TOKEN.';
        }

        if (blank($config->get('prism.app'))) {
            $problems[] = 'PRISM_APP is not set. Set PRISM_APP to the application slug this process '
                .'reports as within your workspace.';
        }

        $endpoint = (string) $config->get('prism.endpoint');

        if ($endpoint === '' || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            $problems[] = "PRISM_ENDPOINT (\"{$endpoint}\") is not a valid URL. Point it at your "
                ."workspace's /api/ingest endpoint.";
        }

        if ($problems === []) {
            $this->line('  Configuration ..... <fg=green;options=bold>OK</>');
        }

        return $problems;
    }

    /**
     * What is capturing here and where its records go (US-005); four of the six lines exist because the
     * interesting failures are silent:
     *   - **engine** names `laravel/nightwatch` and its installed version; the record shapes Prism translates
     *     are versioned per type upstream, so that version is the first thing to know when a screen empties.
     *   - **ingest** says whether `Core::$ingest` is Prism's — assigned by the provider at boot, only on the
     *     enabled + token path, so an install still capturing into the engine's own socket ingest, with
     *     nothing reaching Prism, is visible here rather than inferred from an empty console.
     *   - **agent** says `not required`, unconditionally and out loud: the engine's own documentation tells an
     *     operator to run a `nightwatch:agent` daemon, but Prism replaces the ingest wholesale — nothing to
     *     install, start, monitor or debug.
     *   - **otel spans** reports whether the span processor is registered — the whole span lane, and the half
     *     of "is this install complete" the `ingest` line does not answer. Its absence is a state, not a
     *     fault: spans keep arriving from the engine's own records, flat, so the command carries on.
     * The last two restate the token (which decides whether that ingest was installed at all) and the
     * endpoint (what the connectivity check below reaches for), so the three answers explain each other.
     */
    private function reportCaptureEngine(Repository $config): void
    {
        $this->newLine();
        $this->line('  <options=bold>Capture engine</>');

        $this->engineLine('engine', $this->engineDescription());
        $this->engineLine('ingest', $this->ingestDescription());
        $this->engineLine('agent', '<fg=green>not required</> — no daemon, nothing opens the agent socket');
        $this->engineLine('otel spans', $this->spanProcessorDescription());
        $this->reportBrowserEndpoint($config);
        $this->engineLine('token', $this->tokenDescription($config));
        $this->engineLine('endpoint', (string) $config->get('prism.endpoint'));
    }

    /**
     * The credential this process ships under — three states, not two. A blank token on a `local` host is
     * not a fault (US-003): the hub accepts a token-less batch there and attributes it to a default
     * workspace, so the line says what will happen and what must be true at the other end.
     */
    private function tokenDescription(Repository $config): string
    {
        if (Credentials::tokenless($config, $this->laravel->environment())) {
            return '<fg=yellow>(none — local host, hub must allow untokened ingest)</>';
        }

        return blank($config->get('prism.token'))
            ? '<fg=red>missing</> — set PRISM_TOKEN'
            : '<fg=green>present</>';
    }

    /** One dotted label/value line of the capture-engine report. */
    private function engineLine(string $label, string $value): void
    {
        $this->line(sprintf('    %s %s', str_pad($label.' ', 16, '.'), $value));
    }

    /**
     * The capture engine and the version installed in this tree, from Composer's runtime API rather than a
     * constant of the engine's own — what is *installed*, not what the package believes about itself; its
     * absence (a classmap-only autoloader, a hand-assembled tree) is an unknown version, not a failure.
     */
    private function engineDescription(): string
    {
        if (! class_exists(Core::class)) {
            return '<fg=red>laravel/nightwatch is not installed</> — run composer install';
        }

        $version = $this->engineVersion();

        return 'laravel/nightwatch '.($version ?? '<fg=yellow>(version unknown)</>');
    }

    /** The installed version of the capture engine, or null if it cannot be read. */
    private function engineVersion(): ?string
    {
        try {
            if (! InstalledVersions::isInstalled('laravel/nightwatch')) {
                return null;
            }

            return InstalledVersions::getPrettyVersion('laravel/nightwatch');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether Prism's own in-process pipeline is installed over the engine's ingest — the single fact that
     * decides whether anything captured in this process ever reaches the workspace.
     */
    private function ingestDescription(): string
    {
        if (! $this->laravel->bound(Core::class)) {
            return '<fg=yellow>not installed</> — the capture engine has not registered in this process';
        }

        try {
            $ingest = $this->laravel->make(Core::class)->ingest;
        } catch (Throwable $e) {
            return '<fg=yellow>unknown</> — '.$e->getMessage();
        }

        if ($ingest instanceof PrismIngest) {
            return "<fg=green>in-process</> — records go straight into Prism's buffer";
        }

        return '<fg=red>'.get_debug_type($ingest).'</> — records are NOT reaching Prism';
    }

    /**
     * The OpenTelemetry span lane contributes the parent/child span tree — the one thing the capture engine's
     * completion-only records cannot express. Two absences: no class at all means the span package is not in
     * the tree; installed and not registered means the host published `config/opentelemetry.php` (so Prism
     * deliberately wrote nothing) and has not added the processor to `traces.processors` itself. Either way
     * the command succeeds — spans still arrive from the engine's own records, so a missing processor is a
     * flatter waterfall, not a broken install.
     */
    private function spanProcessorDescription(): string
    {
        if (! class_exists(self::SPAN_PROCESSOR)) {
            return '<fg=yellow>not configured</> — spans come from the capture engine\'s own records';
        }

        // The verdict comes from {@see SpanLane}, not a second reading of the config: since US-018 it
        // decides behaviour as well as wording — with the processor registered the engine's own `query` and
        // `outgoing-request` records are dropped in favour of the spans, and a line that could disagree
        // with that decision is the one place an operator looks to find out why Queries is empty.
        if ($this->laravel->make(SpanLane::class)->registered()) {
            return '<fg=green>configured</>';
        }

        return '<fg=yellow>not configured</> — '.self::SPAN_PROCESSOR.' is installed but not registered';
    }

    /**
     * The browser endpoint's line (US-011). Every other line is about *this* process; this one answers what
     * a page can ask: is there something at the address the SDK posts to, and is it the one I configured? Its
     * absence reaches an operator as a 404 in a browser console, a symptom no console screen or server log
     * shows, so all four states are named out loud. Printed for a disabled install too ({@see handle()}): the
     * endpoint is registered below the `prism.enabled` gate and above the token one, and a blank `PRISM_TOKEN`
     * still answers 204, so `PRISM_ENABLED=false` is the only way a configured install stops answering at all.
     */
    private function reportBrowserEndpoint(Repository $config): void
    {
        $this->engineLine('browser', $this->browserEndpointDescription($config));
    }

    /**
     * Where the browser endpoint is, or why it is nowhere. **The verdict comes from the route table, not a
     * second reading of the config**, for the span-lane line's reason: what matters is what the router will
     * answer, config being only an input to it. It is also the only reading that survives `route:cache`, which
     * keeps serving a table built while the endpoint was on whatever config says now, and the only one that
     * cannot drift from {@see PrismServiceProvider::registerBrowserEndpoint()}'s own rules about a blank path.
     * The route's own URI is printed, so an overridden `prism.browser.path` needs no separate handling and the
     * provider's normalisation cannot disagree with one this method did not. Config is consulted only for the
     * wording of an absence, where the router has nothing to say: the two switches produce the same missing
     * route and are not the same problem.
     */
    private function browserEndpointDescription(Repository $config): string
    {
        $route = $this->browserRoute();

        if ($route !== null) {
            return sprintf(
                '<fg=green>POST /%s</> (enabled, %s)',
                ltrim($route->uri(), '/'),
                $this->browserOriginsDescription($config),
            );
        }

        if (! $config->get('prism.enabled')) {
            return '<fg=yellow>not registered</> — PRISM_ENABLED is false';
        }

        if (! $config->get('prism.browser.enabled', true)) {
            return '<fg=yellow>disabled</> (PRISM_BROWSER_ENABLED)';
        }

        // Both switches on and still no route leaves exactly one cause: the path was configured to nothing,
        // so there was no address to register. Not folded into "disabled" — the fix is a different variable.
        return '<fg=yellow>not registered</> — prism.browser.path is blank';
    }

    /** The registered browser endpoint, or null when this install has none. */
    private function browserRoute(): ?Route
    {
        $route = $this->laravel->make(Router::class)
            ->getRoutes()
            ->getByName(PrismServiceProvider::BROWSER_ROUTE);

        return $route instanceof Route ? $route : null;
    }

    /**
     * Who may post to it: the same-origin default, or the origins in `prism.browser.origins`, read through
     * {@see BrowserCors} rather than off the config key, so an entry dropped for being unusable, or normalised
     * for the comparison, is reported as it will be matched, not as written. A frontend deployed apart from its
     * backend fails with no symptom but a browser refusing the call, so the list is worth reading back.
     */
    private function browserOriginsDescription(Repository $config): string
    {
        $origins = BrowserCors::origins($config);

        return $origins === [] ? 'same-origin' : 'origins: '.implode(', ', $origins);
    }

    /**
     * How batches leave this process and, under `spool`, whether the two things a spool depends on are in
     * place. A spool that cannot be drained is the quietest way for an install to go silent: capture keeps
     * working, batches keep landing in the cache, nothing ever ships. Both preconditions are reported
     * explicitly, with the fallback spelled out, so that state is visible here.
     */
    private function reportDelivery(Repository $config, BatchSpool $spool): void
    {
        $strategy = (string) $config->get('prism.batch.flush', 'terminate');

        $this->newLine();
        $this->line('  <options=bold>Delivery</>');
        $this->line(sprintf('    %s %s', str_pad('strategy ', 16, '.'), $strategy));

        if ($strategy !== 'spool') {
            return;
        }

        $delay = (int) $config->get('prism.batch.spool.delay', 5);
        $store = $config->get('prism.batch.spool.store');
        $store = is_string($store) && $store !== '' ? $store : (string) $config->get('cache.default');

        $this->line(sprintf('    %s %s (%s)', str_pad('cache store ', 16, '.'), $store,
            $spool->usable() ? '<fg=green>atomic locks</>' : '<fg=red>no atomic locks</>'));
        $this->line(sprintf('    %s every %ds via %s', str_pad('drain ', 16, '.'), $delay,
            (string) $config->get('queue.default')));
        $this->line(sprintf('    %s %d', str_pad('waiting ', 16, '.'), $spool->pending()));

        if (! $spool->usable()) {
            $this->components->warn(
                'The configured cache store has no atomic locks, so batches cannot be spooled safely. '
                .'Prism is falling back to sending each batch inline; point PRISM_SPOOL_STORE at a '
                .'store that supports locks (redis, memcached, database, file).'
            );
        }

        if (! $spool->drainable()) {
            $this->components->warn(
                'The default queue connection is "sync", so a spooled batch would be drained inline '
                .'rather than by a worker. Prism is falling back to sending each batch inline; '
                .'configure a real queue connection to use the spool.'
            );
        }
    }

    /**
     * Print each configuration problem with a failure marker.
     *
     * @param  list<string>  $problems
     */
    private function reportProblems(array $problems): void
    {
        $this->newLine();

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        $this->line('  Configuration check failed. Fix the above and run <options=bold>prism:check</> again.');
        $this->newLine();
    }

    /**
     * POST one test event to the ingest endpoint to prove connectivity, the token and end-to-end acceptance
     * in a single round trip (AC1/AC2). The three failure modes — unreachable host, rejected token,
     * non-accepting response — each produce a distinct, actionable message and a non-zero exit; a `202`
     * whose `accepted` count includes the event is success.
     */
    private function sendTestEvent(Repository $config): int
    {
        $endpoint = (string) $config->get('prism.endpoint');
        $timeout = (float) $config->get('prism.batch.timeout', 2.0);

        $this->newLine();
        $this->line("  Sending a test event to {$endpoint} …");

        $token = (string) $config->get('prism.token');

        $request = Http::timeout($timeout)
            ->connectTimeout($timeout)
            ->acceptJson()
            // Marks the probe as Prism's own so an active install's HTTP capture (US-046) never records it.
            ->withHeaders([Recursion::MARKER_HEADER => '1']);

        // No bearer at all on the token-less local path, exactly as the transport sends it: the hub's
        // fallback is reached by a *missing* bearer, so probing with an empty one would test another thing.
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post($endpoint, $this->testEnvelope($config));
        } catch (Throwable $e) {
            $this->components->error(
                "Could not reach the ingest endpoint at {$endpoint}: {$e->getMessage()}. "
                .'Check PRISM_ENDPOINT and that the host is reachable from here.'
            );
            $this->newLine();

            return self::FAILURE;
        }

        $status = $response->status();

        if ($status === 401) {
            $this->components->error($token === ''
                ? 'The ingest endpoint refused an untokened batch (HTTP 401). A hub only accepts one '
                    .'when it is itself running in the local environment and has a workspace to '
                    .'attribute it to — otherwise set PRISM_TOKEN.'
                : 'The ingest endpoint rejected the token (HTTP 401). Check PRISM_TOKEN — it may be '
                    .'revoked, mistyped, or not an ingest-scoped token.'
            );
            $this->newLine();

            return self::FAILURE;
        }

        // Over quota (US-030): reachable and the token valid, but the workspace has exhausted its monthly
        // event allowance so the event was not stored. An operational state, not a wiring fault — report
        // and succeed, so a deploy gate is not tripped by billing.
        if ($status === 429) {
            $this->components->warn(
                'Connected and authenticated, but the workspace is over its monthly event quota '
                .'(HTTP 429). Telemetry will resume when the quota resets or the plan is upgraded.'
            );
            $this->newLine();

            return self::SUCCESS;
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($response->json('message') ?? 'no message');

            $this->components->error(
                "The ingest endpoint returned HTTP {$status} ({$message}). Check PRISM_ENDPOINT points "
                .'at a Prism workspace ingest route.'
            );
            $this->newLine();

            return self::FAILURE;
        }

        $accepted = (int) ($response->json('accepted') ?? 0);

        if ($accepted < 1) {
            $rejected = (int) ($response->json('rejected') ?? 0);

            $this->components->error(
                "The endpoint accepted the request but stored no event (accepted: {$accepted}, "
                ."rejected: {$rejected}). This usually means a version mismatch — update the Prism client."
            );
            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info('Prism is correctly configured — the ingest endpoint accepted a test event.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * The minimal versioned envelope carrying one innocuous `log` event, in the exact wire shape the server
     * validates (US-026): the least intrusive signal to inject, whose string `timestamp` and known type are
     * what make the server accept it.
     *
     * @return array<string, mixed>
     */
    private function testEnvelope(Repository $config): array
    {
        $now = now()->toIso8601String();

        return [
            'v' => 1,
            'app' => (string) $config->get('prism.app'),
            'env' => (string) $config->get('prism.environment'),
            'replica' => (string) $config->get('prism.replica'),
            'sent_at' => $now,
            'events' => [[
                'type' => 'log',
                'timestamp' => $now,
                'trace_id' => '',
                'request_id' => '',
                'user_id' => null,
                'payload' => [
                    'level' => 'info',
                    'message' => 'Prism install verification — prism:check',
                    'channel' => 'prism',
                    'context' => ['source' => 'prism:check'],
                ],
            ]],
        ];
    }
}
