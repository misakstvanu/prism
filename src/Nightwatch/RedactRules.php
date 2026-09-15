<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Nightwatch;

use Illuminate\Contracts\Config\Repository;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Command;
use Laravel\Nightwatch\Records\Exception;
use Laravel\Nightwatch\Records\Mail;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Query;
use Laravel\Nightwatch\Records\Request;
use Misakstvanu\Prism\Otel\PrismSpanProcessor;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\Scrubber;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * `prism.scrub` said in the capture engine's vocabulary (US-011). The list and {@see Scrubber}'s verdict on a
 * name are unchanged, only the *where* moved: the old client's listeners are gone, so the list is translated
 * onto `laravel/nightwatch`'s typed record + `redact*` callback per signal. Unlike the reject callbacks
 * ({@see RejectRules}) these **always run** and **mutate rather than short-circuit**: nothing dropped, no
 * per-execution figure moved. Two halves, like every `nightwatch.*` derivation here:
 *   - **The config keys** `redact_payload_fields` / `redact_headers` from `prism.scrub`
 *     ({@see payloadFields()}, {@see headerNames()}), snapshotted into a `SensorManager` by upstream's request
 *     sensor during Nightwatch's `register()` — so in a real install, where `laravel/nightwatch` sorts first,
 *     the write alone is read by nobody and {@see PrismServiceProvider} pushes them onto the built sensor.
 *   - **The callbacks** below: everything that is not a request field, plus the request again from a seam
 *     no provider ordering can miss.
 *
 * Three shapes cover every signal: a **keyed structure** ({@see redactRequest()}, through the {@see Scrubber});
 * **a URL** ({@see redactUrl()} — a request's own and an outgoing call's, where only the query string can carry
 * a secret); and **free text holding `name = value` pairs** ({@see redactPairs()} — SQL, an artisan command
 * line, a mail subject, an exception message, a cache key, one implementation so all five share one rule and a
 * host adding a key to `prism.scrub` gets them all). Nightwatch's `queued-job` / `job-attempt` records carry no
 * payload (name, id, queue, connection, outcome), so a secret dispatched in a job reaches no record to redact
 * from — covered by absence, not a callback, and asserted as such: no `redactQueuedJobs()` upstream.
 */
final class RedactRules
{
    /**
     * Upstream's `redact_payload_fields` default, kept underneath Prism's list rather than replaced: a host
     * relying on `_token` being redacted did not ask to stop by installing Prism.
     *
     * @var list<string>
     */
    public const PAYLOAD_FIELDS = ['_token', 'password', 'password_confirmation'];

    /**
     * Upstream's `redact_headers` default, kept for the same reason plus the value-shape-aware treatment
     * upstream gives these four (an `Authorization` keeps its scheme, a `Cookie` its names).
     *
     * @var list<string>
     */
    public const HEADERS = ['Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN'];

    /**
     * Looks like a `name = value` pair but is not one: a SQL placeholder. `where password = ?` carries no
     * secret, and replacing the `?` takes the statement's shape off the query screen for nothing.
     */
    private const PLACEHOLDER = '/^[?:$@][\w]*$/';

    /**
     * The compiled `name = value` matcher, or null when the scrub list is empty. Built once, at boot: it is
     * consulted on the hot path — every query, every cache key — and the alternation is the whole scrub list.
     */
    private readonly ?string $pairPattern;

    /**
     * @param  Scrubber  $scrubber  The redactor for keyed structures.
     * @param  list<string>  $keys  The same list, flat, for the config keys
     *                              upstream reads and the free-text matcher.
     */
    public function __construct(
        private readonly Scrubber $scrubber,
        private readonly array $keys = [],
    ) {
        $this->pairPattern = $this->compilePairPattern($keys);
    }

    /**
     * Resolve the list once, at boot, so nothing re-reads config while a request is served —
     * {@see RejectRules::fromConfig()}'s stance. Builds its own {@see Scrubber} rather than taking the
     * container's: this runs during `register()`, before the singleton exists.
     */
    public static function fromConfig(Repository $config): self
    {
        $configured = $config->get('prism.scrub', []);

        $keys = [];

        foreach (is_iterable($configured) ? $configured : [] as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return new self(new Scrubber($keys), array_values(array_unique($keys)));
    }

    /**
     * `nightwatch.redact_payload_fields` — request body fields blanked by upstream's sensor as it serialises.
     *
     * @return list<string>
     */
    public function payloadFields(): array
    {
        return $this->union(self::PAYLOAD_FIELDS);
    }

    /**
     * `nightwatch.redact_headers` — header names blanked by upstream's sensor as it serialises them.
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        return $this->union(self::HEADERS);
    }

    /**
     * A request record: headers and body are keyed structures the {@see Scrubber} answers as it did the old
     * client's, including the case-insensitivity upstream's `in_array($key, $fields, true)` lacks; the URL
     * is rewritten because a token in a query string is a secret in the one field every screen prints.
     */
    public function redactRequest(Request $record): void
    {
        $this->redactHeaderBag($record->headers);

        /** @var array<array-key, mixed> $payload */
        $payload = $record->payload->all();

        $record->payload->replace($this->scrubber->scrub($payload));

        $record->url = $this->redactUrl($record->url);
    }

    /**
     * A query record's SQL. Nightwatch captures no bindings (the old client did, and scrubbed them), so
     * what is left is a value written into the statement itself — what a raw `DB::statement()` produces.
     * A `?` or `:name` placeholder is deliberately left alone.
     */
    public function redactQuery(Query $record): void
    {
        $record->sql = $this->redactPairs($record->sql);
    }

    /** An outgoing call: a credential passed in its query string. */
    public function redactOutgoingRequest(OutgoingRequest $record): void
    {
        $record->url = $this->redactUrl($record->url);
    }

    /**
     * A cache event's key, a `name:value` pair often enough for the free-text rule: `password-reset:<token>`
     * loses the token, keeps the name, stays legible while the secret does not leave. A key that is *only* a
     * name (`api_key`) has no value part and is left alone — a cache entry's name is not a secret.
     */
    public function redactCacheEvent(CacheEvent $record): void
    {
        $record->key = $this->redactPairs($record->key);
    }

    /** A mail record: its subject, which is the only free text it carries. */
    public function redactMail(Mail $record): void
    {
        $record->subject = $this->redactPairs($record->subject);
    }

    /** A command record's full command line, where a secret arrives as an option (`--password=hunter2`). */
    public function redactCommand(Command $record): void
    {
        $record->command = $this->redactPairs($record->command);
    }

    /**
     * An exception record's message. Safe to rewrite: nothing groups on it — upstream hashes `_group` from
     * class, code, file and line, Prism's fingerprint is the class plus the topmost application frame (US-029),
     * never the message — so redacting cannot move an error group, orphan an issue or undo a triage decision.
     */
    public function redactException(Exception $record): void
    {
        $record->message = $this->redactPairs($record->message);
    }

    /**
     * Replace the value of every sensitive header, in place. A `HeaderBag` lower-cases its own keys, so
     * this is case-insensitive on both sides. Upstream's own value-shape-aware redaction of the names
     * that reached `redact_headers` runs after this one and re-blanks a blank value.
     */
    private function redactHeaderBag(HeaderBag $headers): void
    {
        foreach (array_keys($headers->all()) as $name) {
            if (is_string($name) && $this->scrubber->isSensitive($name)) {
                $headers->set($name, Scrubber::REDACTED);
            }
        }
    }

    /**
     * The free-text rule, for a caller holding a string rather than a record: {@see PrismSpanProcessor}
     * (US-018). With the span lane owning the query signal, the SQL Prism ships is an OpenTelemetry span
     * attribute, not the `Query` record {@see redactQuery()} rewrites — a `prism.scrub` list that stopped
     * applying the day the lane landed would be the quietest way to start shipping credentials.
     */
    public function redactText(string $text): string
    {
        return $this->redactPairs($text);
    }

    /**
     * Rewrite a URL's query string, pair by pair. Public for {@see redactText()}'s reason: the span lane's
     * outgoing-call span carries `url.full`, which must meet the same list the `OutgoingRequest` record's URL
     * does. Deliberately not `parse_str()` + `http_build_query()`, whose round trip re-encodes every value,
     * collapses a repeated key into an array and drops a valueless flag, so a URL with nothing sensitive in
     * it still comes back changed; splitting on the separators leaves every untouched pair byte for byte.
     */
    public function redactUrl(string $url): string
    {
        $mark = strpos($url, '?');

        if ($mark === false) {
            return $url;
        }

        $query = substr($url, $mark + 1);
        $fragment = '';

        $hash = strpos($query, '#');

        if ($hash !== false) {
            $fragment = substr($query, $hash);
            $query = substr($query, 0, $hash);
        }

        if ($query === '') {
            return $url;
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            $split = strpos($pair, '=');

            if ($split === false) {
                $pairs[] = $pair;

                continue;
            }

            $name = substr($pair, 0, $split);

            $pairs[] = $this->scrubber->isSensitive(urldecode($name))
                ? $name.'='.rawurlencode(Scrubber::REDACTED)
                : $pair;
        }

        return substr($url, 0, $mark + 1).implode('&', $pairs).$fragment;
    }

    /**
     * Replace the value half of every `name = value` pair whose name is on the scrub list, wherever it appears
     * in free text — one implementation for five signals: `--password=hunter2` on a command line,
     * `password = 'hunter2'` in a statement, `token:abc` in a cache key. Separator `=`, `:` or `=>`; a quoted
     * value keeps its quotes so the text stays well-formed once the value inside is gone.
     */
    private function redactPairs(string $text): string
    {
        if ($this->pairPattern === null || $text === '') {
            return $text;
        }

        $redacted = preg_replace_callback(
            $this->pairPattern,
            static function (array $matches): string {
                $value = $matches[3];

                // A SQL placeholder is not a value; leaving it alone keeps the statement's shape readable.
                if (preg_match(self::PLACEHOLDER, $value) === 1) {
                    return $matches[0];
                }

                $quote = str_contains('\'"`', $value[0] ?? ' ') ? $value[0] : '';

                return $matches[1].$matches[2].$quote.Scrubber::REDACTED.$quote;
            },
            $text,
        );

        return $redacted ?? $text;
    }

    /**
     * Compile the scrub list into the pair matcher, null when nothing to match. The name may wear a `--`
     * (artisan option) or a quote (serialised body); the lookarounds keep `password` from matching inside
     * `password_strength` — the over-redaction the {@see Scrubber} avoids by comparing whole keys.
     *
     * @param  list<string>  $keys
     */
    private function compilePairPattern(array $keys): ?string
    {
        if ($keys === []) {
            return null;
        }

        $names = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            $keys,
        ));

        return '/(?<![\w-])((?:--)?(?:'.$names.'))(?![\w-])(["\']?\s*(?:=>|=|:)\s*)'
            .'(\'[^\']*\'|"[^"]*"|`[^`]*`|[^\s,;)\]}]+)/i';
    }

    /**
     * Upstream's own defaults with `prism.scrub` on top, de-duplicated case-insensitively so a host
     * repeating `password` does not send it twice.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function union(array $defaults): array
    {
        $merged = [];

        foreach ([...$defaults, ...$this->keys] as $key) {
            $merged[strtolower($key)] ??= $key;
        }

        return array_values($merged);
    }
}
