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
        $event = $this->events->find($this->published());
        self::assertNotNull($event);

        $values = EventValues::of($event);

        self::assertSame(
            ['event.name', 'event.registration_opens', 'event.registration_closes', 'event.start', 'event.end'],
            array_keys($values)
        );
        self::assertSame(ValueType::Text, $values['event.name']->type);
        self::assertSame('Tábor', $values['event.name']->text);
        self::assertSame(ValueType::Date, $values['event.registration_opens']->type);
        self::assertSame('2026-11-30', $values['event.registration_opens']->data);
        self::assertSame('2026. november 30.', $values['event.registration_opens']->text);
        self::assertSame('2026. december 15.', $values['event.registration_closes']->text);
        self::assertSame('2027. július 5.', $values['event.start']->text);
        self::assertSame('2027. július 12.', $values['event.end']->text);
    }

    public function testADayThatIsNotSetIsNoneOfTheDateType(): void
    {
        $event = $this->events->find($this->create());
        self::assertNotNull($event);

        $values = EventValues::of($event);

        foreach (['event.registration_opens', 'event.registration_closes', 'event.start', 'event.end'] as $key) {
            self::assertSame(ValueType::Date, $values[$key]->type, $key);
            self::assertTrue($values[$key]->isEmpty(), $key);
            self::assertSame('', $values[$key]->text, $key);
        }
    }

    public function testADayIsTheCalendarDayInTheSitesTimeZone(): void
    {
        update_option('timezone_string', 'Pacific/Auckland');
        $event = $this->events->find($this->published());
        self::assertNotNull($event);

        self::assertSame('2026-11-30', EventValues::of($event)['event.registration_opens']->data);
    }
}
