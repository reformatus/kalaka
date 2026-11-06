<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** Inserts or replaces the "evaluation failed" flag of a registration. */
final class FlagEvaluationFailure implements AdminUseCase
{
    public function __construct(
        private readonly EvaluationFailureRepository $failures = new EvaluationFailureRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/flag-evaluation-failure';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
                'rule' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'error' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['event_id', 'code', 'rule', 'error'],
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
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        $flag = $this->failures->find($key) ?? new EvaluationFailure($key, '', '', $context->now());
        $flag->rule = (string) $input['rule'];
        $flag->error = (string) $input['error'];
        $flag->failedAt = $context->now();
        $this->failures->upsert($flag, $context);

        return null;
    }
}
