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

    public function __construct(
        private readonly CoreFeeComponents $components = new CoreFeeComponents(),
        private readonly FeeComposer $composer = new FeeComposer(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly Events $events = new Events(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * @throws FeeComponentFailed when a component throws or returns something invalid
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure when a line cannot be recorded
     */
    public function materialize(RegistrationKey $key, Context $context): void
    {
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            return;
        }
        $facts = $this->registrations->factsOf($key, $event);
        if ($facts === null) {
            return;
        }

        $components = $this->components->list();
        $owed = $this->composer->compose($facts, $event->currency, $context->now(), $components);

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
        $ids = array_map(static fn (FeeComponent $component): string => $component->id(), $components);
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
                $difference = ($targets[$payer] ?? Money::zero($event->currency))
                    ->minus($mine[$payer]->total ?? Money::zero($event->currency));
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
