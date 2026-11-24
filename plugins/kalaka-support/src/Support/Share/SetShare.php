<?php

declare(strict_types=1);

namespace Kalaka\Support\Share;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Support\Offers\SetSupportOffers;

/**
 * Turns an event's congregation share on or off: a step of a money settings apply (`Pricing\Api\MoneySettings::apply()`
 * with `Api\Share::propose()`), which then re-evaluates every open registration. Saving alone touches no
 * registration. Site-level, like core's fixed price: full access only. On, it keeps a row and declares the
 * share's two participant fields in the same transaction (the owner's rulings 2026-11-23, topic 14: the code
 * defines the field, the form's field links to it by key). Off, it deletes the row only: the fields and their
 * values stay, and the apply takes the pairs away (the component writes none for an event without the share).
 * [UNAPPROVED N142, N143, N152, N153, N154]
 */
final class SetShare implements AdminUseCase
{
    /** The declarer the share's fields are declared with (`declared_by`): the support module's ID, as the offers'. */
    public const DECLARER = SetSupportOffers::DECLARER;

    /** The block types of the two fields, core's. */
    public const CHOICE_TYPE = 'kalaka/choice-field';
    public const NUMBER_TYPE = 'kalaka/number-field';

    /** The title of `share_mode`. [UNAPPROVED T60] */
    public const MODE_TITLE = 'Gyülekezeti hozzájárulás';

    /** The labels of the three modes. [UNAPPROVED T61, T62, T63] */
    public const NONE_LABEL = 'A gyülekezet nem fizet a részvételi díjból';
    public const SHARED_LABEL = 'A gyülekezet a részvételi díj egy részét fizeti';
    public const FULL_LABEL = 'A gyülekezet a teljes részvételi díjat fizeti';

    /** The title of `share_amount`. [UNAPPROVED T64] */
    public const AMOUNT_TITLE = 'Gyülekezeti hozzájárulás összege';

    public function __construct(
        private readonly ShareSettingsRepository $settings = new ShareSettingsRepository(),
        private readonly Events $events = new Events(),
        private readonly ShareInput $input = new ShareInput(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-share';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'on' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'on'],
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

        $stored = $this->settings->findForUpdate($eventId, $context);
        if ($input['on'] !== true) {
            if ($stored !== null) {
                $this->settings->delete($stored, $context);
            }

            return null;
        }
        $this->input->checkKeysDeclarable($eventId);
        if ($stored === null) {
            $this->settings->upsert(new ShareSettings($eventId), $context);
        }
        $this->fields->declareExtensionField(
            $context,
            $eventId,
            ShareComponent::MODE_FIELD,
            self::CHOICE_TYPE,
            self::MODE_TITLE,
            [
                ['value' => ShareComponent::NONE, 'label' => self::NONE_LABEL],
                ['value' => ShareComponent::SHARED, 'label' => self::SHARED_LABEL],
                ['value' => ShareComponent::FULL, 'label' => self::FULL_LABEL],
            ],
            true,
            self::DECLARER,
            moneyRelevant: true,
        );
        $this->fields->declareExtensionField(
            $context,
            $eventId,
            ShareComponent::AMOUNT_FIELD,
            self::NUMBER_TYPE,
            self::AMOUNT_TITLE,
            null,
            true,
            self::DECLARER,
            moneyRelevant: true,
        );

        return null;
    }
}
