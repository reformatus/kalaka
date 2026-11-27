<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * What a deposit amount must be before it is proposed or saved, for the preview and the save alike: decimal
 * text in the event's currency, greater than zero, with at most two decimals (N829). The text is not turned
 * into money here; the money side converts it with the event's currency.
 */
final class DepositInput
{
    /** The JSON schema pattern of an amount as sent: decimal text, at most two decimals. */
    public const SCHEMA_PATTERN = '^[0-9]+(\\.[0-9]{1,2})?$';

    /** The same shape as a PHP pattern; `0`, `0.00` and a negative are refused below. */
    private const SHAPE = '/\A[0-9]+(\.[0-9]{1,2})?\z/';

    /** @throws InvalidInput when the text is not a positive decimal with at most two decimals */
    public function amount(string $amount): string
    {
        if (preg_match(self::SHAPE, $amount) !== 1) {
            throw new InvalidInput();
        }
        if (preg_match('/\A0+(\.0+)?\z/', $amount) === 1) {
            throw new InvalidInput();
        }

        return $amount;
    }
}
