<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\RegistrationTables;
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

    public function testOnlyAWaitlistedRegistrantSeesNoDetails(): void
    {
        self::assertTrue(RegistrationStatus::Registered->showsDetails());
        self::assertFalse(RegistrationStatus::Waitlisted->showsDetails());
        self::assertTrue(RegistrationStatus::Withdrawn->showsDetails());
        self::assertTrue(RegistrationStatus::Removed->showsDetails());
    }

    /** Owner 2026-11-27: active means registered only; waitlisted, withdrawn and removed are inactive. */
    public function testOnlyARegisteredRegistrationIsActive(): void
    {
        self::assertTrue(RegistrationStatus::Registered->isActive());
        self::assertFalse(RegistrationStatus::Waitlisted->isActive());
        self::assertFalse(RegistrationStatus::Withdrawn->isActive());
        self::assertFalse(RegistrationStatus::Removed->isActive());
        self::assertSame(['registered'], RegistrationStatus::activeValues());
    }

    public function testTheSqlConditionNamesTheActiveValues(): void
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => "'" . $value . "'",
            RegistrationStatus::activeValues()
        ));

        self::assertSame("status IN ({$quoted})", RegistrationTables::isActive());
        self::assertSame("r.status IN ({$quoted})", RegistrationTables::isActive('r'));
    }
}
