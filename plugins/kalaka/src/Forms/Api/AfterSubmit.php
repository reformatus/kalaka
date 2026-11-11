<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;

/**
 * The after-submit section of a form (events-and-forms.md, "Forms implementation"): the block
 * `kalaka/after-submit`, at most one per form and outside any branch, holding the content that
 * replaces the form after a successful submit. It never renders in the form itself; the submit renders
 * its inner blocks with `Values::render()` and this document kind's context: the event's keys, and the
 * new registration's reference code and status. Conditions and values inside it read only those.
 * No field block may sit in it. [UNAPPROVED N12]
 */
final class AfterSubmit
{
    public const BLOCK = 'kalaka/after-submit';

    public const CODE = 'registration.code';

    /** A choice of one: `registered` or `waitlisted`. Its display text is empty until a label is approved. */
    public const STATUS = 'registration.status';

    /** Every key of the after-submit context and its type. */
    public const TYPES = EventValues::TYPES + [
        self::CODE => ValueType::Text,
        self::STATUS => ValueType::Choice,
    ];

    /** The context the after-submit section renders with. */
    public static function context(Event $event, string $code, string $status): ValueContext
    {
        return new ValueContext(EventValues::of($event) + [
            self::CODE => Value::text($code, $code),
            self::STATUS => Value::choice([$status], ''),
        ]);
    }
}
