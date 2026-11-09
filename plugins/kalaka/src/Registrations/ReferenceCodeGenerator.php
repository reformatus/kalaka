<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Random\Randomizer;

/**
 * Issues the reference code of a new registration: registrations-and-identity.md, "Reference codes".
 * It stores nothing; the caller inserts the code inside the running use case, under the event lock,
 * so no other submit of the event can take the code meanwhile.
 */
final class ReferenceCodeGenerator
{
    /** The candidates drawn per length; every length has at least 5 000 valid codes. */
    public const BATCH = 64;

    /** @param Randomizer $random a CSPRNG (the default `Secure` engine); tests pass a seeded engine */
    public function __construct(
        private readonly ReferenceCodeQuery $query = new ReferenceCodeQuery(),
        private readonly Randomizer $random = new Randomizer(),
    ) {
    }

    /**
     * A valid code the event does not use, preferably one no other event uses; the shortest length
     * that has one left.
     */
    public function generate(int $eventId): string
    {
        for ($length = ReferenceCode::MIN_LENGTH; $length <= ReferenceCode::MAX_LENGTH; $length++) {
            $code = $this->sampled($eventId, $length) ?? $this->exact($eventId, $length);
            if ($code !== null) {
                return $code;
            }
        }

        $error = new SystemFailure(null, new \LogicException('Every reference code length is used up in the event.'));
        throw $error;
    }

    /**
     * Rejection sampling checked in SQL: a batch of distinct codes of the length, drawn uniformly. The
     * first in draw order that no event uses, else the first the event does not use; null when the event
     * uses every one of them.
     */
    private function sampled(int $eventId, int $length): ?string
    {
        $candidates = $this->candidates($length);
        $own = array_flip($this->query->usedByEvent($eventId, $candidates));
        $free = array_values(array_filter($candidates, static fn (string $code): bool => !isset($own[$code])));
        if ($free === []) {
            return null;
        }

        $site = array_flip($this->query->usedOnTheSite($free));
        foreach ($free as $code) {
            if (!isset($site[$code])) {
                return $code;
            }
        }

        return $free[0];
    }

    /** @return list<string> `BATCH` distinct valid codes of the length, in draw order */
    private function candidates(int $length): array
    {
        $count = self::count($length);
        $numbers = [];
        while (count($numbers) < self::BATCH) {
            $numbers[$this->random->getInt(0, $count - 1)] = true;
        }

        return array_map(static fn (int $number): string => self::codeAt($length, $number), array_keys($numbers));
    }

    /**
     * A code of the length chosen uniformly among the valid ones the event does not use, from the event's
     * codes of that length; null when it uses them all. The valid codes are numbered without listing them.
     */
    private function exact(int $eventId, int $length): ?string
    {
        $positions = array_flip(ReferenceCode::fourCharacterCodes());
        $perPrefix = 10 ** ($length - ReferenceCode::MIN_LENGTH);

        $taken = [];
        foreach ($this->query->ofEventWithLength($eventId, $length) as $code) {
            if (strlen($code) === $length && ReferenceCode::isValid($code)) {
                $prefix = $positions[substr($code, 0, ReferenceCode::MIN_LENGTH)];
                $taken[$prefix * $perPrefix + (int) substr($code, ReferenceCode::MIN_LENGTH)] = true;
            }
        }
        $free = self::count($length) - count($taken);
        if ($free < 1) {
            return null;
        }

        $number = $this->random->getInt(0, $free - 1);
        $ranks = array_keys($taken);
        sort($ranks);
        foreach ($ranks as $rank) {
            if ($rank > $number) {
                break;
            }
            $number++;
        }

        return self::codeAt($length, $number);
    }

    /** The number of valid codes of the length. */
    private static function count(int $length): int
    {
        return count(ReferenceCode::fourCharacterCodes()) * 10 ** ($length - ReferenceCode::MIN_LENGTH);
    }

    /** The valid code of the length at the number: the four-character prefix's position, then the digits. */
    private static function codeAt(int $length, int $number): string
    {
        $perPrefix = 10 ** ($length - ReferenceCode::MIN_LENGTH);
        $digits = $length - ReferenceCode::MIN_LENGTH;

        return ReferenceCode::fourCharacterCodes()[intdiv($number, $perPrefix)]
            . ($digits === 0 ? '' : str_pad((string) ($number % $perPrefix), $digits, '0', STR_PAD_LEFT));
    }
}
