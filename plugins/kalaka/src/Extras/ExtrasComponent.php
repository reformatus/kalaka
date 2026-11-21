<?php

declare(strict_types=1);

namespace Kalaka\Extras;

use Kalaka\Extras\Api\Charge;
use Kalaka\Extras\Charges\ChargeRecord;
use Kalaka\Extras\Charges\ChargeRepository;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Forms\Api\Facts;

/**
 * The extra charges' fee component, at the extras stage. A charge is linked by key: the participant field whose
 * key equals the charge's key calls it, and the charge is its amount times that answer's whole value (a
 * consent's `1` charges it once; a positive whole `n` charges it `n` times). Each charge is its own ledger
 * line, `extras.<charge key>`. A charge has no condition of its own; anything else (no field with the key,
 * empty, 0, negative, fractional) charges nothing, with no warning. It reads only the registration's facts and
 * its own current settings, or the proposed ones in a preview. Support and the congregation share compose
 * earlier and never cover it. [UNAPPROVED N4, N13, N15]
 */
final class ExtrasComponent implements FeeComponent
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
        $lines = [];
        foreach ($charges as $charge) {
            $quantity = self::quantity($input->facts, $charge->key);
            if ($quantity > 0) {
                $lines[$charge->key] = [Payer::PARTICIPANT => $charge->amount->times($quantity)];
            }
        }

        return $lines === [] ? FeeTargets::none() : FeeTargets::ofLines($lines);
    }

    /**
     * The answer's whole value: `1` for a ticked consent, a positive whole number for a number field, and
     * nothing for anything else. A key no field declares charges nothing rather than failing the evaluation.
     */
    private static function quantity(Facts $facts, string $key): int
    {
        try {
            $value = $facts->get($key);
        } catch (SystemFailure) {
            return 0;
        }
        if (!is_string($value->data) || preg_match('/\A[0-9]+\z/', $value->data) !== 1) {
            return 0;
        }

        return (int) $value->data;
    }
}
