<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Decision\CurrentDecision;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The one read of whether a registration is confirmed (money.md, "Confirmation"): its current `confirmation`
 * state is `confirmed`. Only a `registered` registration has a state, so any other is not confirmed. Everyone
 * who asks, the portal and the admin included, asks here, never by the decision's kind or state names.
 * A plain read, without a lock; it never evaluates.
 */
final class Confirmations
{
    public function __construct(
        private readonly Decisions $decisions = new Decisions(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /** Whether the registration's current confirmation state is `confirmed`. */
    public function isConfirmed(RegistrationKey $registration): bool
    {
        return self::confirmedIn($this->decisions->currentOf($registration));
    }

    /**
     * Whether each listed registration of one event is confirmed, as `isConfirmed()` answers for each, in two
     * statements: no matter how many keys. A code that is not a registration of the event is not confirmed.
     *
     * @param list<RegistrationKey> $keys
     * @return array<string, bool> by code, one entry per key
     * @throws \InvalidArgumentException when a key belongs to another event
     */
    public function confirmedOf(int $eventId, array $keys): array
    {
        $codes = [];
        foreach ($keys as $key) {
            if ($key->eventId !== $eventId) {
                throw new \InvalidArgumentException('A registration of another event.');
            }
            $codes[$key->code] = true;
        }
        if ($codes === []) {
            return [];
        }
        $statuses = array_intersect_key($this->registrations->statusesOf($eventId), $codes);
        $states = $this->decisions->currentStates($eventId, $statuses, [ConfirmationRule::KIND]);

        $confirmed = [];
        foreach (array_keys($codes) as $code) {
            $state = $states[$code][ConfirmationRule::KIND] ?? null;
            $confirmed[(string) $code] = $state === ConfirmationRule::CONFIRMED;
        }

        return $confirmed;
    }

    /**
     * The same answer read from decisions the caller already holds (`Decisions::currentOf()`), for a read that
     * needs the decision's record as well and must not ask twice.
     *
     * @param array<string, CurrentDecision> $current
     */
    public static function confirmedIn(array $current): bool
    {
        return ($current[ConfirmationRule::KIND]->state ?? null) === ConfirmationRule::CONFIRMED;
    }
}
