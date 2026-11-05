<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** Evaluates one registration: it only marks it touched, so the runner's evaluation does the work. */
final class EvaluateRegistration implements AdminUseCase
{
    public static function name(): string
    {
        return 'kalaka/evaluate-registration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
            ],
            'required' => ['event_id', 'code'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    public function execute(array $input, Context $context): null
    {
        $context->markTouched(new RegistrationKey((int) $input['event_id'], (string) $input['code']));

        return null;
    }
}
