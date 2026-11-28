<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Naming;

/** Where one plugin's names are kept. Reading never throws. */
interface NameStore
{
    /** The stored value as it is, whatever its shape; null when nothing is stored. */
    public function read(): mixed;
}
