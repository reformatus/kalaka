<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Sets the hourly budget of the sender, a site setting (mail.md, "Sending"): an integer from 1 to 2000, anything
 * else refused (`MailBudgetInvalid`; the schema checks the type only, so the refusal is typed). It writes the
 * sender's state row, made when there is none yet, and nothing else; an unchanged value writes nothing. The
 * sender reads the row fresh before each run, so the next run uses the new value (`Throttle`). Site-level, so
 * only full access (and WP-CLI) passes.
 */
final class SetMailBudget implements AdminUseCase
{
    public const MIN = 1;
    public const MAX = 2000;

    public function __construct(private readonly MailStateRepository $states = new MailStateRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/set-mail-budget';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['hourly_budget' => ['type' => 'integer']],
            'required' => ['hourly_budget'],
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

    /**
     * @return bool whether this call changed the budget; false when it already was that value
     * @throws MailBudgetInvalid outside 1 to 2000
     */
    public function execute(array $input, Context $context): bool
    {
        $budget = $input['hourly_budget'] ?? null;
        if (!is_int($budget) || $budget < self::MIN || $budget > self::MAX) {
            throw new MailBudgetInvalid();
        }
        $state = $this->states->forUpdate($context);
        $context->touch(MailStateRepository::subjectOf());
        if ($state->hourlyBudget === $budget) {
            return false;
        }

        $state->hourlyBudget = $budget;
        $this->states->upsert($state, $context);

        return true;
    }
}
