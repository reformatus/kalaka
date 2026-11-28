<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\DraftClaimChoice;

/**
 * What a person's choice does to the draft that stays (S74): shared by the logged-in person's choice
 * (`ResolveDraftClaim`) and a guest's, on their own guest draft (`ResolveGuestHandoff`, N1106). The incoming
 * answers are the link's or the quiz's.
 */
final class DraftChoice
{
    /**
     * merge writes the incoming answers over the kept draft's and keeps the rest; keep_link replaces the
     * answers with the incoming ones; keep_draft changes nothing. A choice that changes the answers raises the
     * draft's `claims` count (a save from before it is refused, `SaveDraft`). Every choice records the claim and
     * the choice on the kept draft, for a repeat.
     */
    public static function apply(
        Draft $kept,
        Draft $incoming,
        DraftClaimChoice $choice,
        string $claimHash,
        \DateTimeImmutable $now
    ): void {
        if ($choice !== DraftClaimChoice::KeepDraft) {
            $kept->answers = $choice === DraftClaimChoice::Merge
                ? $kept->answers->overwrittenBy($incoming->answers)
                : $incoming->answers;
            $kept->formVersion = max($kept->formVersion, $incoming->formVersion);
            $kept->updatedAt = $now;
            ++$kept->claims;
        }
        $kept->resolvedClaim = $claimHash;
        $kept->resolvedChoice = $choice->value;
    }
}
