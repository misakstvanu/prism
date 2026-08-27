<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Console\Bench;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Log\LogManager;
use Misakstvanu\Prism\Buffer\EventBuffer;
use Misakstvanu\Prism\Flush\BatchSpool;
use Misakstvanu\Prism\Flush\Flusher;
use Misakstvanu\Prism\Flush\SpoolScheduler;
use Misakstvanu\Prism\PrismServiceProvider;
use Misakstvanu\Prism\Support\IgnoreList;
use Misakstvanu\Prism\Support\Recursion;
use Misakstvanu\Prism\Support\Scrubber;
use Misakstvanu\Prism\Transport\Transport;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The pre-2.0 capture path, brought back from git so the benchmark has
 * something to compare against (US-023).
 *
 * **Why this exists at all.** The epic replaced Prism's own nine capture
 * listeners with two upstream engines, and the one question that decides
 * whether that was worth doing is what it costs the host's request. "Cheaper
 * than before" is not a claim that can be made from the new tree — the old
 * listeners were deleted (US-021), and a number remembered from before the
 * change describes a different machine on a different day. So the old client is
 * reconstructed here, in the same process, against the same workload, on the
 * same box, minutes apart from the new one.
 *
 * **What is reconstructed, and what is not.** Only the request path: the
 * middleware that times the lifecycle and emits its spans, the query capturer,
 * the cache capturer, the span recorder they share and the Monolog handler.
 * Jobs, scheduled tasks and outgoing HTTP are left out because the benchmark
 * times requests — a listener that never fires costs a `listen()` call at boot
 * and nothing per request, so including them would change no figure while
 * adding four more ways for the reconstruction to be wrong.
 *
 * **Two deliberate deviations, both in the old client's favour or neutral.**
 * The middleware is pushed onto the *global* stack rather than appended to the
 * `web`/`api` groups, because the benchmark's route belongs to no group and
 * both engines it is being compared against are global too — appending to a
 * group the route is not in would have measured the old client doing nothing.
 * And the flush is registered as an ordinary `terminating` callback, exactly as
 * the old provider registered it.
 *
 * The sources are materialised out of git rather than vendored into the
 * package: 2,600 lines of deleted code carried forward as a fixture would be
 * 2,600 lines nobody maintains, and the one property this needs — that it is
 * *the old client*, not somebody's recollection of it — is exactly what a git
 * ref guarantees and a copy does not.
 */
final class LegacyCapturePath
{
    /**
     * The commit that deleted `packages/prism/src/Capture/` (US-021); its
     * parent is therefore the last tree in which the old client was whole.
     *
     * Pinned as a full sha rather than a branch-relative expression because a
     * benchmark's baseline has to name one tree forever — `HEAD~n` moves with
     * every commit, and a baseline that quietly moves is worse than none.
     */
    public const REF = 'f17de137ddfbeaa8bc4812179ef45432de8ba41c^';

    /** The paths that make up the old request-capture path in that tree. */
    private const SOURCES = [
        'packages/prism/src/Capture',
        'packages/prism/src/Support/SpanStack.php',
    ];

    /** How deep `packages/prism/src/` sits, for `tar --strip-components`. */
    private const STRIP = 3;

    /**
     * Materialise the old client's sources into `$target`, returning the
     * directory on success or an explanation on failure.
     *
     * An already-populated target is used as-is. That is what makes the command
     * usable where `git` is not on the PATH — the container this project runs
     * its PHP in has no git binary, so the export is run once from the host into
     * the shared tree and every later run finds it there.
     *
     * @return array{0: string|null, 1: string|null} `[directory, failure reason]`
     */
    public static function materialise(string $repositoryRoot, string $target, string $ref): array
    {
        if (is_dir($target.'/Capture')) {
            return [$target, null];
        }

        if (! is_dir($repositoryRoot.'/.git')) {
            return [null, "{$repositoryRoot} is not a git repository, so the old client cannot be exported from it"];
        }

        if (! is_dir($target) && ! mkdir($target, 0o755, true) && ! is_dir($target)) {
            return [null, "could not create {$target}"];
        }

        // `git archive | tar -x` rather than a checkout: it writes only the
        // paths asked for, touches no index and cannot disturb the working
        // tree the benchmark is about to measure.
        $command = sprintf(
            'git archive %s %s | tar -x -C %s --strip-components=%d',
            escapeshellarg($ref),
            implode(' ', array_map('escapeshellarg', self::SOURCES)),
            escapeshellarg($target),
            self::STRIP,
        );

        $process = Process::fromShellCommandline($command, $repositoryRoot, timeout: 60);
        $process->run();

        if (! $process->isSuccessful() || ! is_dir($target.'/Capture')) {
            return [null, trim($process->getErrorOutput()) ?: 'git archive produced no sources'];
        }

        return [$target, null];
    }

    /**
     * The one-line command an operator can run themselves when this process
     * cannot (no git binary, an export that failed) — printed rather than
     * guessed at, because the alternative is a benchmark that silently drops
     * its baseline.
     */
    public static function exportCommand(string $target, string $ref): string
    {
        return sprintf(
            'mkdir -p %s && git archive %s %s | tar -x -C %s --strip-components=%d',
            $target,
            $ref,
            implode(' ', self::SOURCES),
            $target,
            self::STRIP,
        );
    }

    /**
     * Autoload the materialised classes.
     *
     * **Prepended, and that is not a preference.** Composer's optimised
     * classmap is generated from the tree as it stood when `dump-autoload` last
     * ran, so it can still hold entries for files US-021 deleted — and a
     * classmap hit is an unconditional `include` of a path that is no longer
     * there, which is a fatal rather than a miss the next autoloader gets to
     * answer. Going first sidesteps that entirely.
     *
     * Nothing here can shadow a class the package still ships: the loader
     * returns without acting unless the file exists in the materialised
     * directory, which holds only the deleted paths.
     */
    public static function autoload(string $directory): void
    {
        spl_autoload_register(static function (string $class) use ($directory): void {
            $prefix = 'Misakstvanu\\Prism\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $path = $directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($path)) {
                require_once $path;
            }
        }, prepend: true);
    }

    /**
     * Wire the old client into an application whose Prism provider registered
     * nothing (the benchmark runs this configuration with `PRISM_ENABLED=false`
     * precisely so the new engines are not in the picture).
     *
     * The bindings are the old `registerCapture()`'s, in its order, minus the
     * pieces no request touches. `PrismServiceProvider::ACTIVE` is bound first
     * and is load-bearing: every old capturer asks the container for it before
     * recording, so without it the whole reconstruction would run and buffer
     * nothing — a baseline of zero, which would then make any new figure look
     * like a regression.
     */
    public static function install(Application $app, BenchTransport $transport): void
    {
        $app->instance(PrismServiceProvider::ACTIVE, true);
        $app->instance(Transport::class, $transport);

        $app->singleton(EventBuffer::class, static fn ($app): EventBuffer => new EventBuffer(
            (int) $app['config']->get('prism.batch.size', 1000),
        ));

        $app->singleton(BatchSpool::class, static fn ($app): BatchSpool => new BatchSpool(
            $app->make(CacheFactory::class),
            $app['config'],
        ));

        $app->singleton(SpoolScheduler::class, static fn ($app): SpoolScheduler => new SpoolScheduler(
            $app->make(CacheFactory::class),
            $app['config'],
        ));

        $app->singleton(Scrubber::class, static function ($app): Scrubber {
            $keys = $app['config']->get('prism.scrub', []);

            return new Scrubber(is_iterable($keys) ? $keys : []);
        });

        // Named as strings, never imported: these classes do not exist in this
        // tree at all, and a `use` of one would be a broken import in a file
        // that has to keep parsing, linting and autoloading on an install that
        // never runs the benchmark. They resolve through the loader above.
        $spanRecorder = 'Misakstvanu\\Prism\\Capture\\SpanRecorder';
        $captureRequests = 'Misakstvanu\\Prism\\Capture\\CaptureRequests';
        $queryCapture = 'Misakstvanu\\Prism\\Capture\\QueryCapture';
        $cacheCapture = 'Misakstvanu\\Prism\\Capture\\CacheCapture';
        $logHandler = 'Misakstvanu\\Prism\\Capture\\PrismLogHandler';

        $app->singleton($spanRecorder, static fn ($app): object => new $spanRecorder(
            $app->make(EventBuffer::class),
            $app,
        ));

        $app->singleton($captureRequests, static fn ($app): object => new $captureRequests(
            $app->make(EventBuffer::class),
            $app->make(Scrubber::class),
            $app['config'],
            $app,
            $app->make($spanRecorder),
        ));

        $app->singleton($queryCapture, static function ($app) use ($queryCapture): object {
            $threshold = $app['config']->get('prism.query.slow_threshold_ms', 100);

            return new $queryCapture(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                is_numeric($threshold) ? (float) $threshold : 100.0,
            );
        });

        $app->singleton($cacheCapture, static fn ($app): object => new $cacheCapture(
            $app->make(EventBuffer::class),
            $app,
            IgnoreList::patterns($app['config']->get('prism.ignore.cache', [])),
        ));

        $kernel = $app->make(HttpKernelContract::class);

        if ($kernel instanceof FoundationHttpKernel) {
            $kernel->pushMiddleware($captureRequests);
        }

        // Two listeners on one event, exactly as the old provider had it: the
        // middleware's own counter (which the request event reports and the `db`
        // waterfall span is built from) and the per-query capturer. Their being
        // separate is part of what is being measured.
        $app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($app, $captureRequests): void {
            if (Recursion::suppressed()) {
                return;
            }

            $app->make($captureRequests)->recordQuery(is_numeric($event->time) ? (float) $event->time : 0.0);
        });

        $app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($app, $queryCapture): void {
            $app->make($queryCapture)->capture($event);
        });

        foreach ([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class] as $eventClass) {
            $app['events']->listen($eventClass, static function (CacheEvent $event) use ($app, $cacheCapture): void {
                $app->make($cacheCapture)->capture($event);
            });
        }

        self::attachLogHandler($app, $logHandler);

        $app->terminating(static function () use ($app): void {
            try {
                $app->make(Flusher::class)->flush();
            } catch (Throwable) {
                // The old client swallowed a flush failure too — a telemetry
                // client must never surface one to the host.
            }
        });
    }

    /**
     * Push the old Monolog handler onto the app's default channel, stripping
     * any earlier copy of the same class first — the stack-channel rule: a
     * Laravel stack is built out of its children's handler instances, so
     * pushing onto one that already carries the handler captures every line
     * twice.
     */
    private static function attachLogHandler(Application $app, string $handlerClass): void
    {
        $app->singleton($handlerClass, static function ($app) use ($handlerClass): object {
            $level = $app['config']->get('prism.log.level', 'debug');

            return new $handlerClass(
                $app->make(EventBuffer::class),
                $app->make(Scrubber::class),
                $app,
                is_string($level) || is_int($level) ? $level : 'debug',
            );
        });

        $log = $app->make('log');

        if (! $log instanceof LogManager) {
            return;
        }

        $channel = $log->channel();

        if (! method_exists($channel, 'getLogger')) {
            return;
        }

        $monolog = $channel->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $handler = $app->make($handlerClass);

        $monolog->setHandlers(array_values(array_filter(
            $monolog->getHandlers(),
            static fn (HandlerInterface $existing): bool => $existing::class !== $handlerClass,
        )));

        $monolog->pushHandler($handler);
    }
}
