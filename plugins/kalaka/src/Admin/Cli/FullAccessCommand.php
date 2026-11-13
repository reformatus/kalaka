<?php

declare(strict_types=1);

namespace Kalaka\Admin\Cli;

use Kalaka\Admin\GrantFullAccess;
use Kalaka\Admin\FullAccess\RevokeFullAccess;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Api\UseCase\UseCase;

/**
 * `wp kalaka full-access grant|revoke <user>`: gives or takes a user's full access, for recovery as well.
 * It runs as the command line without a user (full access), or, with `--user`, checked as that user.
 */
final class FullAccessCommand
{
    public static function register(): void
    {
        \WP_CLI::add_command('kalaka full-access', new self(), [
            'shortdesc' => 'Gives or takes full access to Kaláka.',
        ]);
    }

    /**
     * Gives a user full access to Kaláka.
     *
     * ## OPTIONS
     *
     * <user>
     * : The user ID, login or email.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function grant(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $user = self::user($args[0] ?? '');
            self::run(new GrantFullAccess(), $user);
            \WP_CLI::success('User ' . $user->user_login . ' has full access.');
        });
    }

    /**
     * Takes full access to Kaláka from a user, unless they are its last holder.
     *
     * ## OPTIONS
     *
     * <user>
     * : The user ID, login or email.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function revoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $user = self::user($args[0] ?? '');
            self::run(new RevokeFullAccess(), $user);
            \WP_CLI::success('User ' . $user->user_login . ' has no full access.');
        });
    }

    private static function run(UseCase $useCase, \WP_User $user): void
    {
        $current = get_current_user_id();
        Runner::current()->run($useCase, ['user_id' => $user->ID], new CliActor($current > 0 ? $current : null));
    }

    /** @throws CommandFailure when no user matches */
    private static function user(string $given): \WP_User
    {
        $user = ctype_digit($given) ? get_user_by('id', (int) $given) : false;
        if ($user === false && $given !== '') {
            $user = get_user_by('login', $given);
        }
        if ($user === false && $given !== '') {
            $user = get_user_by('email', $given);
        }
        if ($user === false) {
            $error = new CommandFailure('No user found: ' . $given);
            throw $error;
        }

        return $user;
    }
}
