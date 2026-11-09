<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Cli;

use Kalaka\Foundation\Seed\Seed;
use Kalaka\Foundation\Api\Entry\Boundary;

/** The WP-CLI command `wp kalaka seed [--drop]`: parses the flag and prints; the boundary maps a stop to exit code 1. */
final class SeedCommand
{
    /** @param \Closure(): Seed $seed */
    public function __construct(private readonly \Closure $seed)
    {
    }

    /** @param \Closure(): Seed $seed */
    public static function register(\Closure $seed): void
    {
        \WP_CLI::add_command('kalaka seed', new self($seed), [
            'shortdesc' => 'Fills a local or development site with sample users and events.',
            'synopsis' => [
                [
                    'type' => 'flag',
                    'name' => 'drop',
                    'optional' => true,
                    'description' => 'Drop and recreate the Kaláka tables first.',
                ],
            ],
        ]);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($assocArgs): void {
            $message = ($this->seed)()->run(
                !empty($assocArgs['drop']),
                wp_get_environment_type(),
                static function (string $line): void {
                    \WP_CLI::line($line);
                }
            );
            \WP_CLI::success($message);
        });
    }
}
