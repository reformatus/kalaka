<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Saves an event's donation setting, the whole-number field whose value is charged (null removes it): a step of a
 * money settings apply (`Pricing\Api\MoneySettings::apply()` with `Api\Donation::propose()`), which then
 * re-evaluates every open registration. Saving alone touches no registration. Site-level, like core's fixed
 * price: full access only.
 */
final class SetDonation implements AdminUseCase
{
    public function __construct(
        private readonly DonationRepository $donations = new DonationRepository(),
        private readonly Events $events = new Events(),
        private readonly DonationField $field = new DonationField(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-donation';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'field_key' => ['type' => ['string', 'null'], 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
            ],
            'required' => ['event_id', 'field_key'],
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
        $fieldKey = is_string($input['field_key']) ? $input['field_key'] : null;
        $stored = $this->donations->findForUpdate($eventId, $context);
        if ($fieldKey === null) {
            if ($stored !== null) {
                $this->donations->delete($stored, $context);
            }

            return null;
        }
        $this->field->check($eventId, $fieldKey);
        $record = $stored ?? new DonationRecord($eventId, $fieldKey);
        $record->fieldKey = $fieldKey;
        $this->donations->upsert($record, $context);

        return null;
    }
}
