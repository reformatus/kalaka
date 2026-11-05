<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** An admin use case with a name of its own, to tell it from AdminProbe; its work is a closure of the test. */
final class NamedProbe implements AdminUseCase
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
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/named-probe';
    }

    public function inputSchema(): array
    {
        return $this->schema;
    }

    public function action(): ?string
    {
        return null;
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
