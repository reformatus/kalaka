<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/** Takes full access from a WordPress user, unless they are its last holder; a user without it is left as is. A site-level use case: only full access. */
final class RevokeFullAccess implements AdminUseCase
{
    public function __construct(private readonly FullAccessChange $change = new FullAccessChange())
    {
    }

    public static function name(): string
    {
        return 'kalaka/revoke-full-access';
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
        return $this->change->apply((int) $input['user_id'], false, $context);
    }
}
