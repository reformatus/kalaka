<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Registrations\Api\RegistrationStatus;
use PHPUnit\Framework\TestCase;

/** The status words a document shows for `registration.status`: one place for a mail and the after-submit section. */
final class RegistrationStatusTest extends TestCase
{
    public function testEveryStatusHasItsWord(): void
    {
        self::assertSame(
            ['rögzített', 'várólistás', 'visszavont', 'törölt'],
            array_map(static fn (RegistrationStatus $status): string => $status->word(), RegistrationStatus::cases())
        );
    }
}
