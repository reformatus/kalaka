<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\DraftClaimChoice;

/**
 * The texts of the claim popup (S74), approved by the owner on 2026-11-17 word for word, and its title and
 * intro also on 2026-11-27 (T1100-T1103, since the guest draft may hold the guest's own typing, N965): the
 * popup that asks the person's choice when a guest draft's answers meet their draft, its second confirmation
 * before keep_link, the button that reloads the page after a refused claim, and the one-line notice on the
 * load after the choice. The refused claim's own text is `Registrations\Api\DraftClaimRefused`'s
 * message.
 */
final class DraftClaimTexts
{
    /**
     * The popup's texts for the page's context, merge's line with the number of fields the link would
     * change. A guest's choice (N1106: a handoff meets the guest's own draft, no login involved) has its own
     * title and intro; every other text is the same.
     *
     * @return array<string, string>
     */
    public static function popup(int $changes, bool $guest = false): array
    {
        return [
            'title' => $guest
                ? 'Mentett és előre kitöltött válaszok'
                : 'Bejelentkezés előtti válaszok',
            'intro' => $guest
                ? 'Ehhez az űrlaphoz már vannak mentett, még be nem küldött válaszaid. '
                    . 'Most előre kitöltött válaszok is érkeztek: egy linkből vagy a kvízből. Hogyan folytassuk?'
                : 'Ehhez az űrlaphoz már vannak mentett, még be nem küldött válaszaid. '
                    . 'Ezen az eszközön bejelentkezés előtt is érkeztek válaszok: amelyeket beírtál, '
                    . 'vagy amelyek egy előre kitöltött linkből vagy a kvízből jöttek. Hogyan folytassuk?',
            'warning' => 'Egyesítéskor a bejövő válaszok miatt az űrlap későbbi részén kérdések tűnhetnek el '
                . 'vagy jelenhetnek meg. Egy eltűnt kérdésre adott válaszod nem kerül be a jelentkezésedbe, '
                . 'így az egyesítés a későbbi válaszaidat is megváltoztathatja. '
                . 'Beküldés előtt nézd át az egész űrlapot.',
            'merge' => 'Egyesítés',
            'mergeLine' => 'A bejövő válaszok felülírják a mentetteket: ' . $changes
                . ' kérdésnél változik a válaszod. A többi mentett válaszod megmarad.',
            'keepDraft' => 'Csak a mentett válaszok',
            'keepDraftLine' => 'A mentett válaszaid megmaradnak, a bejövő válaszokat elvetjük.',
            'keepLink' => 'Csak a bejövő válaszok',
            'keepLinkLine' => 'Csak a bejövő válaszok maradnak, az összes mentett válaszodat töröljük.',
            'confirmTitle' => 'Törlöd a mentett válaszaidat?',
            'confirmText' => 'Ha csak a bejövő válaszokat tartod meg, az összes mentett válaszodat töröljük '
                . 'az űrlap minden oldaláról. Ezt nem lehet visszavonni.',
            'confirmCancel' => 'Mégsem',
            'confirm' => 'Igen, törlöm a mentett válaszaimat',
            'reload' => 'Az oldal újratöltése',
        ];
    }

    /** The one-line notice on the load after the choice. */
    public static function notice(DraftClaimChoice $choice): string
    {
        return match ($choice) {
            DraftClaimChoice::Merge => 'Egyesítettük a mentett és a bejövő válaszokat. '
                . 'Beküldés előtt nézd át az egész űrlapot.',
            DraftClaimChoice::KeepDraft => 'Megtartottuk a mentett válaszaidat, a bejövő válaszokat elvetettük.',
            DraftClaimChoice::KeepLink => 'Megtartottuk a bejövő válaszokat, a mentett válaszaidat töröltük.',
        };
    }
}
