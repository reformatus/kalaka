<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\EntryFixture\Cli;

/** A WP-CLI command whose method skips the boundary: the entry-point check must find it. */
final class UnguardedCommand
{
    public static function register(): void
    {
        \WP_CLI::add_command('kalaka fixture', new self());
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        \WP_CLI::success('done');
    }
}
