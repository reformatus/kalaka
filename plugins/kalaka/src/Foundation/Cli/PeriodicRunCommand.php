<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Cli;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
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
        Boundary::cli(function (): void {
            $report = $this->run->run();
            if ($report === null) {
                throw new CommandFailure(
                    'Periodic run not started: another run holds the lock, Kaláka did not load cleanly, '
                    . 'or the database refuses writes (binlog_format=STATEMENT, see Site Health).'
                );
            }

            // [UNAPPROVED N1] the skipped count in the success line
            \WP_CLI::success('Evaluated ' . $report->evaluated . ' registrations, ' . $report->failed . ' failed, '
                . $report->skipped . ' skipped; ' . $report->timeRulesFailed . ' time rule checks failed.');
        });
    }
}
