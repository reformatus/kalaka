<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * One item of a money settings apply (foundation.md, "Bulk changes": one transaction per item): it marks
 * the registration touched, so the runner's evaluation brings its money up to the saved settings. Running
 * it again writes nothing, since the materializer converges.
 */
final class ApplyMoneySettings implements AdminUseCase
{
    public static function name(): string
    {
        return 'kalaka/apply-money-settings';
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
