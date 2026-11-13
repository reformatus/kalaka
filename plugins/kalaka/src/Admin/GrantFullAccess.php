<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\FullAccess\FullAccessChange;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Gives one user `kalaka_full_access`, as a capability of that user (never of a role), with an audit
 * entry of the real old value; a user who has it already is left as is, and an unknown user is not found.
 * The capability is added after commit, with WordPress's own function, outside the transaction
 * (`FullAccessChange`). A site-level use case: only full access.
 */
final class GrantFullAccess implements AdminUseCase
{
    public function __construct(private readonly FullAccessChange $change = new FullAccessChange())
    {
    }

    public static function name(): string
    {
        return 'kalaka/grant-full-access';
    }

    public function inputSchema(): array
    {
        return FullAccessChange::inputSchema();
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
        return $this->change->apply((int) $input['user_id'], true, $context);
    }
}
