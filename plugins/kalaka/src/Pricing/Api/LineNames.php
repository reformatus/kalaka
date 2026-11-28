<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The names of a registration's owed lines, for the admin's „Tételek” and the participant's money table: a
 * `charge` line's source is a fee component, which names its lines when it implements `NamedFeeComponent`; every
 * other line reads as the fixed price, `fee`, „Díj”. Each name also carries its component's stage. A plain read,
 * so there is one rule and one entry point: a component whose `lineNames()` throws is contained like any
 * contribution outside a transaction (modules-and-extensions.md, "Failure isolation"): logged, and its lines read
 * as `fee`. Both callers (the admin's detail read and the participant's money table) are reads; a use case that
 * ever asks for names would be inside a transaction and would need its own entry point that lets the throw
 * roll the run back.
 */
final class LineNames
{
    public function __construct(private readonly CoreFeeComponents $components = new CoreFeeComponents())
    {
    }

    /**
     * @param list<Subject> $sources the sources of the lines asked about
     * @return array<string, LineName> by source key; a source of a component that names nothing reads as `fee`
     */
    public function of(RegistrationKey $registration, array $sources): array
    {
        /** @var array<string, list<?string>> $asked */
        $asked = [];
        foreach ($sources as $source) {
            if ($source->type === Materializer::SOURCE && $source->eventId === null) {
                $parts = explode(':', $source->key, 2);
                $asked[$parts[0]][] = $parts[1] ?? null;
            }
        }

        $named = [];
        $stages = [];
        foreach ($this->components->list() as $component) {
            if (!isset($asked[$component->id()])) {
                continue;
            }
            $stages[$component->id()] = $component->stage();
            if ($component instanceof NamedFeeComponent) {
                $ask = static function () use ($component, $registration, $asked, &$named): void {
                    $found = [];
                    foreach ($component->lineNames($registration, $asked[$component->id()]) as $item => $name) {
                        $found[$component->id() . ($item === '' ? '' : ':' . $item)] = $name;
                    }
                    $named = $found + $named;
                };
                Boundary::background('line names of fee component ' . $component->id(), $ask);
            }
        }

        $names = [];
        foreach ($sources as $source) {
            $stage = $source->type === Materializer::SOURCE && $source->eventId === null
                ? ($stages[explode(':', $source->key, 2)[0]] ?? null)
                : null;
            $name = $named[$source->key] ?? new LineName(LineName::FEE, LineKind::Charge->label());
            $names[$source->key] = new LineName($name->type, $name->label, $name->title, $name->recordedBy, $stage);
        }

        return $names;
    }
}
