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
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatOutcome;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActions;

/**
 * `wp kalaka registration set-field`: staff correct one field value of a registration, until the staff screens.
 * `admit`, `withdraw`, `remove` and `restore`: the status acts, each one `kalaka/<verb>-registration`.
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
        private readonly StatusActions $actions = new StatusActions(),
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
            foreach ((array) ($preview['warnings'] ?? []) as $warning) {
                \WP_CLI::warning((string) $warning);
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

    /**
     * Admits a waitlisted registration: it becomes registered and takes its seats again.
     *
     * Runs kalaka/admit-registration as the global --user (without --user the CLI has full access). A full pool
     * never refuses it: the seat goes over the limit, and the output says so.
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * <code>
     * : The registration's reference code.
     *
     * [--reason=<text>]
     * : Why, up to 500 characters. Written to the audit entry.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function admit(array $args, array $assoc): void
    {
        Boundary::cli(fn () => $this->statusAct(StatusAct::Admit, $args, $assoc));
    }

    /**
     * Records a withdrawal: the registration leaves and frees its seats. Nobody moves up from the waitlist.
     *
     * Runs kalaka/withdraw-registration as the global --user (without --user the CLI has full access).
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * <code>
     * : The registration's reference code.
     *
     * [--reason=<text>]
     * : Why, up to 500 characters. Written to the audit entry.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function withdraw(array $args, array $assoc): void
    {
        Boundary::cli(fn () => $this->statusAct(StatusAct::Withdraw, $args, $assoc));
    }

    /**
     * Removes a registration: it leaves and frees its seats. Nobody moves up from the waitlist.
     *
     * Runs kalaka/remove-registration as the global --user (without --user the CLI has full access).
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * <code>
     * : The registration's reference code.
     *
     * [--reason=<text>]
     * : Why, up to 500 characters. Written to the audit entry.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function remove(array $args, array $assoc): void
    {
        Boundary::cli(fn () => $this->statusAct(StatusAct::Remove, $args, $assoc));
    }

    /**
     * Restores a withdrawn or removed registration to the status it left from: registered (it takes its seats again)
     * or waitlisted (at its place by submit time).
     *
     * Runs kalaka/restore-registration as the global --user; a restore is full access only (without --user the CLI
     * has full access). A full pool never refuses it: the seat goes over the limit, and the output says so.
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * <code>
     * : The registration's reference code.
     *
     * [--reason=<text>]
     * : Why, up to 500 characters. Written to the audit entry.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function restore(array $args, array $assoc): void
    {
        Boundary::cli(fn () => $this->statusAct(StatusAct::Restore, $args, $assoc));
    }

    /**
     * The wrapper of a status act: arguments in, the act as a preview first, the question, then the use case through
     * the runner, the outcome printed. Every rule (which status allows the act, the reason) is the use case's; a
     * refusal prints its own message. The command holds no rule of its own; `--reason` is optional for every act.
     * [UNAPPROVED N522]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function statusAct(StatusAct $act, array $args, array $assoc): void
    {
        if (count($args) < 2) {
            $error = new CommandFailure('Give the event and the registration code.');
            throw $error;
        }
        $reason = isset($assoc['reason']) && is_string($assoc['reason']) ? $assoc['reason'] : null;
        $actor = new CliActor(get_current_user_id() ?: null);
        $key = new RegistrationKey((int) $args[0], $args[1]);
        // The act itself says what it would do (the same refusals, nothing written); then the operator confirms.
        $preview = $this->actions->run($act, $key, null, $actor, true);
        if ($preview['changed'] !== true) {
            \WP_CLI::success($args[1] . ' is already ' . (string) $preview['status'] . '.');

            return;
        }
        foreach ((array) $preview['warnings'] as $warning) {
            \WP_CLI::warning((string) $warning);
        }
        foreach ((array) ($preview['money'] ?? []) as $row) {
            $row = (array) $row;
            \WP_CLI::line(
                'Money, ' . (string) $row['payer'] . ': paid ' . (string) $row['paid'] . ', balance '
                . (string) $row['balance_now'] . ' now, ' . (string) $row['balance_after'] . ' after.'
            );
        }
        \WP_CLI::confirm($act->value . ' ' . $args[1] . '?', $assoc);

        $result = $this->actions->run($act, $key, $reason, $actor);
        $line = $args[1] . ($result['changed'] === true ? ': ' : ' is already ') . (string) $result['status'] . '.';
        if ($result['over_capacity'] === true) {
            \WP_CLI::warning(AssignmentSets::FULL_WARNING);
            $line .= ' The event is over its capacity now.';
        }
        \WP_CLI::success($line);
        foreach ((array) $result['seats'] as $seat) {
            $seat = (array) $seat;
            $outcome = (string) $seat['outcome'];
            $note = $outcome === SeatOutcome::Over->value ? ' (over the limit)' : '';
            \WP_CLI::line(' - ' . (string) $seat['pool'] . ': ' . $outcome . $note);
        }
    }
}
