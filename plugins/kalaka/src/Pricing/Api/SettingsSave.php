<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;

/** The use case that saves a component's proposed settings, and its input: what a money settings apply runs. */
final readonly class SettingsSave
{
    /** @param array<string, mixed> $input */
    public function __construct(public AdminUseCase $useCase, public array $input)
    {
    }
}
