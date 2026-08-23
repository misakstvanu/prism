<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Otel\SpanLane;
use Misakstvanu\Prism\Support\Recursion;
use Throwable;

/**
 * Verifies the Prism client is correctly wired up before a team trusts it
 * (US-052) — a one-shot preflight for `php artisan prism:check`.
 *
 * It runs five checks and reports each in plain language:
 *
 *   1. Configuration. The two required variables (PRISM_TOKEN, PRISM_APP) are
 *      present and the endpoint is a valid URL. A missing or malformed value
 *      names the exact variable at fault, so the fix is obvious.
 *   2. Capture engine (US-005). Which engine is capturing, at which version,
 *      whether Prism's own in-process ingest is installed over it, and — said
 *      out loud, because an operator who follows the engine's own documentation
 *      will otherwise go looking for one — that no agent daemon is required.
 *   3. Delivery. How a finished batch leaves the process and, under the `spool`
 *      strategy, whether the cache store and queue it depends on are in place —
 *      an install that captures happily into a spool nothing drains looks
 *      identical to a healthy one from the inside.
 *   4. Connectivity + token. A single test event is POSTed to the configured
 *      ingest endpoint with the bearer token. An unreachable host, a rejected
 *      token and an accepted event are three distinct, clearly-labelled outcomes.
 *   5. Acceptance. The endpoint's `{ accepted }` count is inspected so the
 *      command confirms the event was actually stored, not merely that the POST
 *      returned 2xx.
 *
 * The command is registered unconditionally (even for a disabled or
 * token-less install) precisely so it can diagnose a broken one. It exits
 * non-zero on any failure so it is usable as a gate in CI and deploy pipelines
 * (AC4); a deliberate `PRISM_ENABLED=false` is reported and treated as success,
 * because an opt-out is not a misconfiguration.
 */
class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'prism:check';

    /** @var string */
    protected $description = 'Verify the Prism client is configured and can reach the ingest endpoint.';

    /**
     * Prism's OpenTelemetry span processor, named as a string rather than
     * imported: the report has to work for an install where the span lane is
     * not there at all — a host that took `keepsuit/laravel-opentelemetry` out
     * of its tree, or one that published `config/opentelemetry.php` and
     * therefore registers the processor itself or not at all.
     */
    private const SPAN_PROCESSOR = 'Misakstvanu\\Prism\\Otel\\PrismSpanProcessor';

    public function handle(Repository $config, BatchSpool $spool): int
    {
        $this->newLine();
        $this->line('<options=bold>Prism install verification</>');
        $this->newLine();

        // A deliberate opt-out — the master switch off. Nothing is captured or
        // sent, so there is nothing to verify. Report it clearly and succeed:
        // a disabled install is a choice, not a fault, and failing CI on it
        // would be surprising.
        if (! $config->get('prism.enabled')) {
            $this->warn('  Prism is disabled — PRISM_ENABLED=false.');
            $this->line('  No telemetry is captured or sent. Set PRISM_ENABLED=true to enable it.');
            $this->newLine();

            return self::SUCCESS;
        }

        $problems = $this->validateConfiguration($config);

        $this->reportCaptureEngine($config);
        $this->reportDelivery($config, $spool);

        // A configuration fault means the live checks cannot even run (no token
        // to authenticate with, no endpoint to reach). Report every problem at
        // once so the user can fix them in one pass, and exit non-zero.
        if ($problems !== []) {
            $this->reportProblems($problems);

            return self::FAILURE;
        }

        return $this->sendTestEvent($config);
    }

    /**
     * Validate the required configuration, returning one actionable message per
     * problem found — each naming the specific variable or condition at fault
     * (AC3). An empty list means the configuration is sound.
     *
     * @return list<string>
     */
    private function validateConfiguration(Repository $config): array
    {
        $problems = [];

        if (blank($config->get('prism.token'))) {
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
     * Report what is actually capturing in this process, and where its records
     * go (US-005).
     *
     * Four of the six lines exist because the interesting failures here are
     * silent ones:
     *
     *   - **engine** names `laravel/nightwatch` and its installed version. The
     *     record shapes Prism translates are versioned per type upstream, so
     *     "which version is in this tree" is the first thing worth knowing when
     *     a screen has quietly emptied.
     *   - **ingest** says whether `Core::$ingest` is Prism's. It is what the
     *     provider assigns at boot, and it is assigned only on the enabled +
     *     token path — so an install that is capturing happily into the
     *     engine's own socket ingest, with nothing reaching Prism, is visible
     *     here rather than inferred from an empty console.
     *   - **agent** says `not required`, unconditionally and out loud. The
     *     engine's own documentation tells an operator to run a `nightwatch:agent`
     *     daemon; Prism replaces the ingest wholesale, so that daemon is not a
     *     thing to install, start, monitor or debug. An operator who goes
     *     looking for it has already lost an afternoon.
     *   - **otel spans** reports whether the span processor is registered —
     *     which is the whole of the span lane, and the half of "is this
     *     install complete" the `ingest` line does not answer. Its absence is
     *     a state, not a fault: spans keep arriving from the engine's own
     *     records, flat, so the line reports and the command carries on.
     *
     * The last two restate the token and the endpoint beside them, because the
     * token is what decides whether the ingest above was installed at all, and
     * the endpoint is the address the connectivity check below actually reaches
     * for — the three answers explain each other and are worth reading together.
     */
    private function reportCaptureEngine(Repository $config): void
    {
        $this->newLine();
        $this->line('  <options=bold>Capture engine</>');

        $this->engineLine('engine', $this->engineDescription());
        $this->engineLine('ingest', $this->ingestDescription());
        $this->engineLine('agent', '<fg=green>not required</> — no daemon, nothing opens the agent socket');
        $this->engineLine('otel spans', $this->spanProcessorDescription());
        $this->engineLine('token', blank($config->get('prism.token'))
            ? '<fg=red>missing</> — set PRISM_TOKEN'
            : '<fg=green>present</>');
        $this->engineLine('endpoint', (string) $config->get('prism.endpoint'));
    }

    /** One dotted label/value line of the capture-engine report. */
    private function engineLine(string $label, string $value): void
    {
        $this->line(sprintf('    %s %s', str_pad($label.' ', 16, '.'), $value));
    }

    /**
     * The capture engine and the version of it installed in this tree.
     *
     * The version comes from Composer's runtime API rather than from a constant
     * of the engine's own, so it reports what is *installed* rather than what
     * the package believes about itself — and its absence (a classmap-only
     * autoloader, a tree assembled by hand) is reported as an unknown version
     * rather than as a failure.
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
     * Whether Prism's own in-process pipeline is installed over the engine's
     * ingest — the single fact that decides whether anything captured in this
     * process ever reaches the workspace.
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
     * The OpenTelemetry span lane, which is what contributes the parent/child
     * span tree — the one thing the capture engine's completion-only records
     * cannot express.
     *
     * Two different absences, and the second is the one worth a sentence: no
     * class at all means the span package is not in the tree, while a class
     * that is installed and not registered means the host published
     * `config/opentelemetry.php` (so Prism wrote nothing, deliberately) and has
     * not added the processor to `traces.processors` itself. Either way the
     * command succeeds: spans still arrive from the engine's own records, so a
     * missing processor is a flatter waterfall rather than a broken install.
     */
    private function spanProcessorDescription(): string
    {
        if (! class_exists(self::SPAN_PROCESSOR)) {
            return '<fg=yellow>not configured</> — spans come from the capture engine\'s own records';
        }

        // The verdict comes from {@see SpanLane} rather than from a second
        // reading of the config, because since US-018 it decides behaviour as
        // well as wording: with the processor registered, the engine's own
        // `query` and `outgoing-request` records are dropped in favour of the
        // spans. A line that could disagree with that decision would be the
        // one place an operator looks to find out why the Queries screen is
        // empty.
        if ($this->laravel->make(SpanLane::class)->registered()) {
            return '<fg=green>configured</>';
        }

        return '<fg=yellow>not configured</> — '.self::SPAN_PROCESSOR.' is installed but not registered';
    }

    /**
     * Report how batches leave this process, and — under the `spool` strategy —
     * whether the two things a spool depends on are actually in place.
     *
     * A spool that cannot be drained is the quietest way for an install to go
     * silent: capture keeps working, batches keep landing in the cache, and
     * nothing ever ships. Both preconditions are reported explicitly, with the
     * fallback spelled out, so that state is visible here rather than inferred
     * from an empty console.
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
     * POST one test event to the ingest endpoint to prove connectivity, the
     * token and end-to-end acceptance in a single round trip (AC1/AC2). The
     * three failure modes — unreachable host, rejected token, non-accepting
     * response — each produce a distinct, actionable message and a non-zero
     * exit; a `202` whose `accepted` count includes the event is success.
     */
    private function sendTestEvent(Repository $config): int
    {
        $endpoint = (string) $config->get('prism.endpoint');
        $timeout = (float) $config->get('prism.batch.timeout', 2.0);

        $this->newLine();
        $this->line("  Sending a test event to {$endpoint} …");

        try {
            $response = Http::withToken((string) $config->get('prism.token'))
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                // Mark the probe as Prism's own so an active install's HTTP
                // capture (US-046) never records this diagnostic call.
                ->withHeaders([Recursion::MARKER_HEADER => '1'])
                ->post($endpoint, $this->testEnvelope($config));
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
            $this->components->error(
                'The ingest endpoint rejected the token (HTTP 401). Check PRISM_TOKEN — it may be '
                .'revoked, mistyped, or not an ingest-scoped token.'
            );
            $this->newLine();

            return self::FAILURE;
        }

        // Over quota (US-030): the endpoint is reachable and the token is valid,
        // but the workspace has exhausted its monthly event allowance so the
        // event was not stored. That is an operational state, not a wiring fault
        // — report it and succeed, so a deploy gate is not tripped by billing.
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
     * The minimal versioned envelope carrying one innocuous `log` event, in the
     * exact wire shape the server validates (US-026). A `log` is the least
     * intrusive signal to inject; its string `timestamp` and known type are what
     * make the server accept it.
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
