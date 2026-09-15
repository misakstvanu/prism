<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Nightwatch;

use BackedEnum;
use JsonSerializable;
use Misakstvanu\Prism\Metrics\QueueMetrics;
use Misakstvanu\Prism\Metrics\SystemMetrics;
use Misakstvanu\Prism\Support\Text;
use Misakstvanu\Prism\Support\Timestamp;
use Throwable;

/**
 * One Laravel Nightwatch record as one Prism telemetry event — the whole seam between the two engines, so the
 * capture engine can be replaced without touching the buffer, flusher, transport, ingest endpoint or a single
 * ClickHouse table. Its sensors emit a flat array per signal (a *record*) to whatever implements
 * `Contracts\Ingest`; Prism's wire format is the versioned envelope the server has always validated (US-026),
 * `{ v: 1, app, env, replica, sent_at, events: [ { type, timestamp, trace_id, request_id, user_id, payload }
 * ] }`. Pure — the record array, no config, container or clock — so every mapping is assertable from a
 * fixture; ambient knowledge (the execution's start for a span's `offset_ms`, the active OTel span for its
 * lineage, the application and environment names) is the ingest's to stamp.
 *
 *   - **The envelope is four keys, the four the server correlates on** — `timestamp`, `trace_id`, `user_id`,
 *     `request_id`, off the record's globals. *Everything else travels in `payload`*, spread over the
 *     signal's columns with the remainder dropped (`input_format_skip_unknown_fields`); a field with no
 *     column today is a column US-006/US-007 can add with no client change.
 *   - **Prism's column vocabulary wins over Nightwatch's field names**: the mappers restate `duration_ms` not
 *     `duration`, `path` not `url`, `job_class` not `name`, merged *over* the passthrough, so the cache
 *     record's `type` (the operation) colliding with `spans.type` (the lane) resolves for the column.
 *   - **Units convert at the seam**: Nightwatch durations are microseconds and memory figures bytes against
 *     Prism's milliseconds and megabytes; unconverted, a record reads as a thousand-fold outlier on a chart,
 *     which looks like a real incident.
 *
 * An unrecognised record returns `null` — dropped and counted by the caller, never fatal: a Nightwatch signal
 * with no Prism counterpart (`user`, an identity upsert, not a telemetry event), or a record whose shape
 * version has moved on underneath us.
 */
final class RecordTranslator
{
    /**
     * Nightwatch record type (`t`) → Prism telemetry type: the singular signal name the server's ingest
     * routes on, naming its table. Deliberately written out, not referenced against the server's enum — the
     * client package must stand alone, and pint's `fully_qualified_strict_types` fixer turns a docblock
     * `{@see}` into a real `use` of host code. {@see normalizeType()} normalises keys first, so the sensors'
     * hyphenated `job-attempt` and the specs' underscored `job_attempt` are one key. Two types fold onto
     * `job` (queued, then attempted — one `jobs` table for both halves), each stamping a `phase` into its
     * payload for which half; two fold onto `span` — a cache event because OTel emits no cache span at all,
     * an outgoing request because that is the lane Prism has always drawn it in — both given identity and
     * lineage by the ingest.
     *
     * **`replica_metric` is the one entry with no Nightwatch sensor behind it** (US-013): Nightwatch has no
     * notion of the host a process runs on — its `Sensors` directory has neither a system sensor nor a queue
     * sensor — so CPU, memory, uptime and queue depth have no upstream record to translate from. Prism
     * collects them itself ({@see SystemMetrics}, {@see QueueMetrics}) and hands them to the ingest in the
     * engine's record shape: exactly one road from a capture to the wire, not a private side door for Prism's
     * own signals. No per-type mapper either — a record Prism raised is already in Prism's column vocabulary
     * and the passthrough carries it unchanged.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'request' => 'request',
        'exception' => 'exception',
        'log' => 'log',
        'query' => 'query',
        'job_attempt' => 'job',
        'queued_job' => 'job',
        'scheduled_task' => 'schedule',
        'command' => 'command',
        'mail' => 'mail',
        'notification' => 'notification',
        'cache_event' => 'span',
        'outgoing_request' => 'span',
        'replica_metric' => 'replica_metric',
    ];

    /**
     * The record-shape version this translator was written against, **per record type**: Nightwatch versions
     * each shape independently and not all are at 1 — an exception record is at `v: 3` and every other at `v:
     * 1`, so a flat `v === 1` gate (the obvious reading) would drop every exception the host ever reports,
     * silently and forever, the single most expensive signal to lose. A version past what is listed here is
     * dropped rather than mis-mapped: a shape we have not read is one we cannot claim to understand. US-022
     * pins these numbers against the upstream source so a `composer update` that bumps one fails loudly
     * instead of quietly emptying a screen.
     *
     * @var array<string, int>
     */
    private const VERSIONS = [
        'request' => 1,
        'exception' => 3,
        'log' => 1,
        'query' => 1,
        'job_attempt' => 1,
        'queued_job' => 1,
        'scheduled_task' => 1,
        'command' => 1,
        'mail' => 1,
        'notification' => 1,
        'cache_event' => 1,
        'outgoing_request' => 1,

        // Prism's own record, so this version is Prism's to move and US-022 has no upstream constant to pin
        // it against.
        'replica_metric' => 1,
    ];

    /**
     * Record keys the Prism envelope consumes: lifted to the top of the event, not repeated inside `payload`,
     * since the server reads correlation off the envelope and a second copy under another name is a second
     * thing to keep true. *Not* here: `deploy`, `server`, `_group`, `execution_source`, `execution_stage` and
     * `execution_preview` all ride the payload — the envelope has no dimension for them, and the payload is
     * where a field with no column belongs.
     *
     * @var list<string>
     */
    private const ENVELOPE_KEYS = ['v', 't', 'timestamp', 'trace_id', 'user', 'execution_id'];

    /**
     * Record keys a per-type mapper has fully restated, which must therefore not also ride the passthrough.
     * The passthrough is deliberately generous — a field with no column today is a column a later story can
     * add with no client change — but a field *rewritten* into Prism's vocabulary shipped beside its
     * restatement pays for the same information twice, and an exception's `trace` is by far the largest field
     * any record carries (the whole stack, up to ten frames of captured source). It becomes `frames` and
     * never travels twice.
     *
     * @var array<string, list<string>>
     */
    private const CONSUMED = [
        'exception' => ['trace'],
    ];

    /**
     * File-path segments marking a frame as library code rather than the application's own: the same two the
     * server's fingerprint skips on, and why every frame path leaves this seam with its project root marked
     * by a leading slash ({@see framePath()}).
     *
     * @var list<string>
     */
    private const VENDOR_SEGMENTS = ['/vendor/', '/node_modules/'];

    /** How deep {@see normalizeValue()} will walk a nested record value. */
    private const MAX_DEPTH = 16;

    /**
     * How many bytes of a command line are kept — the one unbounded string a `command` record carries:
     * upstream builds it from the invocation itself (the raw argv tokens of a real CLI run) and caps it
     * nowhere, and `commands.command` is a plain `String` column with no cap either, so an artisan command
     * invoked with a document as an argument would ride the wire and land in ClickHouse whole, once per run.
     * 4 KB is far past any line a person types and far short of anything worth paying for; the marker is
     * appended after the cut, so a truncated line says so rather than merely looking like a shorter
     * invocation.
     */
    private const MAX_COMMAND_BYTES = 4096;

    /**
     * One Nightwatch record as one Prism event, or null when nothing honest can be made of it.
     *
     * @param  array<string, mixed>  $record
     * @return array{type: string, timestamp: string, trace_id: string, request_id: string, user_id: string|null, payload: array<string, mixed>}|null
     */
    public function translate(array $record): ?array
    {
        $key = $this->normalizeType($record['t'] ?? null);

        if ($key === null) {
            return null;
        }

        if (($record['v'] ?? null) !== self::VERSIONS[$key]) {
            return null;
        }

        if ($this->hasNothingToRecord($key, $record)) {
            return null;
        }

        $timestamp = $this->timestamp($this->normalizeValue($record['timestamp'] ?? null));

        // Every real record carries a float microtime; a malformed one the server would drop on the
        // unparseable timestamp anyway. Dropping here keeps the count honest and this method a function of
        // its argument — inventing `now()` would not.
        if ($timestamp === null) {
            return null;
        }

        return [
            'type' => self::TYPES[$key],
            'timestamp' => $timestamp,
            'trace_id' => $this->string($this->normalizeValue($record['trace_id'] ?? null)),

            // `execution_id` is the id of the request or command the signal happened inside — exactly what
            // Prism's `request_id` correlates on. A *request* record carries none (it is the execution), so
            // this is empty there and only the ingest, holding the execution state, can fill it in.
            'request_id' => $this->string($this->normalizeValue($record['execution_id'] ?? null)),

            // `user_id` is Nullable(String) server-side: no authenticated user is null, not an empty string,
            // so the console tells "nobody" from "somebody with a blank id".
            'user_id' => $this->nullableString($this->normalizeValue($record['user'] ?? null)),
            'payload' => $this->payload($key, $record),
        ];
    }

    /**
     * Every record key the envelope did not consume, the per-type mapping merged over so Prism's column names
     * win.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function payload(string $key, array $record): array
    {
        $passthrough = [];

        foreach ($record as $field => $value) {
            if (in_array($field, self::ENVELOPE_KEYS, true)) {
                continue;
            }

            $passthrough[(string) $field] = $this->normalizeValue($value);
        }

        $payload = [...$passthrough, ...$this->mapped($key, $passthrough)];

        // Dropped only after the mapping has run: a consumed field is one the mapper READ and restated, so
        // removing it first would take it from the method that needs it.
        foreach (self::CONSUMED[$key] ?? [] as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    /**
     * The per-type restatement of a record under Prism's column names, read off the already-normalised
     * passthrough rather than the raw record so a lazily-resolved counter resolves once per record.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function mapped(string $key, array $r): array
    {
        return match ($key) {
            'request' => $this->requestPayload($r),
            'exception' => $this->exceptionPayload($r),
            'log' => $this->logPayload($r),
            'query' => $this->queryPayload($r),
            'job_attempt' => $this->jobAttemptPayload($r),
            'queued_job' => $this->queuedJobPayload($r),
            'scheduled_task' => $this->schedulePayload($r),
            'command' => $this->commandPayload($r),
            'mail' => $this->mailPayload($r),
            'notification' => $this->notificationPayload($r),
            'cache_event' => $this->cacheSpanPayload($r),
            'outgoing_request' => $this->httpSpanPayload($r),
            default => [],
        };
    }

    /**
     * The `requests` columns. `queries`, Nightwatch's per-request query counter, becomes `query_count`; the
     * other sixteen counters and the seven execution-stage durations ride the passthrough untouched under the
     * names US-007's columns take. `url` is read **twice**, into `path` and `query_string`: `path` is the
     * dimension a reader filters and scans the Stream by, and a query string mixed into it would make one
     * endpoint a different value on every request, while the query is the detail screen's alone. Splitting
     * rather than storing the whole URL keeps `path`'s meaning byte-identical to what it has always had.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function requestPayload(array $r): array
    {
        return [
            'method' => $this->string($r['method'] ?? null),
            'path' => $this->path($this->string($r['url'] ?? null)),
            'route' => $this->routeUri($this->string($r['route_path'] ?? null)),
            'status' => $this->int($r['status_code'] ?? null),
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'memory_mb' => $this->megabytes($r['peak_memory_usage'] ?? null),
            'query_count' => $this->int($r['queries'] ?? null),
            'ip' => $this->string($r['ip'] ?? null),
            'user_agent' => $this->header($r['headers'] ?? null, 'user-agent'),
            'query_string' => $this->query($this->string($r['url'] ?? null)),
        ];
    }

    /**
     * The `exceptions` columns. `handled` is a bool upstream and a `UInt8` here, cast at the seam because
     * ClickHouse is fussier about a JSON boolean than PHP. Deliberately no `fingerprint` — the server
     * computes every one itself from the class plus the normalised topmost application frame (US-029), so
     * grouping cannot drift with the client version. What this mapper owes it is `frames` in the shape it
     * reads (the whole of US-009), the one mapping here where *nearly* right is worse than absent: a
     * fingerprint that moves silently orphans every error group, every issue keyed on one and every triage
     * decision made, and the console looks like every error is brand new.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function exceptionPayload(array $r): array
    {
        return [
            'class' => $this->string($r['class'] ?? null),
            'message' => $this->string($r['message'] ?? null),
            'file' => $this->framePath($this->string($r['file'] ?? null)),
            'line' => $this->int($r['line'] ?? null),
            'frames' => $this->frames($r['trace'] ?? null),
            'handled' => $this->flag($r['handled'] ?? null),
        ];
    }

    /**
     * The record's serialised `trace` as the frame list the server reads — `{file, line, function, vendor}`
     * per frame, plus the captured `code` where the sensor fetched any. An unparseable trace yields no frames
     * rather than an error: the server falls back to the exception's own file, as for a stack that is
     * entirely library code. Two things about the conversion are load-bearing, and both are silent when got
     * wrong.
     *
     * **The function label belongs to the NEXT entry.** Nightwatch serialises a PHP backtrace as PHP hands it
     * over: an entry's `file` is where a call was made *from* and its `source` is what was called *there*,
     * while a stack frame, as every console draws one, is the other pairing — a location and the function
     * *containing* it. Shifting the label one entry up restores that, and is the pairing the server's
     * fingerprint has always hashed (the old client shifted the same way building frames from
     * `Throwable::getTrace()`); getting it wrong changes every fingerprint in the workspace while still
     * producing a plausible-looking stack. The last entry has no successor and so carries no function, as the
     * outermost frame of a backtrace has no caller. **`source` is `Class->method(argTypes)` and the label is
     * the part before the bracket** — the same `class . type . function` string the old client assembled,
     * which is why the two agree without a translation table.
     *
     * @return list<array<string, mixed>>
     */
    private function frames(mixed $trace): array
    {
        $decoded = is_string($trace) ? json_decode($trace, true) : $this->normalizeValue($trace);

        if (! is_array($decoded)) {
            return [];
        }

        $entries = [];

        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        $frames = [];

        foreach ($entries as $index => $entry) {
            [$file, $line] = $this->frameLocation($this->string($entry['file'] ?? null));

            $frame = [
                'file' => $file,
                'line' => $line,
                'function' => $this->frameFunction($entries[$index + 1]['source'] ?? null),
                'vendor' => $this->isVendorPath($file),
            ];

            // The captured source lines around the frame, when the sensor fetched them: on by default via
            // `nightwatch.capture_exception_source_code`, and only ever for application files. Keyed by line
            // number, so the error detail screen renders the throw in context.
            $code = $this->normalizeValue($entry['code'] ?? null);

            if (is_array($code) && $code !== []) {
                $frame['code'] = $code;
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Split a serialised frame location — `app/Http/Kernel.php:41` — into a path and a line number. The path
     * goes through {@see framePath()}; the line is 0 when the entry carried none, what the old client
     * recorded for the same case and what `[internal function]` and its like always read as.
     *
     * @return array{0: string, 1: int}
     */
    private function frameLocation(string $location): array
    {
        $separator = strrpos($location, ':');

        if ($separator === false) {
            return [$this->framePath($location), 0];
        }

        $line = substr($location, $separator + 1);

        if ($line === '' || ! ctype_digit($line)) {
            return [$this->framePath($location), 0];
        }

        return [$this->framePath(substr($location, 0, $separator)), (int) $line];
    }

    /**
     * A frame's function label: the `Class->method` / `Class::method` / `function` part of a serialised
     * `source`, without the argument-type list upstream appends. Empty for the outermost frame, which has no
     * caller.
     */
    private function frameFunction(mixed $source): string
    {
        $source = $this->string($this->normalizeValue($source));

        if ($source === '') {
            return '';
        }

        $bracket = strpos($source, '(');

        return trim($bracket === false ? $source : substr($source, 0, $bracket));
    }

    /**
     * A file path with its project root marked by a leading slash. Nightwatch strips the deploy base path
     * from every path it records — right to store, but it leaves a *relative* path, and both rules the server
     * applies key on `/segment/` boundaries: a frame is library code when its path passes through `/vendor/`,
     * and a path is trimmed to the project root at `/app/`, `/src/`, `/routes/` and so on. A bare
     * `vendor/laravel/framework/...` therefore reads as application code, which would make every
     * `ModelNotFoundException` in the host fingerprint on the framework frame that threw it, folding
     * unrelated faults into one group and orphaning every group that already existed. Restoring the slash
     * makes a root-relative path say where its root is, and the two engines' paths hash identically: the old
     * client's deploy-absolute `/var/www/html/app/X.php` and this one's `/app/X.php` both trim to
     * `app/X.php`. An already-absolute path (one outside the base path, which Nightwatch leaves alone) and a
     * pseudo-location like `[internal function]` are returned untouched.
     */
    private function framePath(string $file): string
    {
        if ($file === '' || str_starts_with($file, '/') || str_starts_with($file, '[')) {
            return $file;
        }

        // A Windows absolute path (`C:\inetpub\app\X.php`) or a UNC share.
        if (str_starts_with($file, '\\') || preg_match('#^[A-Za-z]:[\\/]#', $file) === 1) {
            return $file;
        }

        return '/'.$file;
    }

    /** Whether a frame's path is library code rather than the application's own. */
    private function isVendorPath(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        foreach (self::VENDOR_SEGMENTS as $segment) {
            if (str_contains($file, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `logs` columns. `context` arrives already JSON-encoded by the sensor and passes through as-is — the
     * column is a String of encoded JSON, so re-encoding would double-escape the line. `channel` has no
     * counterpart: Nightwatch's log record carries no channel dimension at all, so the column stays empty
     * rather than being invented.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function logPayload(array $r): array
    {
        return [
            'level' => $this->string($r['level'] ?? null),
            'message' => $this->string($r['message'] ?? null),
            'context' => $this->string($r['context'] ?? null),
        ];
    }

    /**
     * The `queries` columns. Nightwatch captures no bindings (it records the SQL, the calling file and line
     * instead), so `bindings` is left absent rather than an empty list reading as "none".
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function queryPayload(array $r): array
    {
        return [
            'sql' => $this->string($r['sql'] ?? null),
            'connection' => $this->string($r['connection'] ?? null),
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
        ];
    }

    /**
     * The `jobs` columns for the *attempt* half — a job that has finished running, so it has an outcome and a
     * runtime. Nightwatch's `released` is Prism's `retried`: a released job went back on the queue to be
     * attempted again, what that status has always meant on the Jobs screen. `queue` and `status` are the job
     * rollup's grouping keys and the sensor fills both on every attempt (queue off the job, status off its
     * outcome), which makes an attempt safe to aggregate. `exception_preview` becomes the `exception` column:
     * the one-line summary the failed-jobs table prints per row, and a column named for the record's own
     * field would be dropped on insert. `queue_wait_ms` has no counterpart — Nightwatch times the attempt,
     * not the wait before it — so the column stays at zero rather than being invented from the two
     * timestamps, which belong to two different processes.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function jobAttemptPayload(array $r): array
    {
        $status = $this->string($r['status'] ?? null);

        return [
            'phase' => 'attempted',
            'job_class' => $this->string($r['name'] ?? null),
            'queue' => $this->string($r['queue'] ?? null),
            'connection' => $this->string($r['connection'] ?? null),
            'uuid' => $this->string($r['job_id'] ?? null),
            'status' => $status === 'released' ? 'retried' : $status,
            'attempts' => $this->int($r['attempt'] ?? null),
            'runtime_ms' => $this->milliseconds($r['duration'] ?? null),
            'exception' => $this->string($r['exception_preview'] ?? null),
        ];
    }

    /**
     * The `jobs` columns for the *queued* half — a job just put on a queue, so there is no outcome yet and
     * `status` is absent for that reason. The record's `duration` is how long the dispatch took, neither a
     * runtime nor a queue wait: it rides the passthrough as `duration` rather than being claimed as either.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function queuedJobPayload(array $r): array
    {
        return [
            'phase' => 'queued',
            'job_class' => $this->string($r['name'] ?? null),
            'queue' => $this->string($r['queue'] ?? null),
            'connection' => $this->string($r['connection'] ?? null),
            'uuid' => $this->string($r['job_id'] ?? null),
        ];
    }

    /**
     * The `schedules` columns. Nightwatch reports a task's outcome as a status word where Prism's table
     * records an exit code, so the word is turned back into the code the Crons screen counts failures with
     * (`exit_code != 0`). Only `processed` and `failed` reach here — a `skipped` task is dropped before this
     * ({@see hasNothingToRecord()}), since it never ran and every exit code is a claim that it did. `output`
     * has no counterpart (Nightwatch captures none), so the column stays empty and the cron detail's output
     * panel is blank rather than wrong.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function schedulePayload(array $r): array
    {
        return [
            'command' => $this->string($r['name'] ?? null),
            'expression' => $this->string($r['cron'] ?? null),
            'exit_code' => $this->string($r['status'] ?? null) === 'failed' ? 1 : 0,
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'memory_mb' => $this->megabytes($r['peak_memory_usage'] ?? null),
            'host' => $this->string($r['server'] ?? null),
        ];
    }

    /**
     * The `commands` columns (US-006 creates the table). The record's own `class`, `name` and `exit_code`
     * already read correctly and ride the passthrough; the two unit conversions and the one cap are restated.
     * **`command` is the whole invocation, and it already is** — upstream's command sensor builds it from the
     * input the console kernel handed it: raw argv tokens for a real CLI run (`backup:run 41 --disk=s3`),
     * formatted parameters otherwise. Nothing to capture that the engine does not report, and no holder of
     * Prism's own (the shape a job payload or an HTTP body needs) is warranted; what upstream does not do is
     * bound it, which is why the line is restated rather than left to the passthrough. The value has already
     * met `prism.scrub` — the engine applies Prism's `redactCommand` callback to the record before the
     * resolver that builds this array runs, so `--password=hunter2` arrives redacted and the cut below can
     * never land inside a secret.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function commandPayload(array $r): array
    {
        return [
            'command' => $this->commandLine($r['command'] ?? null),
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'memory_mb' => $this->megabytes($r['peak_memory_usage'] ?? null),
        ];
    }

    /**
     * A command line, made safe to insert and bounded. {@see Text::clean()} first, because the cap is in
     * bytes and the cut has to happen on a string whose bytes are already valid UTF-8 — an argv token is
     * whatever a shell handed the process, and ClickHouse's `JSONEachRow` refuses the whole insert over one
     * invalid byte, not the row.
     */
    private function commandLine(mixed $value): string
    {
        $line = Text::clean($this->string($value));

        if (strlen($line) <= self::MAX_COMMAND_BYTES) {
            return $line;
        }

        return Text::truncate($line, self::MAX_COMMAND_BYTES).Text::TRUNCATED;
    }

    /**
     * The `mail` columns (US-006 creates the table).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function mailPayload(array $r): array
    {
        return [
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'failed' => $this->flag($r['failed'] ?? null),
        ];
    }

    /**
     * The `notifications` columns (US-006 creates the table).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function notificationPayload(array $r): array
    {
        return [
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'failed' => $this->flag($r['failed'] ?? null),
        ];
    }

    /**
     * The `spans` columns for a cache event. The record's own `type` field is the cache *operation* (`hit`,
     * `miss`, `write`, `delete`, …) and would otherwise land in the `spans.type` column, which names the
     * waterfall *lane*; the mapping wins the collision and the operation is re-exposed under `operation`, the
     * key the cache lane has always carried — the name Prism's own cache capture produced, so a waterfall
     * spanning the engine change reads as one vocabulary. `span_id`, `parent_span_id` and `offset_ms` are
     * absent by design: identity and lineage need the active OTel span, an offset needs the execution's start
     * time, and both are the ingest's to stamp (US-015).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function cacheSpanPayload(array $r): array
    {
        $operation = $this->string($r['type'] ?? null);
        $store = $this->string($r['store'] ?? null);
        $cacheKey = $this->string($r['key'] ?? null);

        return [
            'type' => 'cache',
            'name' => sprintf('cache:%s %s (%s)', $operation, $cacheKey, $store),
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'operation' => $operation,
            'store' => $store,
            'key' => $cacheKey,
        ];
    }

    /**
     * The `spans` columns for an outgoing HTTP call, named the way Prism's own HTTP capture named it (`GET
     * api.example.com/v1/things`).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function httpSpanPayload(array $r): array
    {
        $method = $this->string($r['method'] ?? null);
        $host = $this->string($r['host'] ?? null);
        $path = $this->path($this->string($r['url'] ?? null));

        return [
            'type' => 'http',
            'name' => trim($method.' '.$host.$path),
            'duration_ms' => $this->milliseconds($r['duration'] ?? null),
            'method' => $method,
            'host' => $host,
            'path' => $path,
            'status' => $this->int($r['status_code'] ?? null),
        ];
    }

    /**
     * Whether a record of a known type describes something that did not happen, and so has no honest row to
     * become. Today exactly one case: a scheduled task Nightwatch reports as `skipped` (`withoutOverlapping`,
     * `onOneServer`, a failing `when()`) never ran, and both exit codes Prism's `schedules` table could be
     * given are wrong — zero draws a green tick in the Crons history strip for a run that never started,
     * non-zero reports a failure nothing failed at — so the record is dropped and counted, which is what the
     * old client did by never listening for the event at all.
     *
     * @param  array<string, mixed>  $record
     */
    private function hasNothingToRecord(string $key, array $record): bool
    {
        return $key === 'scheduled_task' && ($record['status'] ?? null) === 'skipped';
    }

    /**
     * The lookup key for a record type: lower-cased with hyphens folded to underscores, so `job-attempt`
     * (what the sensors emit) and `job_attempt` (how the signal is named everywhere else) are one key. Null
     * for a type with no Prism counterpart — today only `user`, Nightwatch's identity upsert, not a telemetry
     * event and with no table to land in.
     */
    private function normalizeType(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        $key = str_replace('-', '_', strtolower($type));

        return isset(self::TYPES[$key]) ? $key : null;
    }

    /**
     * A float microtime as an ISO-8601 UTC string with microsecond precision, built by hand rather than
     * through a date library because the precision is the point: the server parses this into a
     * `DateTime64(3)`, and a span, a query and the log line between them routinely fall inside the same
     * second, which a second-resolution ISO-8601 string would order arbitrarily. The format lives in {@see
     * Timestamp} because the span lane builds one too, from a different unit (US-015).
     */
    private function timestamp(mixed $value): ?string
    {
        return Timestamp::fromMicrotime($value);
    }

    /**
     * Resolve a record value to plain data. Nightwatch defers the expensive globals — the execution id, the
     * resolved user, a job attempt's fifteen counters — behind a `LazyValue`, a `JsonSerializable` that
     * resolves when *its own* ingest encodes the record. Prism does not encode here, so an unresolved value
     * would travel as an object and reach the wire as whatever `json_encode` made of it much later, far from
     * anything that could explain it; resolving at the seam keeps a translated record plain data. Backed
     * enums (a record's `execution_stage`) are unwrapped for the same reason.
     */
    private function normalizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item, $depth + 1);
            }

            return $normalized;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof JsonSerializable) {
            try {
                return $this->normalizeValue($value->jsonSerialize(), $depth + 1);
            } catch (Throwable) {
                // A deferred value that cannot resolve is one field, not a record — telemetry never surfaces
                // an error into the host.
                return null;
            }
        }

        return $value;
    }

    /** A record field as a string; anything non-scalar reads as absent. */
    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A record field as a string, with "no value" preserved as null. */
    private function nullableString(mixed $value): ?string
    {
        $string = $this->string($value);

        return $string === '' ? null : $string;
    }

    /** A record field as an int; anything non-numeric reads as zero. */
    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** A boolean-ish record field as the `UInt8` its column expects. */
    private function flag(mixed $value): int
    {
        return $value ? 1 : 0;
    }

    /** A Nightwatch duration (always microseconds) as Prism milliseconds. */
    private function milliseconds(mixed $value): float
    {
        return is_numeric($value) ? round(((float) $value) / 1000, 3) : 0.0;
    }

    /** A Nightwatch memory figure (always bytes) as Prism megabytes. */
    private function megabytes(mixed $value): float
    {
        return is_numeric($value) ? round(((float) $value) / 1048576, 3) : 0.0;
    }

    /**
     * The path of an absolute URL — what Prism's `path` column has always held, where Nightwatch records the
     * whole URL including scheme, host and query.
     */
    private function path(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * The query string of that same URL, without its `?` — stored in a column of its own rather than left on
     * `path`, and the half {@see path()} used to throw away: the request detail screen has always had a Query
     * parameters panel, and parsing it back off `path`, a value that by construction never contains a `?`,
     * meant that panel could never draw anything. Whatever `prism.scrub` covers is **already gone by the time
     * this runs**: upstream's own request sensor invokes the redact callbacks while it builds the record, and
     * `RedactRules::redactRequest()` rewrites `$record->url`'s query pair by pair — work with no consequence
     * while the query it cleaned was discarded a moment later, load-bearing now.
     */
    private function query(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) ? $query : '';
    }

    /**
     * A route pattern the way Laravel's own `Route::uri()` spells it — no leading slash, except for the root
     * route, which *is* one. Nightwatch prefixes every pattern with a slash; keeping that would make one
     * route two rows on the Requests screen for as long as both engines run.
     */
    private function routeUri(string $routePath): string
    {
        if ($routePath === '' || $routePath === '/') {
            return $routePath;
        }

        return ltrim($routePath, '/');
    }

    /**
     * One header off the record's JSON-encoded header bag. A bag holds a list per header, so a present header
     * is its first value.
     */
    private function header(mixed $headers, string $name): string
    {
        if (! is_string($headers) || $headers === '') {
            return '';
        }

        $decoded = json_decode($headers, true);

        if (! is_array($decoded) || ! isset($decoded[$name])) {
            return '';
        }

        $value = $decoded[$name];

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $this->string($value);
    }
}
