<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Query\Query;

/** The reference codes in use, for the generator. */
final class ReferenceCodeQuery extends Query
{
    /**
     * The codes of the list that the event uses: primary-key lookups inside the event.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public function usedByEvent(int $eventId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->scope(fn (): array => self::codes($this->select(
            'registration',
            'SELECT code FROM %i WHERE event_id = %d AND code IN (' . self::placeholders($codes) . ')',
            [$this->table('kalaka_registrations'), $eventId, ...$codes]
        )));
    }

    /**
     * The codes of the list that any event uses. A site-wide statement outside the per-event rule: point
     * lookups on `KEY code`, so its cost is bounded by the rows that hold the codes asked for.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public function usedOnTheSite(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->scope(fn (): array => self::codes($this->select(
            'registration',
            'SELECT DISTINCT code FROM %i WHERE code IN (' . self::placeholders($codes) . ')',
            [$this->table('kalaka_registrations'), ...$codes]
        )));
    }

    /** @return list<string> the codes of one length that one event uses */
    public function ofEventWithLength(int $eventId, int $length): array
    {
        return $this->scope(fn (): array => self::codes($this->select(
            'registration',
            'SELECT code FROM %i WHERE event_id = %d AND CHAR_LENGTH(code) = %d',
            [$this->table('kalaka_registrations'), $eventId, $length]
        )));
    }

    /**
     * @param non-empty-list<string> $codes
     * @return literal-string
     */
    private static function placeholders(array $codes): string
    {
        return implode(', ', array_fill(0, count($codes), '%s'));
    }

    /**
     * @param list<array<string, ?string>> $rows
     * @return list<string>
     */
    private static function codes(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }
}
