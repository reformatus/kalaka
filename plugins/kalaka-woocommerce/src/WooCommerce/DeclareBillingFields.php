<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Declares an event's five billing participant fields, source `extension` and form-writable, as declarer
 * `woocommerce` (events-and-forms.md, "Fields"): `billing_name`, `billing_country`, `billing_postcode`,
 * `billing_city` and `billing_address`, the code's own keys that a form field joins by key. A `form` field
 * of the same type under a key is adopted; a staff or system field, another type or another declarer's
 * field refuses the run. Run by `wp kalaka card-billing <event>`; there is no screen. (The owner's ruling
 * 2026-11-26.) [UNAPPROVED N821]
 */
final class DeclareBillingFields implements AdminUseCase
{
    public function __construct(
        private readonly Events $events = new Events(),
        private readonly BillingField $field = new BillingField(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/declare-billing-fields';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['event_id'],
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
        $this->field->check($eventId);
        foreach ($this->field->definitions() as $definition) {
            $this->fields->declareExtensionField(
                $context,
                $eventId,
                $definition['key'],
                $definition['type'],
                $definition['title'],
                $definition['options'],
                true,
                BillingField::DECLARER,
            );
        }

        return null;
    }
}
