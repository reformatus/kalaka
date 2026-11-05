<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/** A new draft event with a name only. Returns its id. */
final class CreateEvent implements AdminUseCase
{
    public function __construct(private readonly EventRepository $repository = new EventRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/create-event';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
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

    public function execute(array $input, Context $context): int
    {
        $name = trim((string) $input['name']);
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 1 || $length > 191) {
            throw new InvalidInput();
        }

        return $this->repository->upsert(new EventRecord($name), $context)->id;
    }
}
