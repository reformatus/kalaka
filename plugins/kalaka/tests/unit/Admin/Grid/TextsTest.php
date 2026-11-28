<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin\Grid;

use Kalaka\Admin\Grid\Texts;
use Kalaka\Registrations\Api\RegistrationStatus;
use PHPUnit\Framework\TestCase;

/** The grid's status words: the server's `RegistrationStatus::word()`, capitalised for the grid only. */
final class TextsTest extends TestCase
{
    public function testAStatusKeepsTheServersWordWithACapital(): void
    {
        self::assertSame(
            ['Rögzített', 'Várólistás', 'Lemondott', 'Törölt'],
            array_map(
                static fn (RegistrationStatus $status): string => Texts::status($status),
                RegistrationStatus::cases()
            )
        );
    }
}
