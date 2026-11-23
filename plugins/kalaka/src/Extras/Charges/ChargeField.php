<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Extras\Api\Charge;
use Kalaka\Forms\Api\FieldKind;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * The participant field an extra charge (or the donation) declares, and the check of the field the key already
 * has, for the preview and the save alike: the code defines the field and adopts a `form` field of the same
 * type, so a key held by a `staff` or `system` field, or by a field of another type, is refused here as the
 * declaration would refuse it, and a number field that multiplies the amount must take whole numbers
 * (decimals 0). It reads the event's participant fields (no post). [UNAPPROVED N13]
 */
final class ChargeField
{
    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** The participant field type a charge of this type declares. */
    public static function block(string $type): string
    {
        return $type === Charge::NUMBER ? FieldKind::NUMBER : FieldKind::CONSENT;
    }

    /**
     * The charge's type as its declared field says: a number for an `extension` number field, a consent for
     * anything else (including a key no field has yet).
     *
     * @param array<string, ParticipantField> $fields the event's fields by key
     */
    public static function typeOf(array $fields, string $key): string
    {
        $field = $fields[$key] ?? null;
        $declaredNumber = $field !== null
            && $field->source === ParticipantField::SOURCE_EXTENSION
            && $field->type === FieldKind::NUMBER;

        return $declaredNumber ? Charge::NUMBER : Charge::CONSENT;
    }

    /**
     * @param string $block the block type the code declares under the key
     * @param bool $multiplier whether the field multiplies the charge's amount (a number field then holds whole
     *     numbers only; the donation's number field does not multiply)
     * @throws RuleViolation when the key is held by a staff or system field, or by a field of another type
     * @throws InvalidInput when the event's number field with that key allows decimals and multiplies
     */
    public function check(int $eventId, string $key, string $block, bool $multiplier = true): void
    {
        foreach ($this->fields->of($eventId, true) as $field) {
            if ($field->key !== $key) {
                continue;
            }
            // A form field of the same type is adopted by the declaration; a staff or system field never is.
            $declarable = $field->source === ParticipantField::SOURCE_FORM
                || $field->source === ParticipantField::SOURCE_EXTENSION;
            if (!$declarable || $field->type !== $block) {
                throw new RuleViolation();
            }
            if ($multiplier && $block === FieldKind::NUMBER && (int) ($field->settings['decimals'] ?? 0) !== 0) {
                throw new InvalidInput();
            }
        }
    }
}
