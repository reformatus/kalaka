<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\Api\Events;
use Kalaka\Events\EventRecord;
use Kalaka\Events\EventRepository;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The registration fact source and the open registrations of an event. */
final class RegistrationFactsTest extends RegistrationsTestCase
{
    public function testTheFactsHoldTheEventValuesTheCodeTheStatusAndTheSubmitTime(): void
    {
        $event = $this->event();
        $this->insertRegistration(
            $this->newRegistration($event->id, '00A0', RegistrationStatus::Waitlisted, '2026-12-10 09:30:00')
        );

        $facts = (new Registrations())->factsOf(new RegistrationKey($event->id, '00A0'), $event);

        self::assertInstanceOf(RegistrationFacts::class, $facts);
        self::assertSame('00A0', $facts->get(RegistrationFacts::CODE)->data);
        self::assertSame(['waitlisted'], $facts->get(RegistrationFacts::STATUS)->data);
        self::assertSame('Teszt tábor', $facts->get(EventValues::NAME)->data);
        self::assertSame('2026-12-10 09:30:00', $facts->submittedAt->format('Y-m-d H:i:s'));
        self::assertNull((new Registrations())->factsOf(new RegistrationKey($event->id, '00A2'), $event));

        $this->expectException(SystemFailure::class);
        $facts->get('megjegyzes');
    }

    public function testTheOpenRegistrationsAreTheRegisteredAndWaitlistedOnes(): void
    {
        $event = $this->event();
        foreach (
            [
                '00A0' => RegistrationStatus::Registered,
                '00A2' => RegistrationStatus::Waitlisted,
                '00A5' => RegistrationStatus::Withdrawn,
                '00A8' => RegistrationStatus::Removed,
            ] as $code => $status
        ) {
            $this->insertRegistration($this->newRegistration($event->id, $code, $status));
        }

        $codes = array_map(
            static fn (RegistrationKey $key): string => $key->code,
            (new Registrations())->openOf($event->id)
        );

        self::assertSame(['00A0', '00A2'], $codes);
    }

    private function event(): \Kalaka\Events\Api\Event
    {
        $stored = $this->inRun(
            fn (array $input, Context $context): EventRecord
                => (new EventRepository())->upsert(new EventRecord('Teszt tábor'), $context)
        );
        self::assertInstanceOf(EventRecord::class, $stored);
        $event = (new Events())->find($stored->id);
        self::assertNotNull($event);

        return $event;
    }
}
