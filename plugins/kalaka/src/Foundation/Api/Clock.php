<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
