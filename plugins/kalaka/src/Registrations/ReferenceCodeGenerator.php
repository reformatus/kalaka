<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Issues the reference code of a new registration: registrations-and-identity.md, "Reference codes".
 * It stores nothing; the caller inserts the code inside the running use case.
 */
final class ReferenceCodeGenerator
{
    public function __construct(private readonly ReferenceCodeQuery $query = new ReferenceCodeQuery())
    {
    }

    /**
     * A valid code the event does not use, preferably one no other event uses; the shortest length
     * that has one left.
     */
    public function generate(int $eventId): string
    {
        $own = $this->query->ofEvent($eventId);
        $site = $this->query->onTheSite();

        for ($length = ReferenceCode::MIN_LENGTH; $length <= ReferenceCode::MAX_LENGTH; $length++) {
            $code = self::pick($length, [...$own, ...$site]) ?? self::pick($length, $own);
            if ($code !== null) {
                return $code;
            }
        }

        $error = new SystemFailure(null, new \LogicException('Every reference code length is used up in the event.'));
        throw $error;
    }

    /**
     * A code of the length chosen uniformly among the valid ones that are not excluded, without listing
     * them: the valid codes are numbered (the four-character prefix's position, then the digits after it).
     *
     * @param list<string> $excluded
     */
    private static function pick(int $length, array $excluded): ?string
    {
        $prefixes = ReferenceCode::fourCharacterCodes();
        $positions = array_flip($prefixes);
        $perPrefix = 10 ** ($length - ReferenceCode::MIN_LENGTH);

        $taken = [];
        foreach ($excluded as $code) {
            if (strlen($code) === $length && ReferenceCode::isValid($code)) {
                $prefix = $positions[substr($code, 0, ReferenceCode::MIN_LENGTH)];
                $taken[$prefix * $perPrefix + (int) substr($code, ReferenceCode::MIN_LENGTH)] = true;
            }
        }
        $free = count($prefixes) * $perPrefix - count($taken);
        if ($free < 1) {
            return null;
        }

        $number = random_int(0, $free - 1);
        $ranks = array_keys($taken);
        sort($ranks);
        foreach ($ranks as $rank) {
            if ($rank > $number) {
                break;
            }
            $number++;
        }

        $digits = $length - ReferenceCode::MIN_LENGTH;

        return $prefixes[intdiv($number, $perPrefix)]
            . ($digits === 0 ? '' : str_pad((string) ($number % $perPrefix), $digits, '0', STR_PAD_LEFT));
    }
}
