<?php

declare(strict_types=1);

namespace Kalaka\Support\Share;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * What the share needs checked before it is proposed or saved, for the preview and the save alike: its two field
 * keys must not be held by a form, staff or system field of the event, or by an extension's field of another
 * type (the setup order: turn the share on before the form joins the keys). [UNAPPROVED N154]
 */
final class ShareInput
{
    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when another field holds `share_mode` or `share_amount` */
    public function assertKeysFree(int $eventId): void
    {
        $wanted = [
            ShareComponent::MODE_FIELD => SetShare::CHOICE_TYPE,
            ShareComponent::AMOUNT_FIELD => SetShare::NUMBER_TYPE,
        ];
        foreach ($this->fields->of($eventId, true) as $field) {
            $type = $wanted[$field->key] ?? null;
            if ($type !== null && ($field->source !== ParticipantField::SOURCE_EXTENSION || $field->type !== $type)) {
                throw new InvalidInput();
            }
        }
    }
}
