<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/** A chosen item (a tier, an option, an offer) cannot be removed: an open registration holds it. */
final class ItemChosen extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_item_chosen';
    }
}
