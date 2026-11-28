<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The names of a registration's owed lines, for the admin's „Tételek”: a `charge` line's source is a fee
 * component, which names its lines when it implements `NamedFeeComponent`; every other line reads as the fixed
 * price, `fee`, „Díj”. A plain read.
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
        foreach ($this->components->list() as $component) {
            if ($component instanceof NamedFeeComponent && isset($asked[$component->id()])) {
                foreach ($component->lineNames($registration, $asked[$component->id()]) as $item => $name) {
                    $named[$component->id() . ($item === '' ? '' : ':' . $item)] = $name;
                }
            }
        }

        $names = [];
        foreach ($sources as $source) {
            $names[$source->key] = $named[$source->key] ?? new LineName(LineName::FEE, LineKind::Charge->label());
        }

        return $names;
    }
}
