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
     * @param bool $awaitsAnswer the registration has a request nobody has answered (Registrations'
     *     `Requests`); a row value only, computed per page: not a column, so not filterable, sortable or
     *     summable. (Shapes) grid rows carry awaits_answer: bool, PHP-computed per page; not filterable, sortable
     *     or summable (alt: a per-request-source SQL predicate seam, after opening). [UNAPPROVED N351]
     */
    public function __construct(public RegistrationKey $key, public array $values, public bool $awaitsAnswer = false)
    {
    }
}
