<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Registrations\Api\RegistrationKey;

/** A portal use case naming the given registrations; its work is a closure of the test. */
final class PortalProbe implements PortalUseCase
{
    public int $calls = 0;

    /**
     * @param list<RegistrationKey> $registrations
     * @param \Closure(array<string, mixed>, Context, int): mixed $body input, context, call number
     */
    public function __construct(
        private readonly array $registrations,
        private readonly \Closure $body,
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/probe';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function registrations(array $input): array
    {
        return $this->registrations;
    }

    public function execute(array $input, Context $context): mixed
    {
        $this->calls++;

        return ($this->body)($input, $context, $this->calls);
    }
}
