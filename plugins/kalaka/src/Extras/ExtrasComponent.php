<?php

declare(strict_types=1);

namespace Kalaka\Extras;

use Kalaka\Extras\Api\Charge;
use Kalaka\Extras\Api\Extras;
use Kalaka\Extras\Charges\ChargeRecord;
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
use Kalaka\Registrations\Api\RegistrationFacts;

/**
 * The extra charges' fee component, at the extras stage. A charge is linked by key: the participant field whose
 * key equals the charge's key calls it, by the field's type: a consent counts the amount once (a `1`), a number
 * field whose stored decimals are 0 counts it a positive whole `n` times, and every other type charges
 * nobody. Each charge is its own ledger line, `extras:<charge key>` (one item per charge,
 * `FeeTargets::ofItems()`). A charge has no condition of its own; anything else (no field with
 * the key, empty, 0, negative, a field that allows decimals) charges nothing, with no warning. It reads only the
 * registration's facts and its own current settings, or the proposed ones in a preview. Support and the
 * congregation share compose earlier and never cover it.
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
        $items = [];
        $total = Money::zero($input->currency);
        foreach ($charges as $charge) {
            $quantity = self::quantity($input->facts, $charge->key);
            if ($quantity > 0) {
                // An amount times an answer, or a sum, that Money cannot hold charges nobody: the
                // evaluation never throws on a user's answer.
                try {
                    $line = $charge->amount->times($quantity);
                    $total = $total->plus($line);
                } catch (SystemFailure) {
                    continue;
                }
                $items[$charge->key] = [Payer::PARTICIPANT => $line];
            }
        }

        return $items === [] ? FeeTargets::none() : FeeTargets::ofItems($items);
    }

    public function settingsToArray(int $eventId): array
    {
        $charges = [];
        foreach ((new Extras())->charges($eventId) as $charge) {
            $charges[] = [
                'key' => $charge->key,
                'label' => $charge->label,
                'amount' => PlainAmount::of($charge->amount),
                'type' => $charge->type,
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
     * The answer's whole value, by the field's type: `1` for a ticked consent, a positive whole number for a
     * number field whose stored decimals are 0 (N13), and nothing for every other type, a number field that
     * allows decimals, or a key no field declares.
     */
    private static function quantity(RegistrationFacts $facts, string $key): int
    {
        $kind = $facts->fieldKind($key);
        if ($kind === null) {
            return 0;
        }
        try {
            $value = $facts->get($key);
        } catch (SystemFailure) {
            return 0;
        }
        if (!is_string($value->data)) {
            return 0;
        }
        if ($kind->isConsent()) {
            return $value->data === '1' ? 1 : 0;
        }
        if (!$kind->isNumber() || $kind->decimals !== 0 || preg_match('/\A[0-9]+\z/', $value->data) !== 1) {
            return 0;
        }

        return (int) $value->data;
    }
}
