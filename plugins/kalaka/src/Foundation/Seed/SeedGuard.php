<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Seed;

use Kalaka\Foundation\Api\Entry\CommandFailure;

/**
 * The seed and the development drop run only on local and development sites. WordPress reads an
 * unconfigured site as production, so that refuses too.
 */
final class SeedGuard
{
    /** @throws CommandFailure unless the environment type is `local` or `development` */
    public function check(string $environmentType): void
    {
        if (!in_array($environmentType, ['local', 'development'], true)) {
            $error = new CommandFailure(
                'Refused: the environment type is "' . $environmentType
                . '"; the seed runs only on local or development sites.'
            );
            throw $error;
        }
    }
}
