<?php

declare(strict_types=1);

namespace Kalaka\Admin\Cli;

use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\FieldEditInput;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\FieldCorrections;

/**
 * `wp kalaka registration set-field`: staff correct one field value of a registration, until the staff screens.
 *
 * The WP-CLI wrapper only: arguments, warnings, the confirmation, printing and the exit code. The work is
 * `kalaka/edit-field-value`'s.
 */
final class RegistrationCommand
{
    /** @param Runner|null $runner null: the production runner; a test passes its own */
    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly FieldCorrections $corrections = new FieldCorrections(),
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka registration', new self(), [
            'shortdesc' => 'Corrects registrations by hand.',
        ]);
    }

    /**
     * Sets one field value of a registration, or clears it.
     *
     * Runs kalaka/edit-field-value as the global --user (without --user the CLI has full access). Not a
     * submission: the latest write wins and is audited with the reason. A limited choice (a price tier, an offer)
     * frees the old seat and takes the new one; a full pool only draws a warning, and the count goes over the
     * limit. Set options are placed with `wp kalaka assignment place`, never here.
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * <code>
     * : The registration's reference code.
     *
     * <field>
     * : The participant field's key.
     *
     * [<value>]
     * : The new value as the field's form control would send it: text, a number, `1` or `0` for a consent,
     * an option's value for a choice, a JSON list for several. Leave it out with --clear.
     *
     * [--reason=<text>]
     * : Why the value is corrected, at most 500 characters. Optional; written to the audit entry.
     *
     * [--clear]
     * : Remove the value instead of setting one.
     *
     * [--expected=<stored>]
     * : Refuse (version conflict) unless the stored value is this. An empty string means no value stored.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @subcommand set-field
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function setField(array $args, array $assoc): void
    {
        Boundary::cli(function () use ($args, $assoc): void {
            $clear = isset($assoc['clear']);
            if (count($args) < 3 || ($clear ? isset($args[3]) : !isset($args[3]))) {
                $error = new CommandFailure('Give the value, or --clear and no value.');
                throw $error;
            }
            $reason = $assoc['reason'] ?? null;
            $input = [
                'event_id' => (int) $args[0],
                'code' => $args[1],
                'field' => $args[2],
                'value' => $clear ? null : $args[3],
            ];
            if (is_string($reason)) {
                $input['reason'] = $reason;
            }
            if (isset($assoc['expected'])) {
                $expected = $assoc['expected'];
                $input['expected'] = is_string($expected) && $expected !== '' ? $expected : null;
            }
            $actor = new CliActor(get_current_user_id() ?: null);
            // The column right is decided inside prepare(), before anything about the field is looked up.
            $edit = (new FieldEditInput())->prepare($input, $actor);
            $useCase = new EditFieldValue($edit, $this->corrections);
            $runner = $this->runner ?? Runner::current();
            // The act itself says what it would do and warns when the choice is full; then the operator confirms.
            $preview = $runner->run($useCase, $input + ['preview' => true], $actor);
            if (!is_array($preview) || ($preview['changed'] ?? false) !== true) {
                \WP_CLI::success('Nothing to change: ' . $args[2] . ' of ' . $args[1] . ' already has that value.');

                return;
            }
            if (($preview['over_limit'] ?? []) !== []) {
                \WP_CLI::warning(AssignmentSets::FULL_WARNING);
            }
            \WP_CLI::confirm('Set ' . $args[2] . ' of ' . $args[1] . '?', $assoc);

            $result = $runner->run($useCase, $input, $actor);
            $over = is_array($result) ? ($result['over_limit'] ?? []) : [];
            \WP_CLI::success(
                'Set ' . $args[2] . ' of ' . $args[1] . '.'
                . ($over !== [] ? ' The pool is over its limit now.' : '')
            );
        });
    }
}
