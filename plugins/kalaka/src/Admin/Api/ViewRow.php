<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Registrations\Api\RegistrationKey;

/** One registration in a view: its key and the value of each of the view's columns. */
final readonly class ViewRow
{
    /**
     * @param array<string, string|int|null> $values by column (`code`, `status`, `serial`, `awaits_answer` ('1',
     *     '0' or null: N/A), `field:<key>`, `decision:<kind>`), in the view's column order; null where there is
     *     no value
     * @param ?bool $awaitsAnswer true: the registration is `registered` and has a request nobody has answered; false:
     *     it is `registered` and has none; null: N/A, it is not `registered`. From the same SQL as the
     *     `awaits_answer` column's filter, sort and count (`Requests::awaitingCondition()`); carried on every row,
     *     whether or not the view lists the column. [UNAPPROVED N690]
     */
    public function __construct(public RegistrationKey $key, public array $values, public ?bool $awaitsAnswer = false)
    {
    }
}
