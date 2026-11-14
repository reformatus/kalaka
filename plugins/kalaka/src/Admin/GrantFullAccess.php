<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Gives one user `kalaka_full_access`, as a capability of that user (never of a role), with an audit
 * entry. The capability is added after commit, with WordPress's own function, outside the transaction.
 */
final class GrantFullAccess implements AdminUseCase
{
    public static function name(): string
    {
        return 'kalaka/grant-full-access';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['user_id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['user_id'],
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
        $userId = is_int($input['user_id'] ?? null) ? $input['user_id'] : 0;
        $context->change(new Subject('user', null, (string) $userId), AdminAccess::FULL_ACCESS, false, true);
        $context->afterCommit(static function () use ($userId): void {
            $user = get_userdata($userId);
            if ($user instanceof \WP_User) {
                $user->add_cap(AdminAccess::FULL_ACCESS);
            }
        });

        return null;
    }
}
