<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Query\Query;

/** The reference codes in use, for the generator. */
final class ReferenceCodeQuery extends Query
{
    /** @return list<string> the codes of one event's registrations */
    public function ofEvent(int $eventId): array
    {
        return $this->scope(fn (): array => self::codes($this->select(
            'registration',
            'SELECT code FROM %i WHERE event_id = %d',
            [$this->table('kalaka_registrations'), $eventId]
        )));
    }

    /**
     * The distinct codes of the whole site. A site-wide statement outside the per-event rule, served
     * by `KEY code` as a loose index scan; its cost is bounded by the number of distinct codes.
     *
     * @return list<string>
     */
    public function onTheSite(): array
    {
        return $this->scope(fn (): array => self::codes($this->select(
            'registration',
            'SELECT DISTINCT code FROM %i',
            [$this->table('kalaka_registrations')]
        )));
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
