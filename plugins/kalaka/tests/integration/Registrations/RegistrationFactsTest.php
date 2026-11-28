<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\Api\Events;
use Kalaka\Events\EventRecord;
use Kalaka\Events\EventRepository;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Registrations\Api\ItemChosen;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The registration fact source, with the field values (S25), and the open registrations of an event. */
final class RegistrationFactsTest extends RegistrationsTestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC'));
    }

    public function testTimeNowIsTheMomentTheCallerPasses(): void
    {
        $event = $this->event();
        $this->insertRegistration($this->newRegistration($event->id, '00A0'));
        $key = new RegistrationKey($event->id, '00A0');

        $first = (new Registrations())->factsOf($key, $event, self::now());
        $later = (new Registrations())->factsOf($key, $event, self::now()->modify('+40 days'));

        self::assertNotNull($first);
        self::assertNotNull($later);
        self::assertSame('2026-11-30T08:00:00+00:00', $first->get(Facts::NOW)->data);
        self::assertSame('2027-01-09T08:00:00+00:00', $later->get(Facts::NOW)->data);
    }

    public function testTheFactsHoldTheEventValuesTheCodeTheStatusAndNoRegisteredTimeWhileWaitlisted(): void
    {
        $event = $this->event();
        $this->insertRegistration(
            $this->newRegistration($event->id, '00A0', RegistrationStatus::Waitlisted, '2026-12-10 09:30:00')
        );

        $facts = (new Registrations())->factsOf(new RegistrationKey($event->id, '00A0'), $event, self::now());

        self::assertInstanceOf(RegistrationFacts::class, $facts);
        self::assertSame('00A0', $facts->get(RegistrationFacts::CODE)->data);
        self::assertSame(['waitlisted'], $facts->get(RegistrationFacts::STATUS)->data);
        self::assertSame('várólistás', $facts->get(RegistrationFacts::STATUS)->text);
        self::assertSame('Teszt tábor', $facts->get(EventValues::NAME)->data);
        self::assertNull($facts->registeredAt);
        self::assertNull((new Registrations())->factsOf(new RegistrationKey($event->id, '00A2'), $event, self::now()));

        $this->expectException(SystemFailure::class);
        $facts->get('megjegyzes');
    }

    public function testTheDeclaredKeysNameTheAwaitsAnswerFactAChoice(): void
    {
        $declared = RegistrationFacts::declared($this->event(), []);

        self::assertSame(ValueType::Choice, $declared[RegistrationFacts::AWAITS_ANSWER]);
        self::assertSame(ValueType::Choice, $declared[RegistrationFacts::STATUS]);
        self::assertSame(ValueType::Text, $declared[RegistrationFacts::CODE]);
    }

    public function testTheFactsHoldEveryParticipantFieldOfTheEventTypedAndAnUnansweredOneAsNone(): void
    {
        $event = $this->event();
        $this->declareParticipantField($event->id, 'tamogatas', [
            ['value' => 'nincs', 'label' => 'Nincs'],
            ['value' => 'teljes', 'label' => '<strong>Teljes</strong>'],
        ]);
        $this->declareParticipantField($event->id, 'megjegyzes', [], 1);
        $this->insertRegistration($this->newRegistration($event->id, '00A0'));
        $key = new RegistrationKey($event->id, '00A0');
        $this->writeValue($key, 'tamogatas', 'teljes');

        $facts = (new Registrations())->factsOf($key, $event, self::now());

        self::assertNotNull($facts);
        self::assertSame(ValueType::Choice, $facts->get('tamogatas')->type);
        self::assertSame(['teljes'], $facts->get('tamogatas')->data);
        self::assertSame('Teljes', $facts->get('tamogatas')->text);
        self::assertSame(ValueType::Text, $facts->get('megjegyzes')->type);
        self::assertTrue($facts->get('megjegyzes')->isEmpty());
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

    public function testAnOpenRegistrationHoldingAValueIsFoundAndAWithdrawnOneIsNot(): void
    {
        $event = $this->event();
        $this->insertRegistration($this->newRegistration($event->id, '00A0', RegistrationStatus::Withdrawn));
        $this->insertRegistration($this->newRegistration($event->id, '00A2', RegistrationStatus::Registered));
        global $wpdb;
        foreach (['00A0' => 'a', '00A2' => 'b'] as $code => $value) {
            $wpdb->insert($wpdb->prefix . 'kalaka_field_values', [
                'event_id' => $event->id,
                'code' => $code,
                'field_key' => 'tier',
                'version' => 1,
                'value' => $value,
            ]);
        }
        $registrations = new Registrations();

        self::assertFalse($registrations->openHolds($event->id, 'tier', 'a'));
        self::assertTrue($registrations->openHolds($event->id, 'tier', 'b'));
        self::assertFalse($registrations->openHolds($event->id, 'tier', 'c'));
        self::assertFalse($registrations->openHolds($event->id, 'other', 'b'));

        $registrations->refuseRemovingChosen($event->id, 'tier', 'a');
        try {
            $registrations->refuseRemovingChosen($event->id, 'tier', 'b');
            self::fail('A value an open registration holds cannot be removed.');
        } catch (ItemChosen $error) {
            self::assertSame('kalaka_item_chosen', $error->errorCode());
            self::assertSame('A művelet nem hajtható végre.', $error->getMessage());
        }
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
