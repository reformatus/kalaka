<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\ChargeTotal;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\FeeComposer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The money phase of the evaluation (money.md, "Materializer"; foundation.md, "Evaluation"): composes the
 * fee from the current settings and the registration's facts, and writes, per component and payer, what it
 * owes minus what is recorded as one `charge` line (per item too, for a component that owes per item: each
 * manual charge or discount is its own line). It converges: a second run writes nothing. Lines of
 * components not registered now are left alone. The run holds the registration's row lock.
 */
final class Materializer
{
    public const SOURCE = 'fee_component';

    private readonly FeeComposition $composition;

    public function __construct(
        CoreFeeComponents $components = new CoreFeeComponents(),
        FeeComposer $composer = new FeeComposer(),
        private readonly Ledger $ledger = new Ledger(),
        Events $events = new Events(),
        Registrations $registrations = new Registrations(),
    ) {
        $this->composition = new FeeComposition($components, $composer, $events, $registrations);
    }

    /**
     * The composition this materializer records, for confirmation, which reads each payer's target: one source
     * of components.
     */
    public function composition(): FeeComposition
    {
        return $this->composition;
    }

    /**
     * @throws FeeComponentFailed when a component throws or returns something invalid
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure when a line cannot be recorded
     */
    public function materialize(RegistrationKey $key, Context $context): void
    {
        $composed = $this->composition->of($key, $context->now());
        if ($composed === null) {
            return;
        }
        $currency = $composed->currency;
        $owed = $composed->byComponent;

        /** @var array<string, array<string, ChargeTotal>> $recorded */
        $recorded = [];
        foreach ($this->ledger->chargeTotalsOf($key) as $total) {
            if ($total->source->type === self::SOURCE && $total->source->eventId === null) {
                $recorded[$total->source->key][$total->payer] = $total;
            }
        }

        // A line of a registered component that the composition no longer names (a withdrawal, a removed item)
        // owes zero now, so it is reversed. A source key is `<component>` or `<component>:<item>`; lines of a
        // component not registered now are left alone.
        $ids = $this->composition->componentIds();
        foreach (array_keys($recorded) as $sourceKey) {
            if (!isset($owed[$sourceKey]) && in_array(explode(':', (string) $sourceKey, 2)[0], $ids, true)) {
                $owed[$sourceKey] = [];
            }
        }

        foreach ($owed as $id => $targets) {
            $mine = $recorded[$id] ?? [];
            $payers = array_unique([...array_keys($targets), ...array_keys($mine)]);
            sort($payers);
            foreach ($payers as $payer) {
                $payer = (string) $payer;
                $difference = ($targets[$payer] ?? Money::zero($currency))
                    ->minus($mine[$payer]->total ?? Money::zero($currency));
                if (!$difference->isPositive() && !$difference->isNegative()) {
                    continue;
                }

                $this->ledger->record(
                    new NewLine(
                        $key,
                        LineKind::Charge,
                        $payer,
                        $difference,
                        new Subject(self::SOURCE, null, $id),
                        'charge:' . $id . ':' . $payer . ':' . (($mine[$payer]->lines ?? 0) + 1),
                    ),
                    $context
                );
            }
        }
    }
}
