<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Extras\Api\Charge;
use Kalaka\Forms\Api\FieldKind;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\FieldDecimalsHeld;
use Kalaka\ParticipantFields\Api\FieldKeyHeld;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * The participant field an extra charge (or the donation) declares, and the check of the field the key already
 * has, for the preview and the save alike: the code defines the field and adopts a `form` field of the same
 * type, so a key `ParticipantFields::checkDeclarable()` refuses (a `staff` or `system` field, a field of another
 * type, another declarer's field) is refused here as the declaration would refuse it, and a number field that
 * multiplies the amount must take whole numbers (decimals 0). It reads the event's participant fields
 * (no post). [UNAPPROVED N13]
 */
final class ChargeField
{
    /** The declarer Extras stores as `declared_by` on the fields it declares: its module's ID. */
    public const DECLARER = 'extras';

    public function __construct(
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly Forms $forms = new Forms(),
    ) {
    }

    /** The participant field type a charge of this type declares. */
    public static function block(string $type): string
    {
        return $type === Charge::NUMBER ? FieldKind::NUMBER : FieldKind::CONSENT;
    }

    /**
     * The charge's type as its field's type says, whatever the field's source: a number for a number field (a
     * `form` field a declaration will adopt included), a consent for anything else, including a key no field has
     * yet. So a shown charge reads back as the operator wrote it, and its document applies again.
     *
     * @param array<string, ParticipantField> $fields the event's fields by key
     */
    public static function typeOf(array $fields, string $key): string
    {
        return ($fields[$key] ?? null)?->type === FieldKind::NUMBER ? Charge::NUMBER : Charge::CONSENT;
    }

    /**
     * @param string $block the block type the code declares under the key
     * @param bool $multiplier whether the field multiplies the charge's amount (a number field then holds whole
     *     numbers only; the donation's number field does not multiply)
     * @throws FieldKeyHeld when `ParticipantFields::checkDeclarable()` refuses the key
     * @throws InvalidInput when the event's number field with that key allows decimals and multiplies
     */
    public function check(int $eventId, string $key, string $block, bool $multiplier = true): void
    {
        $this->fields->checkDeclarable($eventId, $key, $block, self::DECLARER);
        foreach ($this->fields->of($eventId, true) as $field) {
            if ($field->key !== $key) {
                continue;
            }
            if ($multiplier && $block === FieldKind::NUMBER && (int) ($field->settings['decimals'] ?? 0) !== 0) {
                throw new InvalidInput();
            }
        }
    }

    /**
     * A number charge fixes its field's decimals at 0, so no form of the event may hold a number field under the
     * key with other decimals: adopting the key would close it (`SharedKeyCheck`'s `extension_decimals`). It reads
     * the event's form posts (every status whose fields count, `Forms::formsWithOtherDecimals()`), so it runs
     * outside a use case's transaction: at the preview and again just before the apply runs the save, never in
     * the save. The record-based check of `check()` is the one inside it. [UNAPPROVED N808]
     *
     * @throws FieldDecimalsHeld when a form holds the key with other decimals
     */
    public function checkForms(int $eventId, string $key): void
    {
        $forms = $this->forms->formsWithOtherDecimals($eventId, $key, 0);
        if ($forms !== []) {
            $held = new FieldDecimalsHeld($key, $forms);
            throw $held;
        }
    }
}
