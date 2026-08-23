<?php

use Misakstvanu\Prism\Nightwatch\RecordTranslator;

/*
 * US-009 — the frames an exception record becomes.
 *
 * Nightwatch serialises a stack once, into a `trace` string, and that string is
 * all the server ever gets: the fingerprint every error group, every issue and
 * every triage decision is keyed on is computed from the frames this file
 * produces. The two properties worth a test are the two that are silent when
 * wrong — the function label is shifted one entry against the location it is
 * paired with, and a project-relative path leaves the seam with its root marked
 * so the server's `/vendor/` and `/app/` rules — which key on `/segment/`
 * boundaries — can see it.
 *
 * The exception is thrown through two named functions on purpose: it is what
 * makes the shift assertable by name rather than by shape.
 */
beforeEach(function () {
    $this->translator = new RecordTranslator;
});

/** The innermost frame: the function the throw happens inside. */
function prismFramesInnerThrow(): void
{
    throw new RuntimeException('Order 5 could not be priced.');
}

/** Its caller, so the stack has a second application frame to shift onto. */
function prismFramesOuterCall(): RuntimeException
{
    try {
        prismFramesInnerThrow();
    } catch (RuntimeException $e) {
        return $e;
    }

    throw new LogicException('unreachable');
}

/**
 * One real record for an exception thrown in this file, with the package root
 * standing in for a host's base path — which is what makes these test files
 * "application code" as far as upstream is concerned, and so gives them the
 * relative paths and the captured source a host's own files get.
 *
 * @return array<string, mixed>
 */
function prismFramesCaptured(bool $captureSourceCode = true): array
{
    return nightwatchExceptionRecord(
        prismFramesOuterCall(),
        captureSourceCode: $captureSourceCode,
        basePath: dirname(__DIR__, 2),
    );
}

/**
 * A hand-written trace in the shape the sensor serialises, for the two rules a
 * single stack cannot show at once — an absolute path left alone, and a
 * pseudo-location.
 */
function prismFramesRelativeTrace(): string
{
    return json_encode([
        ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:600', 'source' => '', 'code' => null],
        ['file' => 'app/Http/Controllers/InvoiceController.php:41', 'source' => 'Illuminate\\Database\\Eloquent\\Builder->firstOrFail()', 'code' => null],
        ['file' => 'app/Http/Kernel.php:12', 'source' => 'App\\Http\\Controllers\\InvoiceController->show(int)', 'code' => null],
        ['file' => '[internal function]', 'source' => 'App\\Http\\Kernel->handle()', 'code' => null],
    ], JSON_THROW_ON_ERROR);
}

/**
 * One exception record with a hand-written trace, so a test can state the stack
 * it is asserting about.
 *
 * @return array<string, mixed>
 */
function prismFramesRecord(string $trace): array
{
    return [
        'v' => 3,
        't' => 'exception',
        'timestamp' => microtime(true),
        'trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'class' => 'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
        'message' => 'No query results for model [App\\Models\\Invoice] 5.',
        'file' => 'app/Http/Controllers/InvoiceController.php',
        'line' => 41,
        'trace' => $trace,
        'handled' => true,
    ];
}

it('pairs each frame with the function that CONTAINS it, not the one called there', function () {
    // Nightwatch serialises a PHP backtrace as PHP hands it over: an entry's
    // file is where a call was made FROM and its source is what was called
    // there. A stack frame is the other pairing, and it is the one the server
    // fingerprints on — so the label shifts one entry up.
    $frames = $this->translator->translate(prismFramesCaptured())['payload']['frames'];

    expect($frames[0]['function'])->toBe('prismFramesInnerThrow')
        ->and($frames[1]['function'])->toBe('prismFramesOuterCall')
        ->and($frames[0]['file'])->toBe('/tests/Feature/ExceptionFramesTest.php')
        ->and($frames[1]['file'])->toBe('/tests/Feature/ExceptionFramesTest.php')
        ->and($frames[0]['line'])->toBeGreaterThan(0)
        ->and($frames[0]['vendor'])->toBeFalse();

    // Pest's own runner is under the package's vendor directory, so a real
    // stack proves the vendor rule as well as the shift.
    expect(array_column($frames, 'vendor'))->toContain(true);

    // The outermost frame has no caller, so it carries no function — the same
    // `{main}` the console draws for one.
    expect(end($frames)['function'])->toBe('');
});

it('drops the argument-type list upstream appends to a source', function () {
    $frames = $this->translator->translate(prismFramesRecord(prismFramesRelativeTrace()))['payload']['frames'];

    // `App\Http\Controllers\InvoiceController->show(int)` is the label plus the
    // types of the arguments it was called with; only the label is a property
    // of the code, and only the label is what the fingerprint hashes.
    expect($frames[1]['function'])->toBe('App\\Http\\Controllers\\InvoiceController->show');
});

it('marks the project root on a relative path so the vendor rule can see it', function () {
    $frames = $this->translator->translate(prismFramesRecord(prismFramesRelativeTrace()))['payload']['frames'];

    // Without the leading slash `vendor/laravel/...` does not pass through
    // `/vendor/`, and every framework-thrown exception would fingerprint on the
    // framework frame that threw it.
    expect($frames[0]['file'])->toBe('/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php')
        ->and($frames[0]['line'])->toBe(600)
        ->and($frames[0]['vendor'])->toBeTrue()
        ->and($frames[1]['file'])->toBe('/app/Http/Controllers/InvoiceController.php')
        ->and($frames[1]['vendor'])->toBeFalse();

    // The record's own file is the fingerprint's fallback and gets the same
    // treatment, so the two cannot describe one location two ways.
    expect($this->translator->translate(prismFramesRecord(prismFramesRelativeTrace()))['payload']['file'])
        ->toBe('/app/Http/Controllers/InvoiceController.php');
});

it('leaves an absolute path and a pseudo-location alone', function () {
    $trace = json_encode([
        ['file' => '/opt/shared/lib/Thing.php:8', 'source' => '', 'code' => null],
        ['file' => '[internal function]', 'source' => 'Opt\\Shared\\Thing->run()', 'code' => null],
    ], JSON_THROW_ON_ERROR);

    $frames = $this->translator->translate(prismFramesRecord($trace))['payload']['frames'];

    expect($frames[0]['file'])->toBe('/opt/shared/lib/Thing.php')
        ->and($frames[1]['file'])->toBe('[internal function]')
        ->and($frames[1]['line'])->toBe(0);
});

it('carries the captured source code into the frames', function () {
    // `nightwatch.capture_exception_source_code` is on by default and Prism
    // leaves it on: the lines around the throw are what the error detail screen
    // has to show, and the record is the only place they exist.
    $frames = $this->translator->translate(prismFramesCaptured())['payload']['frames'];

    expect($frames[0])->toHaveKey('code')
        ->and($frames[0]['code'])->toBeArray()
        ->and(implode("\n", $frames[0]['code']))->toContain('Order 5 could not be priced.');
});

it('omits the source code a sensor did not capture', function () {
    $frames = $this->translator->translate(prismFramesCaptured(captureSourceCode: false))['payload']['frames'];

    expect($frames[0])->not->toHaveKey('code');
});

it('does not ship the serialised trace beside the frames it became', function () {
    // The trace is the largest field any record carries; the payload keeps the
    // restatement, not both.
    $payload = $this->translator->translate(prismFramesRecord(prismFramesRelativeTrace()))['payload'];

    expect($payload)->not->toHaveKey('trace')
        ->and($payload['frames'])->toHaveCount(4);
});

it('answers an unparseable or missing trace with no frames at all', function () {
    $truncated = $this->translator->translate(prismFramesRecord('[{"file":"app/X.php:1"'))['payload'];
    $absent = $this->translator->translate([...prismFramesRecord('[]'), 'trace' => null])['payload'];

    // The server then falls back to the exception's own file, which is what it
    // already does for a stack that is entirely library code.
    expect($truncated['frames'])->toBe([])
        ->and($absent['frames'])->toBe([]);
});
