<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Admin\AdminTestCase;

/** `Registrations::statusesOf()`: the statuses of some codes of one event, in one statement. */
final class StatusesOfTest extends AdminTestCase
{
    public function testItReadsTheStatusesOfTheGivenCodesOfTheEventInOneStatement(): void
    {
        $event = $this->createEvent();
        $other = $this->createEvent('Másik');
        $this->addRegistration($event, '00A0');
        $this->addRegistration($event, '00B1', 'waitlisted');
        $this->addRegistration($event, '00C2', 'withdrawn');
        $this->addRegistration($event, '00D3', 'removed');
        $this->addRegistration($other, '00E4');

        $statuses = [];
        $queries = [];
        $count = static function (string $query) use (&$queries): string {
            if (str_contains($query, 'kalaka_registrations')) {
                $queries[] = $query;
            }

            return $query;
        };
        add_filter('query', $count);
        try {
            $statuses = (new Registrations())->statusesOf($event, ['00A0', '00B1', '00C2', '00D3', '00E4', '00F5']);
        } finally {
            remove_filter('query', $count);
        }

        self::assertSame([
            '00A0' => RegistrationStatus::Registered,
            '00B1' => RegistrationStatus::Waitlisted,
            '00C2' => RegistrationStatus::Withdrawn,
            '00D3' => RegistrationStatus::Removed,
        ], $statuses);
        self::assertCount(1, $queries);
    }

    public function testNoCodesIsNoStatement(): void
    {
        self::assertSame([], (new Registrations())->statusesOf($this->createEvent(), []));
    }
}
