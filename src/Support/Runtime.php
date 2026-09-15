<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Support;

/**
 * Facts about the process the client is running inside, read once from the
 * command line rather than inferred from traffic.
 *
 * The one question so far is whether this process works the queue — a
 * `queue:work`/`queue:listen` worker or a Horizon supervisor. Queue-metrics
 * polling (US-048) registers only on such a process, so a web replica that
 * dispatches jobs but never processes them pays nothing for polling; the same
 * signal seeds the replica-type inference (US-051).
 *
 * The check reads `$_SERVER['argv']`, populated for a CLI process and absent
 * under php-fpm/web: a web request answers false with no argv-parsing, a worker
 * answers true from the command it was launched with.
 */
final class Runtime
{
    /**
     * Artisan commands whose process is a queue worker. Matched exactly or as a
     * `<command>:*` prefix, so `horizon`, `horizon:work` and `horizon:supervisor`
     * all count while an unrelated command never does.
     *
     * @var list<string>
     */
    private const WORKER_COMMANDS = ['queue:work', 'queue:listen', 'horizon'];

    /**
     * Whether this process is a queue worker. True when the launching command is
     * one of {@see WORKER_COMMANDS}; false for a web request (no argv) or any
     * other console command.
     */
    public static function isQueueWorker(): bool
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv)) {
            return false;
        }

        foreach ($argv as $arg) {
            if (! is_string($arg)) {
                continue;
            }

            foreach (self::WORKER_COMMANDS as $command) {
                if ($arg === $command || str_starts_with($arg, $command.':')) {
                    return true;
                }
            }
        }

        return false;
    }
}
