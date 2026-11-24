<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventPoint;

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

    /**
     * What a registration form's condition may read besides the earlier answers: the event's keys and
     * `account.logged_in` (`SignUp::LOGGED_IN`, owner ruling 2026-11-18). Mails and the after-submit section
     * read only `TYPES`.
     */
    public const FORM_TYPES = self::TYPES + [SignUp::LOGGED_IN => ValueType::Choice];

    /**
     * `FORM_TYPES`' values: the event's, and `account.logged_in`: `yes` or `no` where the form has an account
     * step (`$loggedIn` says whether someone is logged in), none where it has none (`$loggedIn` null: account
     * mode off, or an additional form). True, false and null stay apart, never conflated (owner ruling
     * 2026-11-18). The text of yes and no is „Igen” and „Nem” (`yesNo()`). `now` is the day of `$at`, else of the
     * runner's clock (`now()`).
     *
     * @return array<string, Value>
     */
    public static function form(Event $event, ?bool $loggedIn, \DateTimeImmutable $at): array
    {
        $fact = $loggedIn === null
            ? Value::none(ValueType::Choice)
            : self::yesNo($loggedIn);

        return self::of($event) + self::now($at) + [SignUp::LOGGED_IN => $fact];
    }

    /**
     * The key a date reference to "now" reads (`Facts::NOW`): the day of the clock's time in the site's time
     * zone, of the moment `$at` the caller holds (the act's, never a clock read here: one read, one clock). A fact
     * source whose conditions may use the reference adds it to the event's keys; it is no key a condition may
     * name itself, so it is in no `TYPES`.
     *
     * @return array<string, Value>
     */
    public static function now(\DateTimeImmutable $at): array
    {
        return [Facts::NOW => self::day($at)];
    }

    /**
     * A yes/no fact's value: the choice `yes` or `no`, shown as „Igen” or „Nem” (the texts of the participant-field
     * prompt's buttons, already approved UI text). Both account facts (`account.logged_in`, `account.unverified`)
     * use it.
     */
    public static function yesNo(bool $yes): Value
    {
        return Value::choice([$yes ? 'yes' : 'no'], $yes ? 'Igen' : 'Nem');
    }

    /** The places left (a number); a form's condition reads it, no mail or document does (`volatile()`). */
    public const SEATS_LEFT = 'event.seats_left';

    /**
     * Before a pool's field key and option value: the places left in a set option, price tier or support offer
     * (`seats_left.<field key>.<option value>`), for a form's conditions only (`EventFormHost`).
     */
    public const POOL_SEATS_PREFIX = 'seats_left.';

    /** Before a custom point's ID: the point's date (`event.points.<id>`), for a form's conditions only. */
    public const POINT_PREFIX = 'event.points.';

    /** Every event key and its type. */
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

    /**
     * The types of the keys only a form's conditions read, which change on their own or are the event's own
     * extras (CF2; the owner's convention 2026-11-23: whatever decides visibility is a value a condition can
     * read). They are not in `of()`, which a mail, the after-submit section and `RegistrationFacts` also read.
     *
     * @return array<string, ValueType>
     */
    public static function volatileTypes(Event $event): array
    {
        $types = [self::SEATS_LEFT => ValueType::Number];
        foreach ($event->customPoints as $point) {
            $types[self::POINT_PREFIX . $point->id] = ValueType::Date;
        }

        return $types;
    }

    /**
     * The values of `volatileTypes()`. `event.seats_left` is the event pool's places left, given in
     * (`Registrations\Api\Seats::left()`, the one computation: 0 while the event's "full" latch is set, none while
     * the event has no capacity, a draft without one included). A custom point is a day in the site's time zone,
     * like the event's other points. `choice_open.<key>` is yes or no.
     *
     * @param int|null $seatsLeft the event pool's places left; null for no limit
     * @param array<string, bool> $choiceOpen request field key → whether its set's window holds now
     * @return array<string, Value>
     */
    public static function volatile(Event $event, ?int $seatsLeft, array $choiceOpen): array
    {
        $values = [self::SEATS_LEFT => self::places($seatsLeft)];
        foreach ($event->customPoints as $point) {
            /** @var EventPoint $point */
            $values[self::POINT_PREFIX . $point->id] = self::day($point->at);
        }

        return $values;
    }

    /** The places left of a pool as a number value; none for a pool with no limit. */
    public static function places(?int $left): Value
    {
        return $left === null
            ? Value::none(ValueType::Number)
            : Value::number((string) $left, (string) $left);
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
