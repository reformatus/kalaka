<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

interface AdminUseCase extends UseCase
{
    public function capability(): string;
}
