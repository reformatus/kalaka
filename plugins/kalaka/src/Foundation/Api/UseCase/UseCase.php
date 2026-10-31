<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

interface UseCase
{
    /** `kalaka/<verb-noun>` for admin, `kalaka-portal/<verb-noun>` for portal. */
    public static function name(): string;

    /**
     * JSON Schema, WordPress REST/abilities dialect.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** @param array<string, mixed> $input */
    public function execute(array $input, Context $context): mixed;
}
