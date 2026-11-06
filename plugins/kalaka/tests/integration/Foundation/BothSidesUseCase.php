<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;

/** Implements both side interfaces: a programming error the runner refuses. */
final class BothSidesUseCase implements AdminUseCase, PortalUseCase
{
    public int $calls = 0;

    /** @param \Closure(): void $body */
    public function __construct(private readonly \Closure $body)
    {
    }

    public static function name(): string
    {
        return 'kalaka/both-sides';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
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

    public function registrations(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): mixed
    {
        $this->calls++;
        ($this->body)();

        return null;
    }
}
