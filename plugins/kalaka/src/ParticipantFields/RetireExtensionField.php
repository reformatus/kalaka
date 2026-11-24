<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * An extension takes the participant field it declared out of use: the field is retired as a form field is
 * (`retiredAt`), its values and texts kept, and a declaration of the key (`DeclareExtensionField`) makes it active
 * again. Only an `extension` field the declarer declared, or one no declarer is recorded for, is retired; any
 * other field, and a key no field holds, is left alone. Run nested in the declarer's own use case
 * (`ParticipantFields::retireExtensionField()`), e.g. the extra charges' save.
 */
final class RetireExtensionField implements AdminUseCase
{
    public function __construct(
        private readonly ParticipantFieldRepository $repository = new ParticipantFieldRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/retire-extension-field';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                'declarer' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,63}$'],
            ],
            'required' => ['event_id', 'key', 'declarer'],
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
        foreach ($this->repository->forEventForUpdate((int) $input['event_id'], $context) as $record) {
            $ours = $record->source === ParticipantFieldRecord::SOURCE_EXTENSION
                && ($record->declaredBy === null || $record->declaredBy === $input['declarer']);
            if ($record->key !== $input['key'] || !$ours || $record->retiredAt !== null) {
                continue;
            }
            $record->retiredAt = $context->now();
            $record->updatedAt = $context->now();
            $this->repository->upsert($record, $context);
        }

        return null;
    }
}
