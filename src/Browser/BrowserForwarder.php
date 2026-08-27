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
use Misakstvanu\Prism\Support\Timestamp;

/**
 * Ships an accepted browser report to the workspace (US-007), enriched with
 * what only the backend knows (US-008).
 *
 * This is the second half of the endpoint: {@see BrowserReport} says what
 * arrived and {@see BrowserReportController} says whether it is worth having,
 * and this turns what survived into ordinary Prism events and puts them on the
 * wire. Nothing below it is new — the same {@see Flusher}, the same envelope,
 * the same `prism.batch.flush` strategy, the same transport and the same
 * `/api/ingest` every PHP signal has always travelled.
 *
 * **The batch is a private one, and that is the whole reason this class exists
 * rather than a call to `EventBuffer::add()`.** The forwarding request is on
 * `prism.ignore.paths` (US-005), so its execution is `dontSample()`d and
 * {@see PrismServiceProvider::discardRejectedExecution()} empties the shared
 * buffer before the terminating drain — which is correct for everything the
 * *host* did while handling the report and fatal for the report itself. So the
 * events go into a throwaway {@see EventBuffer} handed to the
 * `Closure(EventBuffer): Flusher` factory, exactly the shape
 * {@see PrismIngest::writeNow()} uses and for exactly the same reason: a batch
 * that has to survive an execution nobody is keeping.
 *
 * **Nothing on this path goes through {@see PrismIngest}**, and that is
 * deliberate rather than an omission. Three of its stamps would be wrong here:
 *
 *   - `stampTraceId()` would rewrite every event's `trace_id` to the trace of
 *     the *forwarding request*. A browser event's trace is the page's, minted
 *     in the browser and propagated to the backend as a `traceparent` (US-019)
 *     — it is what correlates a JavaScript error with the API call it made, and
 *     the POST carrying the report is not part of that story.
 *   - `stampExecutionId()` would file every browser event under the forwarding
 *     request's `request_id`, so a browser error would appear among the
 *     children of the request that reported it.
 *   - `RecordTranslator` translates the capture engine's vocabulary, and a
 *     browser event is already written in Prism's own — the SDK composes the
 *     column names directly, which is what makes a fourth signal a wire
 *     contract rather than a mapping.
 *
 * **What the report says about itself is not what is shipped about it.** Four
 * fields are the backend's answer rather than the page's, because the report is
 * an unauthenticated write from an origin nobody controls and each of these is
 * a value the page either cannot know or could set to anything:
 *
 *   - `user_id` — the signed-in identity of the request that carried the report
 *     ({@see userId}), never the page's claim unless the host has explicitly
 *     said to trust it.
 *   - `context.ip` — the client address the server saw. A page cannot know its
 *     own public address without asking a third party.
 *   - `context.user_agent` (and a page view's own `user_agent` column) — the
 *     header, overwriting the SDK's copy of `navigator.userAgent`. The two
 *     ordinarily agree; when they do not, the one that travelled with the
 *     request is the one that cannot be edited by the page.
 *   - `timestamp` — every event's instant, corrected against the report's own
 *     `sent_at` by {@see BrowserClock}, because a device clock that is out lands
 *     rows in a day partition that is dropped on sight or never read.
 *
 * **And nothing leaves that `prism.scrub` said should not** (US-009). One list
 * governs every signal a host ships, so a browser error's page context, its
 * breadcrumbs, a failed call's log line and a page view's address all meet the
 * same two implementations every PHP signal meets — {@see BrowserScrubber} is
 * only the map of where they apply to a shape they have not seen. It runs after
 * the enrichment above, deliberately: the list is a promise about what leaves
 * the process, not about what the page said.
 *
 * The three dimensions a workspace narrows by — the application, the environment
 * and the replica — need nothing here: they ride the batch envelope
 * {@see Flusher} builds, exactly as they do for every PHP signal, so a browser
 * event arrives already scoped to the install that forwarded it.
 *
 * **A failure here is not swallowed**, which is the other place this differs
 * from {@see PrismIngest::ship()}. There, a throw would cost the host a request
 * it was doing real work in, so it is logged at debug and dropped. Here the
 * forwarding *is* the request: there is nothing else it was for, so letting the
 * exception out costs a telemetry POST and buys a visible error. It is reported
 * by the host's own handler, and `Core::report()` re-rolls a sampled-out
 * execution against `sampling.exceptions` — which Prism pins at 1.0 — so the
 * execution flips to sampled-in, `discardRejectedExecution()` leaves its buffer
 * alone and the exception ships together with the request row that raised it. A
 * broken forwarder is never invisible. That covers the enrichment too: a
 * `prism.browser.guard` naming a guard the application does not define is a
 * configuration mistake, and a mistake that made every browser row anonymous
 * while answering 204 would be one nobody found.
 */
final class BrowserForwarder
{
    /**
     * The payload key that says which runtime produced a signal, and the value
     * every event forwarded from here carries.
     *
     * `exceptions` and `logs` hold both halves of an incident — one Errors
     * screen and one Logs screen rather than a second of each to check — and
     * this column is which half a row is. It is stamped **here** rather than
     * trusted from the page: the SDK could send it, but so could anything else
     * posting to this endpoint, and a row claiming to be PHP would land among
     * the backend's own.
     */
    private const RUNTIME_KEY = 'runtime';

    private const RUNTIME = 'browser';

    /**
     * The signals whose table has a `runtime` column, and whose payload has a
     * `context` object to put the request's own facts in.
     *
     * `page_view` is deliberately absent from both: that table is the browser's
     * own execution, so it has neither column — stamping a `runtime` would add a
     * key `input_format_skip_unknown_fields` drops on the way in, silently,
     * which is the worst kind of harmless. What it does have is a `user_agent`
     * column of its own, filled in {@see payload} below.
     *
     * @var list<string>
     */
    private const RUNTIME_TYPES = ['exception', 'log'];

    /** The signal that is the browser's own execution rather than an event inside one. */
    private const PAGE_VIEW_TYPE = 'page_view';

    /**
     * The longest the enriched values may be, in bytes.
     *
     * All three are short by construction — an address, a header a real client
     * writes in about 150 characters, a primary key — so a long one is a client
     * being careless or hostile rather than a value with meaning. Cut rather
     * than refused, for the reason every other cap in a browser report is.
     */
    private const IP_BYTES = 64;

    private const USER_AGENT_BYTES = 512;

    private const USER_ID_BYTES = 256;

    /**
     * @param  Closure(EventBuffer): Flusher  $flusher  Builds a flusher over a given buffer. A
     *                                                  factory rather than one flusher for the
     *                                                  reason above: the batch this ships is
     *                                                  never the shared buffer's.
     */
    public function __construct(
        private readonly Closure $flusher,
        private readonly Repository $config,
        private readonly BrowserScrubber $scrub,
    ) {}

    /**
     * Ship every event a report was accepted with.
     *
     * A report with nothing in it costs nothing — an empty batch would be
     * declined by the flusher anyway, and not building one keeps the common
     * case (a page that reported one error and had it filtered out) free. It is
     * also what keeps an empty report from asking the auth guard who is signed
     * in, which can be a query.
     *
     * The whole of it runs inside a {@see Recursion::suppress()} scope. The
     * shipping itself is the obvious reason — building an envelope, spooling it
     * and POSTing it are a cache write, a queue dispatch and an outgoing HTTP
     * call, and capturing those is capturing capture — but it also covers
     * everything the enrichment reads: resolving the signed-in user can be a
     * query and a session read. The execution is discarded either way; this is
     * what keeps that true on the one path where it is not, the exception above
     * that flips the execution back to sampled-in.
     *
     * Every enriched value is read **once per report**, not once per event: they
     * are properties of the request that carried it, so re-reading them per
     * event would be the same answer at a per-event price.
     */
    public function forward(BrowserReport $report, Request $request): void
    {
        if ($report->events === []) {
            return;
        }

        Recursion::suppress(function () use ($report, $request): void {
            // Carbon rather than a bare `new DateTimeImmutable`, so the clock a
            // report is corrected against is one a test can move; UTC because
            // that is the timezone every instant in the pipeline is written in,
            // whatever the host's own `app.timezone` says.
            $clock = BrowserClock::correcting($report->sentAt, now()->utc()->toDateTimeImmutable());

            $userId = $this->userId($report);
            $ip = self::header((string) $request->ip(), self::IP_BYTES);
            $userAgent = self::header((string) $request->headers->get('User-Agent'), self::USER_AGENT_BYTES);

            // Unbounded, because the report's own caps (`prism.browser.max_events`,
            // refused with a 413 before anything is built) are what bound it —
            // and because a batch of exactly what was accepted is the claim this
            // endpoint answers with.
            $batch = new EventBuffer(capacity: 0);

            foreach ($report->events as $event) {
                $batch->add($event->type, [
                    'type' => $event->type,
                    'timestamp' => Timestamp::fromDateTime($clock->correct($event->timestamp)),
                    'trace_id' => $event->traceId,
                    // Blank, and it stays blank: a browser event belongs to no
                    // backend execution. The forwarding request is one, but it
                    // is the request that carried the report rather than the one
                    // that produced the error — filing the error under it would
                    // put a JavaScript fault among the children of the POST that
                    // told us about it. What correlates a browser error with the
                    // backend work it caused is `trace_id`, which the page
                    // minted and this leaves exactly as it arrived.
                    'request_id' => '',
                    'user_id' => $userId,
                    'payload' => $this->payload($event, $ip, $userAgent),
                ]);
            }

            ($this->flusher)($batch)->flush();
        });
    }

    /**
     * Who the backend says was looking, or null when it says nobody.
     *
     * **The guard is asked, not the page.** A report is an unauthenticated write
     * — that is the point of the endpoint, since an anonymous visitor hitting a
     * JavaScript error is the report most worth having — so a `user.id` in the
     * body is a value anyone can set to anyone, and believing it would make the
     * console's user attribution mean nothing at all. The request that carried
     * the report travelled through the host's `web` group with its session, so
     * the identity that matters is already resolved and is simply read.
     *
     * The page's hint is used in exactly one case, and only where the host has
     * turned it on: a token-authenticated SPA whose `/_prism/browser` post
     * carries no session has no server-side answer to be had, and a hint is
     * better than nothing on an install that has already decided attribution is
     * not something it trusts. `prism.browser.trust_client_user` defaults to
     * false, and the guard's answer wins wherever there is one, so turning it on
     * can never *replace* a real identity with a claimed one.
     *
     * Read through the {@see Auth} facade rather than an injected factory
     * deliberately: this class is a container singleton, while a long-lived
     * runtime replaces the auth manager between requests (Octane forgets the
     * `auth` instance on every one), so a factory captured at construction would
     * answer with a guard belonging to a request that has already been sent.
     * The facade resolves it per call and therefore follows.
     */
    private function userId(BrowserReport $report): ?string
    {
        /** @var mixed $guard */
        $guard = $this->config->get('prism.browser.guard');

        $id = Auth::guard(is_string($guard) && $guard !== '' ? $guard : null)->id();

        if ($id !== null) {
            $resolved = trim(BrowserText::clean((string) $id));

            if ($resolved !== '') {
                return BrowserText::truncate($resolved, self::USER_ID_BYTES);
            }
        }

        return (bool) $this->config->get('prism.browser.trust_client_user', false)
            ? $report->userId
            : null;
    }

    /**
     * One browser event's payload, with the facts the request carried written
     * over whatever the page said about them.
     *
     * The overwrite is the point rather than a merge: `ip` and `user_agent`
     * describe the client, and the request is where a client is described
     * truthfully. A page can put anything in its own copy, and a page in a bad
     * state — which is every page this endpoint hears from — can put nothing
     * there at all. So the server's answer is written unconditionally, blank
     * included: a blank `user_agent` says the request carried no such header,
     * which is a fact about the client and is the kind of thing worth being able
     * to see.
     *
     * The two keys written here sit outside {@see BrowserEvent}'s context cap,
     * which is deliberate: that cap bounds what a *page* can cost, while these
     * are bounded by {@see header} below.
     *
     * The whole payload then goes through {@see BrowserScrubber}, which is the
     * last thing that happens to it before it is put in the batch (US-009).
     *
     * @return array<array-key, mixed>
     */
    private function payload(BrowserEvent $event, string $ip, string $userAgent): array
    {
        $payload = $event->payload;

        if ($event->type === self::PAGE_VIEW_TYPE) {
            // `page_views` names the user agent as a column of its own and has
            // no `context` at all — and no `ip` either, because a page view is
            // a visit rather than an incident and the address is of no use in
            // reading one.
            $payload['user_agent'] = $userAgent;
        } elseif (in_array($event->type, self::RUNTIME_TYPES, true)) {
            $payload[self::RUNTIME_KEY] = self::RUNTIME;

            /** @var array<array-key, mixed> $context */
            $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

            $context['ip'] = $ip;
            $context['user_agent'] = $userAgent;

            $payload['context'] = $context;
        }

        // Last, so `prism.scrub` governs everything that leaves rather than only
        // what the page put there — the two keys written above meet the list
        // exactly as the page's own copies of them would have.
        return $this->scrub->scrub($payload);
    }

    /**
     * A value the request supplied, made safe to insert.
     *
     * This is the one door into the pipeline that a JSON parser has not already
     * walked through: a header is a byte string, so an old or hostile client can
     * put a NUL or a malformed UTF-8 sequence in one, and ClickHouse's row
     * parser rejects either outright — rejecting the whole insert rather than
     * the offending row, on a queue, long after the 204 that accepted it. See
     * {@see BrowserText}.
     */
    private static function header(string $value, int $bytes): string
    {
        return BrowserText::truncate(BrowserText::clean($value), $bytes);
    }
}
