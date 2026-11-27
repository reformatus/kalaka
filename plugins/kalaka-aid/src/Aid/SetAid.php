<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Saves an event's adjudicated aid, the label the organizers give it, or turns it off with no label: a step of a
 * money settings apply (`Pricing\Api\MoneySettings::apply()` with `Api\Aid::propose()`), which then re-evaluates
 * every open registration. Saving alone touches no registration. Site-level, like core's fixed price: full access
 * only. Turning aid off is refused (`AidInUse`) while an open registration has a request awaiting an answer or holds an
 * award above zero; with none it deletes the setting only: the stored requests and awards (refusals, 0) stay, and
 * the apply takes the aid lines away. Turning aid on again revives them.
 *
 * With a label it declares the aid's two participant fields in the same transaction (the owner's rulings
 * 2026-11-23, topic 14: the code defines the field, the form's field links to it by key): `aid_can_pay`, a number
 * the participant states what they can pay in, form-writable, and `aid_award`, a number written only by the aid
 * request source (the answer act), never by a form.
 *
 * The two fields are declared as declarer `aid` (the module's ID), money relevant (locked after their first
 * write), and their keys are judged by core's `ParticipantFields::checkDeclarable()`.
 */
final class SetAid implements AdminUseCase
{
    /** The block type of a number field, core's. */
    public const NUMBER_TYPE = 'kalaka/number-field';

    /** The declarer this module declares its fields with (`declared_by`) and writes them under: its module ID. */
    public const DECLARER = 'aid';

    /** The title of `aid_can_pay`, the declarer's text. */
    public const CAN_PAY_TITLE = 'Befizetni vállalt összeg';

    /** The title of `aid_award`, the declarer's text. */
    public const AWARD_TITLE = 'Megítélt összeg';

    public function __construct(
        private readonly AidSettingsRepository $settings = new AidSettingsRepository(),
        private readonly Events $events = new Events(),
        private readonly AidInput $input = new AidInput(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly AidUse $use = new AidUse(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-aid';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'label' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => AidInput::MAX_LABEL],
            ],
            'required' => ['event_id', 'label'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $eventId = (int) $input['event_id'];
        $this->events->find($eventId) ?? throw new NotFound();
        $label = $input['label'] === null ? null : (string) $input['label'];

        $stored = $this->settings->findForUpdate($eventId, $context);
        if ($label === null) {
            if ($stored !== null) {
                $this->use->assertNotInUse($eventId);
                $this->settings->delete($stored, $context);
            }

            return null;
        }
        if ($label !== trim($label) || $label === '') {
            throw new InvalidInput();
        }
        $this->input->assertLabelAllowed($label);
        $this->input->checkKeysDeclarable($eventId);
        if ($stored === null) {
            $this->settings->upsert(new AidSettings($eventId, $label), $context);
        } else {
            $stored->label = $label;
            $this->settings->upsert($stored, $context);
        }
        $this->fields->declareExtensionField(
            $context,
            $eventId,
            AidRequests::CAN_PAY_FIELD,
            self::NUMBER_TYPE,
            self::CAN_PAY_TITLE,
            null,
            true,
            self::DECLARER,
            moneyRelevant: true,
        );
        $this->fields->declareExtensionField(
            $context,
            $eventId,
            AidComponent::AWARD_FIELD,
            self::NUMBER_TYPE,
            self::AWARD_TITLE,
            null,
            false,
            self::DECLARER,
            moneyRelevant: true,
        );

        return null;
    }
}
