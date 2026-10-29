<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

/** What extensions receive on the kalaka_register_modules action. */
interface Modules
{
    public function add(Module $module): void;
}
