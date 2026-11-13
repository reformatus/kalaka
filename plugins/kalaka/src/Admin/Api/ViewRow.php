<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Registrations\Api\RegistrationKey;

/** One registration in a view: its key and the value of each of the view's columns. */
final readonly class ViewRow
{
    /**
     * @param array<string, string|int|null> $values by column (`code`, `status`, `serial`, `field:<key>`,
     *     `decision:<kind>`), in the view's column order; null where there is no value
     */
    public function __construct(public RegistrationKey $key, public array $values)
    {
    }
}
