<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A declaration that fixes a number field's decimals (a number charge: 0) found forms of the event that hold a
 * number field under the key with other decimals, so adopting the key would close them (`SharedKeyCheck`'s
 * `extension_decimals`). It carries the key and the forms; no text of its own, the one CLI prints is English.
 * Its own code; the text is the rule violation's until one is approved. [UNAPPROVED N808]
 */
final class FieldDecimalsHeld extends RuleViolation
{
    /** @param list<array{form_id: int, title: string}> $forms the forms holding the key with other decimals */
    public function __construct(public readonly string $key, public readonly array $forms)
    {
        parent::__construct();
    }

    public function errorCode(): string
    {
        return 'kalaka_field_decimals_held';
    }
}
