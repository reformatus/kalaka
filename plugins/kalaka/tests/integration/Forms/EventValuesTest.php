<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\ValueType;

/** D5, D6, D25: the event keys of a form page's value context and the date text. */
final class EventValuesTest extends FormsTestCase
{
    public function testTheFiveKeysAreDeclaredWithTheEventsValuesAndTheDatesInHungarian(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $event = $this->events->find($this->published());
        self::assertNotNull($event);

        $values = EventValues::of($event);

        self::assertSame(
            ['event.name', 'event.registration_opens', 'event.registration_closes', 'event.start', 'event.end'],
            array_keys($values)
        );
        self::assertSame(ValueType::Text, $values['event.name']->type);
        self::assertSame('Tábor', $values['event.name']->text);
        self::assertSame(ValueType::Datetime, $values['event.registration_opens']->type);
        self::assertSame('2026-11-30T08:00:00+01:00', $values['event.registration_opens']->data);
        self::assertSame('2026-12-15T20:00:00+01:00', $values['event.registration_closes']->data);
        self::assertSame(ValueType::Date, $values['event.start']->type);
        self::assertSame('2027-07-05', $values['event.start']->data);
        self::assertSame('2026. november 30.', $values['event.registration_opens']->text);
        self::assertSame('2026. december 15.', $values['event.registration_closes']->text);
        self::assertSame('2027. július 5.', $values['event.start']->text);
        self::assertSame('2027. július 12.', $values['event.end']->text);
    }

    public function testAPointThatIsNotSetIsNoneOfItsType(): void
    {
        $event = $this->events->find($this->create());
        self::assertNotNull($event);

        $values = EventValues::of($event);

        foreach (EventValues::TYPES as $key => $type) {
            if ($type === ValueType::Text) {
                continue;
            }
            self::assertSame($type, $values[$key]->type, $key);
            self::assertTrue($values[$key]->isEmpty(), $key);
            self::assertSame('', $values[$key]->text, $key);
        }
    }

    public function testADayIsTheCalendarDayInTheSitesTimeZone(): void
    {
        update_option('timezone_string', 'Pacific/Auckland');
        $event = $this->events->find($this->published());
        self::assertNotNull($event);

        $opens = EventValues::of($event)['event.registration_opens']->data;
        self::assertSame('2026-11-30', substr((string) $opens, 0, 10));
        self::assertSame('2027-07-05', EventValues::of($event)['event.start']->data);
    }

    public function testTheTwoRegistrationKeysAreDatetimesAndTheEventsDaysAreDates(): void
    {
        self::assertSame(ValueType::Datetime, EventValues::TYPES[EventValues::REGISTRATION_OPENS]);
        self::assertSame(ValueType::Datetime, EventValues::TYPES[EventValues::REGISTRATION_CLOSES]);
        self::assertSame(ValueType::Date, EventValues::TYPES[EventValues::START]);
        self::assertSame(ValueType::Date, EventValues::TYPES[EventValues::END]);
    }
}
