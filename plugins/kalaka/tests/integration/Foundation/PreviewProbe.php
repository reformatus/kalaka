<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PreviewableUseCase;

/** An admin use case that is a preview when its input says `preview: true`; its work is a closure of the test. */
final class PreviewProbe implements AdminUseCase, PreviewableUseCase
{
    /** @param \Closure(array<string, mixed>, Context): mixed $body */
    public function __construct(private readonly \Closure $body)
    {
    }

    public static function name(): string
    {
        return 'kalaka/preview-probe';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['preview' => ['type' => 'boolean']]];
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

    public function isPreview(array $input): bool
    {
        return ($input['preview'] ?? false) === true;
    }

    public function execute(array $input, Context $context): mixed
    {
        return ($this->body)($input, $context);
    }
}
