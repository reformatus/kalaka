<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** An admin use case whose work is a closure of the test; counts its `execute` calls. */
final class AdminProbe implements AdminUseCase
{
    public int $calls = 0;

    /**
     * @param \Closure(array<string, mixed>, Context, int): mixed $body input, context, call number
     * @param array<string, mixed> $schema
     * @param list<RegistrationKey> $targets
     */
    public function __construct(
        private readonly \Closure $body,
        private readonly array $schema = ['type' => 'object'],
        private readonly ?int $event = null,
        private readonly array $targets = [],
        private readonly ?string $action = null,
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/probe';
    }

    public function inputSchema(): array
    {
        return $this->schema;
    }

    public function action(): ?string
    {
        return $this->action;
    }

    public function event(array $input): ?int
    {
        return $this->event;
    }

    public function targets(array $input): array
    {
        return $this->targets;
    }

    public function execute(array $input, Context $context): mixed
    {
        $this->calls++;

        return ($this->body)($input, $context, $this->calls);
    }
}
