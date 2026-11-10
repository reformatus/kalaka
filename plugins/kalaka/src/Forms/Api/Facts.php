<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * A fact source: the typed values a condition may read, by key. The one availability evaluator
 * (`Availability`) reads every condition from a fact source and nothing else: a form's draft
 * answers and the event's values while the form is filled in and at submit, a registration's facts
 * after submit. Whoever evaluates builds the source; the evaluator never runs the materializer or
 * reads the database to fill one.
 *
 * Every key is declared with its type, also when its value is `Value::none()`.
 *
 * [UNAPPROVED N1] the fact source interface and its one method.
 */
interface Facts
{
    /** @throws SystemFailure when the key is not declared (problem `unknown_value_key`) */
    public function get(string $key): Value;
}
