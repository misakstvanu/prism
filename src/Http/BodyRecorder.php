<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Misakstvanu\Prism\Nightwatch\PrismIngest;
use Misakstvanu\Prism\Nightwatch\RedactRules;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Support\Text;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The request and response bodies of the execution being recorded, and its request headers.
 *
 * The capture engine answers none of it: Nightwatch's request record serialises a `payload` only on a
 * **500**, only for a supported content type, into no Prism column, and models no response body at all
 * (`RequestRecord` carries `responseSize`, an integer, nothing else). {@see Middleware\CaptureHttpBodies}
 * captures, this holds — a **holder, not a listener**, filled with the response in hand and read back by
 * {@see PrismIngest} when the engine writes its `request` record from `terminate()`, long after the stack
 * unwound. A singleton, not scoped, for the event buffer's reason (a long-lived runtime — Octane, a queue
 * worker — keeps the container between executions), so safety comes from {@see reset()} at the *start* of
 * every request, not from discard at the end; a worker never runs the middleware, so never has a body to
 * report (a job is not an HTTP exchange). Four rules then decide what is recorded, each a way of not lying:
 *   - A JSON or form body is decoded, walked by key by the {@see Scrubber} — the same `prism.scrub` list
 *     that redacts a header or an outgoing query string — and re-encoded; raw bytes would mean scrubbing a
 *     secret out of text with a regex, the weaker rule.
 *   - Anything else is free text: a `text/plain` or XML body has no keys to walk, so it meets
 *     {@see RedactRules::redactText()}, the `name = value` matcher a SQL statement and an artisan command
 *     line also meet, so a key added to `prism.scrub` covers all of them.
 *   - A content type nobody can read is not recorded: `prism.request.body_content_types` keeps a rendered
 *     image, a PDF export, a gzip stream and, by default, rendered HTML out of a `String` column.
 *   - A body is capped in bytes and says when it was cut: {@see Text::truncate()} never cuts mid-character,
 *     and the appended marker stops a reader seeing a JSON document that merely *looks* malformed.
 *
 * Uploaded **file contents are never recorded**: a multipart request is recorded as its non-file fields,
 * the files named, sized and typed in a `_files` entry beside them — a body silently omitting the half of
 * the request that mattered would be worse than one saying what it left out. **The request headers are
 * captured here too, and on every request**: the 500-only rule governs the engine's *payload*, never its
 * headers, and a 200 addressed wrongly is exactly the case a header bag answers. The engine does serialise
 * a bag onto its own record, but words a redaction `[47 bytes redacted]` against the `[REDACTED]` every
 * other value Prism stores says — one console, two vocabularies for one act — and blanks only the names it
 * was handed rather than walking the bag by key, so the bag is read here through the same {@see Scrubber}
 * a JSON body meets, into the `request_headers` column beside the two bodies.
 */
final class BodyRecorder
{
    /**
     * Appended to a body the cap cut, so a reader knows the document is partial. {@see Text::TRUNCATED} is
     * the one definition (a job payload is cut the same way); kept under this name because callers ask for it.
     */
    public const TRUNCATED = Text::TRUNCATED;

    /**
     * The key an uploaded file's metadata is recorded under, inside the recorded body. Prefixed
     * like upstream's own `_nightwatch_files` so it cannot be mistaken for a field the client sent.
     */
    private const FILES_KEY = '_prism_files';

    /**
     * Content types whose bodies are worth recording, when the host has not said otherwise. Matched on
     * the media type alone (parameters such as `charset=` ignored) with `+json` / `+xml` suffixes
     * recognised, so `application/problem+json` is a JSON body. `text/html` is deliberately **absent**:
     * the rendered page is the largest and least diagnostic thing an application produces, and 64 KB of
     * markup per request would price the whole feature out. A host that wants it adds it.
     *
     * @var list<string>
     */
    public const CONTENT_TYPES = [
        'application/json',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'application/xml',
        'text/xml',
        'text/plain',
    ];

    /** The recorded request headers as JSON, or '' when they were not recorded. */
    private string $requestHeaders = '';

    /** The recorded request body, or '' when there was none to record. */
    private string $requestBody = '';

    /** The recorded response body, or '' when there was none to record. */
    private string $responseBody = '';

    public function __construct(
        private readonly Repository $config,
        private readonly Scrubber $scrubber,
        private readonly RedactRules $redact,
    ) {}

    /**
     * Forget the previous execution's bodies at the *start* of a request, not the end: one dying mid-stack
     * before {@see record()} leaves nothing for the next to report as its own in a long-lived runtime.
     */
    public function reset(): void
    {
        $this->requestHeaders = '';
        $this->requestBody = '';
        $this->responseBody = '';
    }

    /**
     * Record what this exchange carried, subject to the three switches, the content-type allow list and
     * the byte cap. Every step is guarded — reading a body touches a stream, decodes client-supplied bytes
     * and encodes what came back, none of it worth a host's request — and a failure leaves the body empty,
     * reading as the honest "nothing was captured".
     */
    public function record(Request $request, Response $response): void
    {
        if ($this->enabled('capture_headers')) {
            $this->requestHeaders = $this->guard(fn (): string => $this->readHeaders($request));
        }

        if ($this->enabled('capture_body')) {
            $this->requestBody = $this->guard(fn (): string => $this->readRequest($request));
        }

        if ($this->enabled('capture_response')) {
            $this->responseBody = $this->guard(fn (): string => $this->readResponse($response));
        }
    }

    /**
     * What was recorded, as the `requests` columns name them, or null when nothing was — null rather than
     * blanks so the ingest leaves an execution that is not an HTTP request (a command, a job) alone instead
     * of stamping three blanks onto every one; it drops an empty value key by key, so a request that
     * recorded headers and no body stamps only the headers.
     *
     * @return array{request_headers: string, request_body: string, response_body: string}|null
     */
    public function captured(): ?array
    {
        if ($this->requestHeaders === '' && $this->requestBody === '' && $this->responseBody === '') {
            return null;
        }

        return [
            'request_headers' => $this->requestHeaders,
            'request_body' => $this->requestBody,
            'response_body' => $this->responseBody,
        ];
    }

    /**
     * Headers as scrubbed JSON. Symfony lower-cases every name and lists every value, since a header may
     * legally repeat; a one-value name is flattened back to a string (one-element arrays read as a
     * machine's header bag, not a reader's), a repeated one keeps its list, so nothing is silently dropped.
     * The {@see Scrubber} then walks it by key — why it is read here, not off the engine's own record:
     * `cookie` and `authorization` are keys, meeting the same `prism.scrub` list and `[REDACTED]` wording
     * as a JSON body's `password` field. The cap is the bodies': nothing reaching a String column may be
     * unbounded.
     */
    private function readHeaders(Request $request): string
    {
        /** @var array<string, mixed> $bag */
        $bag = $request->headers->all();

        $headers = [];

        foreach ($bag as $name => $values) {
            $headers[$name] = is_array($values) && count($values) === 1
                ? reset($values)
                : $values;
        }

        if ($headers === []) {
            return '';
        }

        return $this->cap($this->encode($this->scrubber->scrub($headers)));
    }

    /**
     * The request body: its parsed fields where it has any, its raw text where it does not.
     * `$request->request` is the POST bag, which Laravel points at the decoded JSON document for a JSON
     * request (`Request::createFromBase()`), so one read covers both shapes a client sends structured data
     * in and buys the strong scrub: the fields are keys before they are text.
     */
    private function readRequest(Request $request): string
    {
        if (! $this->recordable($request->headers->get('content-type'))) {
            return '';
        }

        /** @var array<array-key, mixed> $fields */
        $fields = $request->request->all();
        $files = $this->files($request);

        if ($fields !== [] || $files !== []) {
            $body = $this->scrubber->scrub($fields);

            if ($files !== []) {
                $body[self::FILES_KEY] = $files;
            }

            return $this->cap($this->encode($body));
        }

        $content = $request->getContent();

        return is_string($content) ? $this->cap($this->redact->redactText($content)) : '';
    }

    /**
     * The response body. A streamed response has no content to read (a callback produces its body while it
     * is being sent, after every middleware has returned) and a file response's content is the file, which
     * this must never put in a column; both answer '' rather than something that merely looks like a body.
     */
    private function readResponse(Response $response): string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return '';
        }

        if (! $this->recordable($response->headers->get('content-type'))) {
            return '';
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return '';
        }

        return $this->cap($this->redactBody($content));
    }

    /**
     * A response body meets the keyed rule when JSON and the free-text rule when not. The decode buys the
     * strong scrub on the way *out* as well as in: an API answering `{"token": "…"}` has a key to match,
     * where a regex over the serialised document answers a weaker question about the same bytes. A body
     * that does not decode to a structure (a bare JSON scalar, malformed output, plain text) falls back,
     * never dropped.
     */
    private function redactBody(string $content): string
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $this->encode($this->scrubber->scrub($decoded));
        }

        return $this->redact->redactText($content);
    }

    /**
     * Uploaded files as name, size and client-reported type — never contents. A field may hold many files
     * (`documents[]`) and a nested one may hold more, so this walks rather than reading the first: an
     * upload silently left out is the half of the request a reader most needs to know arrived.
     *
     * @param  array<array-key, mixed>|null  $files
     * @return array<string, array{name: string, size: int, type: string}>
     */
    private function files(Request $request, ?array $files = null, string $prefix = ''): array
    {
        $named = [];

        /** @var array<array-key, mixed> $files */
        $files ??= $request->allFiles();

        foreach ($files as $field => $file) {
            $key = $prefix === '' ? (string) $field : $prefix.'.'.$field;

            if (is_array($file)) {
                $named = [...$named, ...$this->files($request, $file, $key)];

                continue;
            }

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $named[$key] = [
                'name' => Text::clean((string) $file->getClientOriginalName()),
                'size' => (int) $file->getSize(),
                'type' => Text::clean((string) $file->getClientMimeType()),
            ];
        }

        return $named;
    }

    /**
     * Whether a content type is one whose body is worth a column. The media type alone is compared
     * (`application/json; charset=utf-8` is `application/json`) and a `+json` / `+xml` suffix resolves to
     * its base type, so a vendor media type is recognised without the host listing every one it uses. A
     * request that declared no type at all (a GET, most beacons) has no body worth reading and is refused
     * here rather than further down, keeping `getContent()` off the hot path for most requests.
     */
    private function recordable(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }

        $media = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($media === '') {
            return false;
        }

        if (str_ends_with($media, '+json')) {
            $media = 'application/json';
        } elseif (str_ends_with($media, '+xml')) {
            $media = 'application/xml';
        }

        return in_array($media, $this->contentTypes(), true);
    }

    /**
     * The allow list, lower-cased. A host that replaces it replaces it whole — the config merge is
     * shallow, the rule every other list in `config/prism.php` follows.
     *
     * @return list<string>
     */
    private function contentTypes(): array
    {
        $configured = $this->config->get('prism.request.body_content_types', self::CONTENT_TYPES);

        $types = [];

        foreach (is_iterable($configured) ? $configured : [] as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = strtolower(trim($type));
            }
        }

        return $types === [] ? self::CONTENT_TYPES : array_values(array_unique($types));
    }

    /** One of the two capture switches, read per request so config:cache is the only cache. */
    private function enabled(string $key): bool
    {
        return (bool) $this->config->get('prism.request.'.$key, true);
    }

    /**
     * Make a string safe to insert, and bound its cost. {@see Text::clean} first: the cap is in bytes, so
     * the cut must happen on bytes that are already valid UTF-8. The marker is appended after the cut, not
     * budgeted inside it, so the cap caps the *body* and reads as the number the host configured.
     */
    private function cap(string $value): string
    {
        $value = Text::clean($value);

        if ($value === '') {
            return '';
        }

        $max = $this->maxBytes();

        if ($max <= 0 || strlen($value) <= $max) {
            return $value;
        }

        return Text::truncate($value, $max).self::TRUNCATED;
    }

    /** `prism.request.max_body`, in bytes. `0` or less means no cap. */
    private function maxBytes(): int
    {
        $max = $this->config->get('prism.request.max_body', 65536);

        return is_numeric($max) ? (int) $max : 65536;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function encode(array $body): string
    {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($json) ? $json : '';
    }

    /**
     * @param  callable(): string  $read
     */
    private function guard(callable $read): string
    {
        try {
            return $read();
        } catch (Throwable) {
            return '';
        }
    }
}
