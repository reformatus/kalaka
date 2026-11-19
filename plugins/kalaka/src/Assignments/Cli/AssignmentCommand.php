<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Cli;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\PlaceRegistration;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Places registrations into assignment sets by hand, until the staff screens (S72/S48).
 *
 * The WP-CLI wrapper only: arguments, printing and the exit code. The work is the use case's.
 */
final class AssignmentCommand
{
    /**
     * Places a registration into an option of a set, or overrides its assignment there.
     *
     * Runs kalaka/place-registration as the global --user (without --user the CLI has full access). The
     * assignment is written confirmed, with the source staff, whatever the registration held in that set;
     * neither the option's capacity nor its eligibility stops it (staff override), but a warning is printed first
     * when the option is full or the registration does not meet its condition.
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * <code>
     * : The registration's reference code.
     *
     * <set>
     * : The set's ID.
     *
     * <option>
     * : The option's key.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function place(array $args, array $assoc): void
    {
        Boundary::cli(static function () use ($args): void {
            $registration = new RegistrationKey((int) $args[0], $args[1]);
            $actor = new CliActor(get_current_user_id() ?: null);
            $input = [
                'event_id' => (int) $args[0],
                'code' => $args[1],
                'set_id' => (int) $args[2],
                'option' => $args[3],
            ];
            // The same permission check the run makes, before any warning: a refused actor learns nothing
            // about the option first.
            AdminAccess::check(new PlaceRegistration(), $input, $actor);
            // Staff override: the placing goes ahead, but they are told first when the option is full or the
            // registration does not meet its condition.
            $warnings = (new AssignmentSets())->placementWarnings($registration, (int) $args[2], $args[3]);
            foreach ($warnings as $warning) {
                \WP_CLI::warning($warning);
            }
            Runner::current()->run(new PlaceRegistration(), $input, $actor);
            \WP_CLI::success('Placed ' . $args[1] . ' into ' . $args[3] . '.');
        });
    }
}
