<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\NamedFeeComponent;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Support\Api\Offer;
use Kalaka\Support\Api\Offers;

/**
 * The support offers' fee component, at the support stage (money.md, "Sponsor and church support"). An offer is
 * selected by the participant field whose key is the offer's key, a consent holding `1`. In the offers' order,
 * an offer applies when it is selected, it needs no request or its answer `<key>_answer` is `approved`, and it
 * has no limit or the registration holds its seat; an offer awaiting its answer, or refused, or whose seat is
 * not held gives no line. Each applying offer is its own participant-side line, `support:<key>`
 * (`FeeTargets::ofItems()`), a negative amount, and all of them together are capped at the base price (the tier
 * stage), the earlier offers first, so the fee never goes negative and an offer that finds nothing left writes no
 * line. It reads only the registration's facts, its own current settings (or the proposed ones in a preview)
 * and the seats the registration holds, never a charge line. [UNAPPROVED N96, N97]
 */
final class SupportComponent implements ConfigurableFeeComponent, NamedFeeComponent
{
    public const ID = 'support';

    /** @param list<Offer>|null $proposed the offers to compose with instead of the stored ones (a preview) */
    public function __construct(
        private readonly OfferRepository $offers = new OfferRepository(),
        private readonly ?array $proposed = null,
        private readonly Seats $seats = new Seats(),
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Support;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $offers = $this->proposed ?? array_map(
            static fn (OfferRecord $record): Offer => $record->offer(''),
            $this->offers->forEvent($input->registration->eventId)
        );
        $left = $input->before->of(FeeStage::Tier, Payer::PARTICIPANT);
        $items = [];
        $held = null;
        foreach ($offers as $offer) {
            if (!$left->isPositive()) {
                break;
            }
            if (!self::selected($input->facts, $offer)) {
                continue;
            }
            if ($offer->limit !== null) {
                $held ??= $this->seats->poolsOf($input->registration);
                if (!in_array('offer.' . $offer->key, $held, true)) {
                    continue;
                }
            }
            $amount = $offer->amount->isGreaterThan($left) ? $left : $offer->amount;
            $items[$offer->key] = [Payer::PARTICIPANT => $amount->negated()];
            $left = $left->minus($amount);
        }

        return $items === [] ? FeeTargets::none() : FeeTargets::ofItems($items);
    }

    /**
     * A line is named by its offer's own label, the organizers' text (`LineName::COMPONENT`); a line of an offer
     * that was removed is left out and reads as „Díj”. [UNAPPROVED N98]
     */
    public function lineNames(RegistrationKey $registration, array $items): array
    {
        $names = [];
        foreach ($this->offers->forEvent($registration->eventId) as $record) {
            if (in_array($record->key, $items, true)) {
                $names[$record->key] = new LineName(LineName::COMPONENT, $record->label);
            }
        }

        return $names;
    }

    public function settingsToArray(int $eventId): array
    {
        $offers = [];
        foreach ((new Offers($this->offers))->offers($eventId) as $offer) {
            $offers[] = [
                'key' => $offer->key,
                'label' => $offer->label,
                'node' => $offer->node,
                'amount' => PlainAmount::of($offer->amount),
                'request' => $offer->needsRequest,
                'limit' => $offer->limit,
            ];
        }

        return ['offers' => $offers];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        $offers = $settings['offers'] ?? null;
        if (array_keys($settings) !== ['offers'] || !is_array($offers) || !array_is_list($offers)) {
            throw new InvalidInput();
        }

        return (new Offers($this->offers))->propose($eventId, $offers);
    }

    /** Whether the offer counts for the registration, apart from its seat: selected, and approved if it needs it. */
    private static function selected(RegistrationFacts $facts, Offer $offer): bool
    {
        $kind = $facts->fieldKind($offer->key);
        if ($kind === null || !$kind->isConsent() || self::textOf($facts, $offer->key) !== '1') {
            return false;
        }

        return !$offer->needsRequest
            || self::choiceOf($facts, $offer->key . SetSupportOffers::ANSWER_SUFFIX) === 'approved';
    }

    private static function textOf(RegistrationFacts $facts, string $key): ?string
    {
        if (!$facts->has($key)) {
            return null;
        }
        $data = $facts->get($key)->data;

        return is_string($data) ? $data : null;
    }

    private static function choiceOf(RegistrationFacts $facts, string $key): ?string
    {
        if (!$facts->has($key)) {
            return null;
        }
        $data = $facts->get($key)->data;

        return is_array($data) && count($data) === 1 ? (string) $data[0] : null;
    }
}
