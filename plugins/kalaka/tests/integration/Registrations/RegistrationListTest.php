<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Registrations\RegistrationList;

/** AC11: the sorszám is computed from the status and the creation order. */
final class RegistrationListTest extends RegistrationsTestCase
{
    /** @var list<string> codes whose order differs from the order of creation */
    private array $codes = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->codes = array_slice(array_reverse(ReferenceCode::fourCharacterCodes()), 0, 6);
    }

    /** @return array<string, ?int> the sorszám by code */
    private function serials(int $event): array
    {
        $serials = [];
        foreach ((new RegistrationList())->forEvent($event) as $row) {
            $serials[$row['code']] = $row['serial'];
        }

        return $serials;
    }

    public function testTheSorszamCountsWithinEachStatusInCreationOrder(): void
    {
        $statuses = [
            RegistrationStatus::Registered,
            RegistrationStatus::Registered,
            RegistrationStatus::Waitlisted,
            RegistrationStatus::Registered,
            RegistrationStatus::Waitlisted,
            RegistrationStatus::Registered,
        ];
        foreach ($statuses as $i => $status) {
            $this->insertRegistration($this->newRegistration(5, $this->codes[$i], $status, '2026-11-30 08:00:0' . $i));
        }
        $this->insertRegistration(
            $this->newRegistration(6, '12A3', RegistrationStatus::Registered, '2026-11-30 07:00:00')
        );
        [$r1, $r2, $r3, $r4, $r5, $r6] = $this->codes;

        $this->setStatus(new RegistrationKey(5, $r2), RegistrationStatus::Withdrawn);
        self::assertSame([$r1 => 1, $r2 => null, $r3 => 1, $r4 => 2, $r5 => 2, $r6 => 3], $this->serials(5));

        $this->setStatus(new RegistrationKey(5, $r3), RegistrationStatus::Registered);
        self::assertSame([$r1 => 1, $r2 => null, $r3 => 2, $r4 => 3, $r5 => 1, $r6 => 4], $this->serials(5));

        $this->setStatus(new RegistrationKey(5, $r4), RegistrationStatus::Removed);
        self::assertSame([$r1 => 1, $r2 => null, $r3 => 2, $r4 => null, $r5 => 1, $r6 => 3], $this->serials(5));
    }

    public function testTheRowsCarryTheirTimesAndComeInCreationOrder(): void
    {
        $this->insertRegistration(
            $this->newRegistration(5, $this->codes[0], RegistrationStatus::Waitlisted, '2026-11-30 08:00:01')
        );
        $this->insertRegistration(
            $this->newRegistration(5, $this->codes[1], RegistrationStatus::Registered, '2026-11-30 08:00:00')
        );

        $rows = (new RegistrationList())->forEvent(5);

        self::assertSame([$this->codes[1], $this->codes[0]], array_column($rows, 'code'));
        self::assertSame(RegistrationStatus::Registered, $rows[0]['status']);
        self::assertSame('2026-11-30 08:00:00.000000', $rows[0]['created_at']);
        self::assertSame('2026-11-30 08:00:00.000000', $rows[0]['registered_at']);
        self::assertNull($rows[1]['registered_at']);
    }

    public function testTwoRegistrationsWithTheSameNameAreBothListed(): void
    {
        foreach ([0, 1] as $i) {
            $this->insertRegistration(
                $this->newRegistration(5, $this->codes[$i], RegistrationStatus::Registered, '2026-11-30 08:00:0' . $i)
            );
            $this->inRun(function (array $input, Context $context) use ($i): void {
                $this->lockRegistrations($context, new RegistrationKey(5, $this->codes[$i]));
                $this->values->upsert(
                    new FieldValue(new RegistrationKey(5, $this->codes[$i]), 'name', 'Teszt Anna'),
                    $context
                );
            });
        }

        self::assertSame([$this->codes[0] => 1, $this->codes[1] => 2], $this->serials(5));
    }

    public function testRegistrationsCreatedMicrosecondsApartAreOrderedByTheirTimesWhateverTheirCodes(): void
    {
        [$low, $high] = [ReferenceCode::fourCharacterCodes()[0], ReferenceCode::fourCharacterCodes()[4999]];
        $this->insertRegistration(
            $this->newRegistration(5, $low, RegistrationStatus::Registered, '2026-11-30 08:00:00.000400')
        );
        $this->insertRegistration(
            $this->newRegistration(5, $high, RegistrationStatus::Registered, '2026-11-30 08:00:00.000100')
        );

        self::assertSame([$high => 1, $low => 2], $this->serials(5));
        self::assertSame([$high, $low], array_keys($this->serials(5)));
    }

    public function testRegistrationsCreatedAtTheSameInstantAreOrderedByCode(): void
    {
        [$low, $high] = [ReferenceCode::fourCharacterCodes()[0], ReferenceCode::fourCharacterCodes()[4999]];
        $this->insertRegistration($this->newRegistration(5, $high));
        $this->insertRegistration($this->newRegistration(5, $low));

        self::assertSame([$low => 1, $high => 2], $this->serials(5));
    }
}
