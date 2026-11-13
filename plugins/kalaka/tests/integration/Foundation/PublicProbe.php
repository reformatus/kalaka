<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;
use Kalaka\Registrations\Api\RegistrationKey;

/** A public use case; a test may make it name registrations, which the coarse check refuses. */
final class PublicProbe implements PublicUseCase
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
        return 'kalaka-portal/public-probe';
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
