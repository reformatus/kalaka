<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory\Cli;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * Manages the organization directory of the support plugin.
 *
 * The WP-CLI wrapper only: arguments, printing and the exit code. The work is DirectoryImport's.
 */
final class DirectoryCommand
{
    /**
     * Imports the organization directory from a CSV file, one node at a time.
     *
     * Runs kalaka/save-directory-node for every node of the file, parents before children, as the
     * global --user (full access is needed; without --user the CLI has it). A node already stored as
     * the file has it runs nothing and comes back unchanged. Active nodes below the file's top-level
     * nodes that the file leaves out are listed as warnings; nothing is deactivated.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path of the UTF-8 CSV file. The header names one column per level, top level first, each
     * column's text being that level's node type (e.g. Egyház,Egyházkerület,Egyházmegye,Egyházközség),
     * then optionally, in any order: id (the node's external ID), aliases (separated by |) and active
     * (1, 0 or empty for active). A row's node is in its last filled level cell; the cells before it
     * name its ancestors, an empty one skipping that level, and an ancestor without a row of its own
     * is created if missing. A node without an id gets its parent's external ID, "/" and the slug of
     * its name (the slug alone at the top level).
     *
     * [--delimiter=<char>]
     * : The one-character column separator.
     * ---
     * default: ,
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        Boundary::cli(static function () use ($args, $assoc): void {
            self::runImport($args, $assoc);
        });
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    private static function runImport(array $args, array $assoc): void
    {
        $actor = new CliActor(get_current_user_id() ?: null);
        $report = (new DirectoryImport(Runner::current()))->run($args[0], $assoc['delimiter'] ?? ',', $actor);

        // an implied ancestor counts only when it was created or failed
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $counted = 0;
        foreach ($report['entries'] as $entry) {
            if (!$entry['implied'] || in_array($entry['outcome'], ['created', 'failed'], true)) {
                $counts[$entry['outcome']]++;
                $counted++;
            }
            \WP_CLI::line(
                'Line ' . $entry['line'] . ' (' . $entry['external_id'] . ($entry['implied'] ? ', implied' : '') . '): '
                . ($entry['outcome'] === 'failed' ? 'failed: ' . $entry['message'] : $entry['outcome'])
            );
        }

        foreach ($report['unmentioned'] as $node) {
            \WP_CLI::warning('Active but not in the file: ' . $node['external_id'] . ' (' . $node['name'] . ')');
        }
        if ($report['unmentioned'] !== []) {
            \WP_CLI::warning(
                count($report['unmentioned']) . ' active nodes below the file\'s top level are not in the file.'
            );
        }

        if ($counts['failed'] > 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI prints it as plain text
            throw new CommandFailure($counts['failed'] . ' of ' . $counted . ' nodes failed.');
        }
        \WP_CLI::success(
            $counts['created'] . ' created, ' . $counts['updated'] . ' updated, ' . $counts['unchanged'] . ' unchanged.'
        );
    }
}
