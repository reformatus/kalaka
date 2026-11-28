<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\SlotRequirement;

/** A slot requirement that fails: the page must not fail with it (modules-and-extensions.md, "Failure isolation"). */
final class ThrowingSlotRequirement implements SlotRequirement
{
    public function requiredKeys(int $eventId): array
    {
        throw new \RuntimeException('lejárt a kérés');
    }
}
