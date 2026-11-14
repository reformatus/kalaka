<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;

/**
 * The event keys of a value context and a fact source (events-and-forms.md, "Values and
 * conditions"), declared here and nowhere else: a document or a form that reads the event's values
 * builds them from `of()`. In a form, conditions may read them too: the event's date references
 * (S76), e.g. the age at the event's start.
 *
 * Decision N2 (#156): the same keys and `of()` as the form front end's (p04); a date's text is the
 * Hungarian date text (`DateText`).
 */
final class EventValues
{
    public const NAME = 'event.name';
    public const REGISTRATION_OPENS = 'event.registration_opens';
    public const REGISTRATION_CLOSES = 'event.registration_closes';
    public const START = 'event.start';
    public const END = 'event.end';

    /** Every event key and its type: what a form's condition may read besides the earlier answers. */
    public const TYPES = [
        self::NAME => ValueType::Text,
        self::REGISTRATION_OPENS => ValueType::Date,
        self::REGISTRATION_CLOSES => ValueType::Date,
        self::START => ValueType::Date,
        self::END => ValueType::Date,
    ];

    /**
     * Every key is declared; a date point that is not set is `Value::none()`. A point is a calendar day
     * in the site's time zone.
     *
     * @return array<string, Value> key → value, for `new ValueContext()`
     */
    public static function of(Event $event): array
    {
        return [
            self::NAME => Value::text($event->name, $event->name),
            self::REGISTRATION_OPENS => self::day($event->registrationOpens),
            self::REGISTRATION_CLOSES => self::day($event->registrationCloses),
            self::START => self::day($event->start),
            self::END => self::day($event->end),
        ];
    }

    private static function day(?\DateTimeImmutable $point): Value
    {
        if ($point === null) {
            return Value::none(ValueType::Date);
        }
        $day = $point->setTimezone(wp_timezone())->format('Y-m-d');

        return Value::date($day, DateText::of($day));
    }
}
