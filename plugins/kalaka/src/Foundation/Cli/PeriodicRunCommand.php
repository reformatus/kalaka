<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Cli;

use Kalaka\Foundation\Periodic\PeriodicRun;

/** The WP-CLI command `wp kalaka periodic-run`: a manual start of the periodic run, in the CLI process. */
final class PeriodicRunCommand
{
    public function __construct(private readonly PeriodicRun $run)
    {
    }

    public static function register(PeriodicRun $run): void
    {
        \WP_CLI::add_command('kalaka periodic-run', new self($run), [
            'shortdesc' => 'Runs the periodic run now.',
        ]);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $report = $this->run->run();
        if ($report === null) {
            \WP_CLI::error('Periodic run not started: another run holds the lock, or Kaláka did not load cleanly.');
        }

        \WP_CLI::success('Evaluated ' . $report->evaluated . ' registrations, ' . $report->failed . ' failed.');
    }
}
