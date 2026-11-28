<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\SeatPool;

/** A test claim source: the stored value of the `tier` field names the pool it takes a seat in. */
final class TierClaimSource implements SeatClaimSource
{
    public const FIELD = 'tier';

    /**
     * @param array<string, SeatPool> $pools stored value → its pool
     * @param (\Closure(): void)|null $onClaims called each time the source is asked: a write that slips in
     */
    public function __construct(private readonly array $pools, private readonly ?\Closure $onClaims = null)
    {
    }

    public function claims(Event $event, ValidatedAnswers $answers): array
    {
        if ($this->onClaims !== null) {
            ($this->onClaims)();
        }
        $pool = $this->pools[$answers->stored()[self::FIELD] ?? ''] ?? null;

        return $pool === null ? [] : [new SeatClaim(self::FIELD, $pool)];
    }

    public function pools(Event $event): array
    {
        return [];
    }
}
