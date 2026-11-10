<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Seed;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Api\UseCase\UseCase;
use Kalaka\Foundation\Api\Entry\CommandFailure;

/** What a seeder gets: the way to run use cases as the command line without a user, and the seed day. */
final class Seeding
{
    public function __construct(private readonly Runner $runner, private readonly Clock $clock)
    {
    }

    /**
     * Runs a use case as `CliActor(null)`, which has full access.
     *
     * @param array<string, mixed> $input
     * @throws CommandFailure when the use case fails, naming it
     */
    public function run(UseCase $useCase, array $input): mixed
    {
        try {
            return $this->runner->run($useCase, $input, new CliActor(null));
        } catch (KalakaException $e) {
            $error = new CommandFailure($useCase::name() . ': ' . $e->getMessage(), $e);
            throw $error;
        }
    }

    /** Local 00:00 of the seed day in the site's time zone. */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(wp_timezone())->setTime(0, 0);
    }
}
