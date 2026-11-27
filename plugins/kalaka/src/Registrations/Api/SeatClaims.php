<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;

/**
 * The one read of seat claims: every `SeatClaimSource` asked for the claims of the answers, in contribution order.
 * A registration submit asks it of the answers it validated, an additional form's submit of the values the
 * registration would hold after it (D7), a staff correction and a status act of the stored values. One claim
 * per pool: when two sources claim the same pool, the first stays, so a submit takes one seat for it, not two.
 * Plain reads, outside any transaction.
 */
final class SeatClaims
{
    /**
     * @param list<SeatClaimSource> $sources
     * @return list<SeatClaim>
     */
    public static function of(array $sources, Event $event, ValidatedAnswers $answers): array
    {
        $byPool = [];
        foreach ($sources as $source) {
            foreach ($source->claims($event, $answers) as $claim) {
                $byPool[$claim->pool->key()] ??= $claim;
            }
        }

        return array_values($byPool);
    }
}
