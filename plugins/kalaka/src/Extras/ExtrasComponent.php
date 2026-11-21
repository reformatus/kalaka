<?php

declare(strict_types=1);

namespace Kalaka\Extras;

use Kalaka\Extras\Api\Charge;
use Kalaka\Extras\Charges\ChargeRecord;
use Kalaka\Extras\Api\Extras;
use Kalaka\Extras\Charges\ChargeRepository;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Forms\Api\Facts;

/**
 * The extra charges' fee component, at the extras stage: each charge is its amount times the linked
 * answer's whole value (a consent's `1` charges it once; a positive whole `n` charges it `n` times), and
 * every charge a registration owes is one `extras` line (the materializer keeps one line source per
 * component and payer). A charge has no condition of its own; anything else (empty, 0, negative,
 * fractional, an unknown field) charges nothing. It reads only the registration's facts and its own
 * current settings, or the proposed ones in a preview. Support and the congregation share compose earlier
 * and never cover it. [UNAPPROVED N4, N13]
 */
final class ExtrasComponent implements ConfigurableFeeComponent
{
    public const ID = 'extras';

    /** @param list<Charge>|null $proposed the charges to compose with instead of the stored ones (a preview) */
    public function __construct(
        private readonly ChargeRepository $charges = new ChargeRepository(),
        private readonly ?array $proposed = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $charges = $this->proposed ?? array_map(
            static fn (ChargeRecord $record): Charge => $record->charge(),
            $this->charges->forEvent($input->registration->eventId)
        );
        if ($charges === []) {
            return FeeTargets::none();
        }
        $total = Money::zero($input->currency);
        foreach ($charges as $charge) {
            $quantity = self::quantity($input->facts, $charge->field);
            if ($quantity > 0) {
                $total = $total->plus($charge->amount->times($quantity));
            }
        }

        return $total->isPositive() ? new FeeTargets([Payer::PARTICIPANT => $total]) : FeeTargets::none();
    }

    public function settingsToArray(int $eventId): array
    {
        $charges = [];
        foreach ((new Extras())->charges($eventId) as $charge) {
            $charges[] = [
                'key' => $charge->key,
                'label' => $charge->label,
                'amount' => PlainAmount::of($charge->amount),
                'field' => $charge->field,
            ];
        }

        return ['charges' => $charges];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        $charges = $settings['charges'] ?? null;
        if (array_keys($settings) !== ['charges'] || !is_array($charges) || !array_is_list($charges)) {
            throw new InvalidInput();
        }

        return (new Extras())->propose($eventId, $charges);
    }

    /**
     * The answer's whole value: `1` for a ticked consent, a positive whole number for a number field, and
     * nothing for anything else. A charge names a field the save accepted; an undeclared key still charges
     * nothing rather than failing the evaluation.
     */
    private static function quantity(Facts $facts, string $field): int
    {
        try {
            $value = $facts->get($field);
        } catch (SystemFailure) {
            return 0;
        }
        if (!is_string($value->data) || preg_match('/\A[0-9]+\z/', $value->data) !== 1) {
            return 0;
        }

        return (int) $value->data;
    }
}
