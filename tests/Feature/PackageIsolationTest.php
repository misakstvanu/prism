<?php

/**
 * The client package is installed into other people's applications, so it must
 * stand on its own: nothing under src/ may name a class from the host this
 * repository happens to also be. The premise is normally structural — the
 * package suite runs against the package's own vendor tree, where no host class
 * exists, so a reference would be a fatal — but two things can slip a host name
 * in without ever resolving it at runtime: a docblock (pint's
 * fully_qualified_strict_types fixer promotes a fully-qualified see-tag into a
 * real import) and a class-string in config or an array. Both survive every
 * other test in this suite.
 *
 * The docblock half is not hypothetical: writing that fixer's name beside a
 * fully-qualified see-tag in this very file made pint rewrite the prose into a
 * real import. Name a host class in words here, never in package-docblock
 * syntax.
 */

/**
 * The host application's own PSR-4 roots, from the repository's composer.json.
 * A reference to any of them from src/ is a dependency on code the package
 * cannot be shipped with.
 */
const PRISM_HOST_NAMESPACE_ROOTS = ['App', 'Database\\Factories', 'Database\\Seeders', 'Tests'];

/**
 * Every PHP file under the package's src/ directory, keyed by its path relative
 * to the package root so a failure names something you can open.
 *
 * @return array<string, string>
 */
function prismPackageSources(): array
{
    $root = dirname(__DIR__, 2);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)
    );

    $sources = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
    }

    ksort($sources);

    return $sources;
}

it('has sources to sweep', function () {
    // A sweep over an empty list passes on every mutation it exists to catch.
    expect(count(prismPackageSources()))->toBeGreaterThan(30);
});

it('names no host class anywhere in src/', function () {
    $offenders = [];

    foreach (prismPackageSources() as $path => $source) {
        foreach (PRISM_HOST_NAMESPACE_ROOTS as $root) {
            $quoted = preg_quote($root, '/');

            // A fully-qualified reference (including one inside a docblock)
            // starts at the root, so the backslash before it cannot follow an
            // identifier character — that would make it a deeper segment of
            // some other namespace, e.g. Misakstvanu\Prism\Tests.
            $qualified = '/(?<![A-Za-z0-9_\\\\])\\\\'.$quoted.'\\\\/';

            // An unqualified import: use App\…, use function App\…
            $imported = '/^\s*use\s+(?:function\s+|const\s+)?'.$quoted.'\\\\/m';

            // A class-string names the host without ever being resolved, and
            // carries no leading backslash to give itself away:
            // 'App\Models\User' in a config array or a listener map reads as
            // data right up until something instantiates it. A double-quoted
            // string escapes each separator, so both widths are matched.
            $segments = array_map(
                static fn (string $segment): string => preg_quote($segment, '/'),
                explode('\\', $root),
            );
            $stringly = '/[\'"]\\\\{0,2}'.implode('\\\\{1,2}', $segments).'\\\\{1,2}/';

            if (preg_match($qualified, $source) === 1
                || preg_match($imported, $source) === 1
                || preg_match($stringly, $source) === 1) {
                $offenders[] = $path.' references '.$root.'\\';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('autoloads only its own namespace', function () {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(array_keys($composer['autoload']['psr-4']))->toBe(['Misakstvanu\\Prism\\']);
});

it('declares a stable version so the path repository resolves', function () {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    // The package is consumed through a path repository under
    // minimum-stability: stable, which has no tags to read a version from — the
    // version string in composer.json is the whole answer, and a non-stable one
    // (1.0.0-beta, dev-main) would stop resolving with no other symptom.
    expect($composer['version'] ?? null)->toMatch('/^\d+\.\d+\.\d+$/');
});
