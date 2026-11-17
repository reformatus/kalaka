<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Ties a set to its one request field (S44-1): the participant field a form's request field naming the set
 * declares. The first tie is stored; the same key again writes nothing; a different key, or a key that is
 * already another set's request field, is a `RuleViolation`. A tie is never released (a participant field's
 * key is never reused either). Run after a form's save by the participant-field sync, as the saving user.
 */
final class TieRequestField implements AdminUseCase
{
    public function __construct(private readonly SetRepository $sets = new SetRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/tie-request-field';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'set_id' => ['type' => 'integer', 'minimum' => 1],
                'field_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['set_id', 'field_key'],
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
        $key = (string) $input['field_key'];
        $set = $this->sets->findForUpdate((int) $input['set_id'], $context) ?? throw new NotFound();
        if ($set->requestFieldKey === $key) {
            return null;
        }
        if ($set->requestFieldKey !== null) {
            throw new RuleViolation();
        }
        foreach ($this->sets->ofEvent($set->eventId) as $other) {
            if ($other->id !== $set->id && $other->requestFieldKey === $key) {
                throw new RuleViolation();
            }
        }

        $set->requestFieldKey = $key;
        $this->sets->upsert($set, $context);

        return null;
    }
}
