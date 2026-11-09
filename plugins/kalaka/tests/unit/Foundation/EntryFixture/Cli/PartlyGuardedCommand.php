<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\EntryFixture\Cli;

use Kalaka\Foundation\Api\Entry\Boundary;

/**
 * The entry-point check's edge cases: a method without a body is skipped, a guarded method passes
 * even with a closure in it, and a method without a visibility keyword is public and is checked.
 */
abstract class PartlyGuardedCommand
{
    /** @param list<string> $args */
    abstract public function plan(array $args): void;

    /** @param list<string> $args */
    public function run(array $args): void
    {
        Boundary::cli(static function () use ($args): void {
            \WP_CLI::success((string) count($args));
        });
    }

    // phpcs:ignore Squiz.Scope.MethodScope.Missing, PSR2.Methods.MethodDeclaration -- the case under test
    function bare(): void
    {
        \WP_CLI::success('done');
    }
}
