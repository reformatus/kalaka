<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/** Draft or unpublished to published; needs a name, the four core points and a capacity. */
final class PublishEvent implements AdminUseCase
{
    public function __construct(private readonly EventRepository $repository = new EventRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/publish-event';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['id', 'version'],
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
        $id = (int) $input['id'];
        $record = $this->repository->findForUpdate($id, $context) ?? throw new NotFound();
        $context->touch(Subject::event($id));
        if (!in_array($record->state, [EventState::Draft, EventState::Unpublished], true)) {
            throw new RuleViolation();
        }
        if (
            $record->name === ''
            || $record->capacity === null
            || $record->registrationOpensOn === null
            || $record->registrationClosesOn === null
            || $record->startsOn === null
            || $record->endsOn === null
        ) {
            throw new RuleViolation();
        }
        $record->state = EventState::Published;

        $record->version = (int) $input['version'];
        $this->repository->upsert($record, $context);

        return null;
    }
}
