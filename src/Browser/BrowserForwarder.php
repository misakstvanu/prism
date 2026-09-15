<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Browser;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Http\Controllers\BrowserReportController;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Text;
use Misakstvanu\Prism\Support\Timestamp;

/**
 * Ships an accepted browser report to the workspace (US-007), enriched with what only the backend knows
 * (US-008) — the endpoint's second half ({@see BrowserReport} says what arrived,
 * {@see BrowserReportController} whether it is worth having). Survivors become ordinary Prism events: the
 * same {@see Flusher}, envelope, `prism.batch.flush` strategy, transport and `/api/ingest` every PHP signal
 * travels.
 *
 * **The batch is a private one — the reason this class exists rather than a call to `EventBuffer::add()`.**
 * The forwarding request is on `prism.ignore.paths` (US-005), so its execution is `dontSample()`d and
 * {@see PrismServiceProvider::discardRejectedExecution()} empties the shared buffer before the terminating
 * drain: right for everything the *host* did while handling the report, fatal for the report itself. So the
 * events go into a throwaway {@see EventBuffer} handed to the `Closure(EventBuffer): Flusher` factory —
 * {@see PrismIngest::writeNow()}'s shape and reason: a batch must survive an execution nobody is keeping.
 *
 * **Nothing on this path goes through {@see PrismIngest}**, deliberately: three of its stamps would be wrong.
 *   - `stampTraceId()` would rewrite every `trace_id` to the *forwarding request*'s. A browser event's trace
 *     is the page's — minted in the browser, propagated to the backend as a `traceparent` (US-019), and what
 *     correlates a JavaScript error with the API call it made.
 *   - `stampExecutionId()` would file browser events under the forwarding request's `request_id`, putting a
 *     browser error among the children of the request that reported it.
 *   - `RecordTranslator` translates the capture engine's vocabulary; a browser event is already in Prism's
 *     own — the SDK composes the column names directly, making a fourth signal a wire contract, not a
 *     mapping.
 *
 * **Four fields are the backend's answer, not the report's own**: this is an unauthenticated write from an
 * origin nobody controls, so each is a value the page cannot know or could set to anything.
 *   - `user_id` — the signed-in identity of the request that carried the report ({@see userId}), never the
 *     page's claim unless the host has explicitly said to trust it.
 *   - `context.ip` — the client address the server saw; a page cannot know its own public address without
 *     asking a third party.
 *   - `context.user_agent` (and a page view's own `user_agent` column) — the header, overwriting the SDK's
 *     `navigator.userAgent` copy; the two ordinarily agree, and the header is the half a page cannot edit.
 *   - `timestamp` — every event's instant, corrected against the report's own `sent_at` by
 *     {@see BrowserClock}: a device clock that is out lands rows in a day partition dropped on sight or never
 *     read.
 *
 * **And nothing leaves that `prism.scrub` said should not** (US-009). One list governs every signal a host
 * ships, so a browser error's page context, its breadcrumbs, a failed call's log line and a page view's
 * address meet the same two implementations every PHP signal meets; {@see BrowserScrubber} is only the map of
 * where they apply to a shape they have not seen, and runs after the enrichment deliberately: the list
 * promises what leaves the process, not what the page said. Application, environment and replica need nothing
 * here — they ride the batch envelope {@see Flusher} builds, as every PHP signal does, so a browser event
 * arrives scoped to the install that forwarded it.
 *
 * **A failure here is not swallowed**, unlike {@see PrismIngest::ship()}, where a throw would cost the host a
 * request doing real work and is logged at debug and dropped. Here the forwarding *is* the request: letting
 * the exception out costs a telemetry POST and buys a visible error — the host's own handler reports it,
 * `Core::report()` re-rolls the sampled-out execution against `sampling.exceptions` — which Prism pins at
 * 1.0 — so it flips to sampled-in, `discardRejectedExecution()` leaves its buffer alone and the exception
 * ships with the request row that raised it. A broken forwarder is never invisible, enrichment included: a
 * `prism.browser.guard` naming a guard the application does not define is a configuration mistake that would
 * make every browser row anonymous while answering 204 — one nobody would find.
 */
final class BrowserForwarder
{
    /**
     * The payload key naming which runtime produced a signal; every event forwarded from here carries it.
     * `exceptions` and `logs` hold both halves of an incident — one Errors screen and one Logs screen rather
     * than a second of each to check — and this column is which half a row is. Stamped **here**, never
     * trusted from the page: the SDK could send it, but so could anything else posting to this endpoint, and
     * a row claiming to be PHP would land among the backend's own.
     */
    private const RUNTIME_KEY = 'runtime';

    private const RUNTIME = 'browser';

    /**
     * The signals whose table has a `runtime` column and whose payload has a `context` object for the
     * request's own facts. `page_view` is deliberately absent from both: that table is the browser's own
     * execution and has neither column, so a stamped `runtime` would add a key
     * `input_format_skip_unknown_fields` drops on the way in, silently. What it does have is a `user_agent`
     * column of its own, filled in {@see payload} below.
     *
     * @var list<string>
     */
    private const RUNTIME_TYPES = ['exception', 'log'];

    /** The signal that is the browser's own execution rather than an event inside one. */
    private const PAGE_VIEW_TYPE = 'page_view';

    /**
     * The longest the enriched values may be, in bytes. All three are short by construction — an address, a
     * header a real client writes in about 150 characters, a primary key — so a long one is a client being
     * careless or hostile, not a value with meaning. Cut rather than refused, for the reason every other cap
     * in a browser report is.
     */
    private const IP_BYTES = 64;

    private const USER_AGENT_BYTES = 512;

    private const USER_ID_BYTES = 256;

    /**
     * @param  Closure(EventBuffer): Flusher  $flusher  Builds a flusher over a given buffer. A
     *                                                  factory, not one flusher, for the reason
     *                                                  above: this batch is never the shared
     *                                                  buffer's.
     */
    public function __construct(
        private readonly Closure $flusher,
        private readonly Repository $config,
        private readonly BrowserScrubber $scrub,
    ) {}

    /**
     * Ship every event a report was accepted with. An empty report costs nothing: the flusher would decline
     * an empty batch anyway, and not building one keeps the common case (a page whose one error was filtered
     * out) free — and keeps it from asking the auth guard who is signed in, which can be a query.
     *
     * All of it runs inside a {@see Recursion::suppress()} scope: building an envelope, spooling it and
     * POSTing it are a cache write, a queue dispatch and an outgoing HTTP call, and capturing those is
     * capturing capture; the scope also covers what the enrichment reads, since resolving the signed-in user
     * can be a query and a session read. The execution is discarded either way — this keeps that true on the
     * one path where it is not, the exception above that flips the execution back to sampled-in. Every
     * enriched value is read **once per report**, not once per event: they are properties of the request that
     * carried it, so per-event re-reads would be the same answer at a per-event price.
     */
    public function forward(BrowserReport $report, Request $request): void
    {
        if ($report->events === []) {
            return;
        }

        Recursion::suppress(function () use ($report, $request): void {
            // Carbon rather than a bare `new DateTimeImmutable`, so a test can move the clock a report is
            // corrected against; UTC because every instant in the pipeline is written in it, whatever the
            // host's `app.timezone` says.
            $clock = BrowserClock::correcting($report->sentAt, now()->utc()->toDateTimeImmutable());

            $userId = $this->userId($report);
            $ip = self::header((string) $request->ip(), self::IP_BYTES);
            $userAgent = self::header((string) $request->headers->get('User-Agent'), self::USER_AGENT_BYTES);

            // Unbounded: the report's own caps bound it (`prism.browser.max_events`, refused with a 413
            // before anything is built), and a batch of exactly what was accepted is the claim this endpoint
            // answers with.
            $batch = new EventBuffer(capacity: 0);

            foreach ($report->events as $event) {
                $batch->add($event->type, [
                    'type' => $event->type,
                    'timestamp' => Timestamp::fromDateTime($clock->correct($event->timestamp)),
                    'trace_id' => $event->traceId,
                    // Blank, and it stays blank: a browser event belongs to no backend execution. The
                    // forwarding request is one, but it carried the report rather than producing the error —
                    // filing the error under it would put a JavaScript fault among the children of the POST
                    // that told us about it. `trace_id` correlates a browser error with the backend work it
                    // caused, and is left exactly as the page minted it.
                    'request_id' => '',
                    'user_id' => $userId,
                    'payload' => $this->payload($event, $ip, $userAgent),
                ]);
            }

            ($this->flusher)($batch)->flush();
        });
    }

    /**
     * Who the backend says was looking, or null when it says nobody. **The guard is asked, not the page.** A
     * report is an unauthenticated write — the point of the endpoint, an anonymous visitor hitting a
     * JavaScript error being the report most worth having — so a body `user.id` is a value anyone can set to
     * anyone, and believing it would make the console's user attribution mean nothing at all. The request
     * that carried the report went through the host's `web` group with its session, so the identity that
     * matters is already resolved and is read.
     *
     * The page's hint is used in exactly one case, and only where the host turned it on: a
     * token-authenticated SPA whose `/_prism/browser` post carries no session has no server-side answer to be
     * had, and a hint beats nothing on an install that has already decided attribution is not something it
     * trusts. `prism.browser.trust_client_user` defaults to false and the guard's answer wins wherever there
     * is one, so turning it on can never *replace* a real identity with a claimed one. Read through the
     * {@see Auth} facade rather than an injected factory, deliberately: this class is a container singleton,
     * while a long-lived runtime replaces the auth manager between requests (Octane forgets the `auth`
     * instance on every one), so a factory captured at construction would answer with a guard belonging to a
     * request already sent; the facade resolves it per call and therefore follows.
     */
    private function userId(BrowserReport $report): ?string
    {
        /** @var mixed $guard */
        $guard = $this->config->get('prism.browser.guard');

        $id = Auth::guard(is_string($guard) && $guard !== '' ? $guard : null)->id();

        if ($id !== null) {
            $resolved = trim(Text::clean((string) $id));

            if ($resolved !== '') {
                return Text::truncate($resolved, self::USER_ID_BYTES);
            }
        }

        return (bool) $this->config->get('prism.browser.trust_client_user', false)
            ? $report->userId
            : null;
    }

    /**
     * One browser event's payload, with the facts the request carried written over whatever the page said
     * about them. An overwrite, not a merge: `ip` and `user_agent` describe the client and the request is
     * where a client is described truthfully — a page can put anything in its own copy, and a page in a bad
     * state (every page this endpoint hears from) can put nothing there at all. So the server's answer is
     * written unconditionally, blank included: a blank `user_agent` says the request carried no such header,
     * a fact about the client worth being able to see. The two keys written here sit outside
     * {@see BrowserEvent}'s context cap, deliberately: that cap bounds what a *page* can cost, these are
     * bounded by {@see header} below. The whole payload then goes through {@see BrowserScrubber}, the last
     * thing that happens to it before it is put in the batch (US-009).
     *
     * @return array<array-key, mixed>
     */
    private function payload(BrowserEvent $event, string $ip, string $userAgent): array
    {
        $payload = $event->payload;

        if ($event->type === self::PAGE_VIEW_TYPE) {
            // `page_views` names the user agent as a column of its own and has no `context` at all — and no
            // `ip` either, a page view being a visit rather than an incident, where the address is of no use.
            $payload['user_agent'] = $userAgent;
        } elseif (in_array($event->type, self::RUNTIME_TYPES, true)) {
            $payload[self::RUNTIME_KEY] = self::RUNTIME;

            /** @var array<array-key, mixed> $context */
            $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

            $context['ip'] = $ip;
            $context['user_agent'] = $userAgent;

            $payload['context'] = $context;
        }

        // Last, so `prism.scrub` governs everything that leaves rather than only what the page put there: the
        // two keys written above meet the list as the page's own copies would have.
        return $this->scrub->scrub($payload);
    }

    /**
     * A value the request supplied, made safe to insert — the one door into the pipeline a JSON parser has
     * not already walked through: a header is a byte string, so an old or hostile client can put a NUL or a
     * malformed UTF-8 sequence in one, and ClickHouse's row parser rejects either outright — the whole insert
     * rather than the offending row, on a queue, long after the 204 that accepted it. See {@see Text}.
     */
    private static function header(string $value, int $bytes): string
    {
        return Text::truncate(Text::clean($value), $bytes);
    }
}
