<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Actor;

interface Actor
{
    public function side(): Side;

    /** One of: user, cli, system, secret_link, account. */
    public function type(): string;
}
