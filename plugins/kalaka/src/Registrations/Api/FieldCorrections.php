<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\RegistrationRepository;

/**
 * What a staff correction of one field value of a registration needs from Registrations
 * (events-and-forms.md, "Changing participant fields after submit"): the value's one writer, and the seats that
 * follow it. A limited choice (a price tier, a support offer) holds a seat through the contributed
 * `SeatClaimSource`s, which give a registration's claims from its answers; a correction asks them for the values
 * before and after, frees the seats the old value held and takes those the new one claims, never through
 * per-field code. Set options have no claim source: their seat follows the confirmed assignment.
 *
 * The caller (a use case) runs the steps in the one order the lock order allows: `plan()` (a plain read),
 * `lockPools()` (before the registration), `lock()` (the registration), `check()` under the lock (it refuses a
 * run that does not hold the registration), `write()`, then `move()` (or `wouldOverfill()` for a preview). Seats
 * move only for a `registered` registration, and a staff placing may go over a pool's limit (`Seats::retake()`:
 * over the limit when the pool is full, reported as `over`).
 *
 * The class returns no registration's stored values: it answers booleans, option keys and field keys, and
 * compares state internally. Admin reads registration data only through the view query (engineering.md,
 * "Static checks"), and this is the narrow door the staff edit needs.
 */
final class FieldCorrections
{
    /**
     * @param list<SeatClaimSource>|null $seatClaimSources null: the contributed ones; a test passes its own
     */
    public function __construct(
        private readonly ?array $seatClaimSources = null,
        private readonly Seats $seats = new Seats(),
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * The seats the correction moves, from a plain read, without a lock: the registration's stored values and
     * the same values with the field changed (`null` clears it) are each put to every claim source. The plan holds
     * only a fingerprint of the values (`check()`).
     *
     * @throws NotFound when the event is gone
     */
    public function plan(RegistrationKey $key, string $field, ?string $new): FieldCorrectionPlan
    {
        $event = $this->events->find($key->eventId) ?? throw new NotFound();
        $before = $this->values($key);
        $after = $before;
        if ($new === null) {
            unset($after[$field]);
        } else {
            $after[$field] = $new;
        }
        $claimedBefore = $this->claimsOf($event, $before);
        $claimedAfter = $this->claimsOf($event, $after);

        return new FieldCorrectionPlan(
            self::fingerprint($before),
            array_values(array_diff_key($claimedAfter, $claimedBefore)),
            array_values(array_diff_key($claimedBefore, $claimedAfter)),
        );
    }

    /**
     * The check under the registration's lock, before anything is written: the values must still be the ones the
     * plan saw (else `VersionConflict`: the claims must be planned again); the field's value being the new one
     * already means there is nothing to do (the result is true, checked before `$expected`); otherwise, when
     * `$checkExpected`, the stored value must be `$expected` (`null`: none stored), else `VersionConflict`. No stored
     * value leaves this class.
     *
     * @return bool whether the field already holds the new value: nothing to write
     * @throws VersionConflict
     */
    public function check(
        Context $context,
        RegistrationKey $key,
        FieldCorrectionPlan $plan,
        string $field,
        ?string $new,
        bool $checkExpected,
        ?string $expected,
    ): bool {
        if (!$context->holdsRegistration($key)) {
            $error = new SystemFailure(null, new \LogicException('The check needs the registration locked.'));
            throw $error;
        }
        $values = $this->values($key);
        if (self::fingerprint($values) !== $plan->fingerprint) {
            $error = new VersionConflict();
            throw $error;
        }
        $current = $values[$field] ?? null;
        if ($current === $new) {
            return true;
        }
        if ($checkExpected && $current !== $expected) {
            $error = new VersionConflict();
            throw $error;
        }

        return false;
    }

    /**
     * Locks the pool rows the plan takes seats in, those that sit before the registration row in the lock order,
     * in the order the guard accepts. Before `lock()`.
     */
    public function lockPools(FieldCorrectionPlan $plan, Context $context): void
    {
        foreach ($this->seats->inLockOrder($plan->take) as $claim) {
            if ($claim->pool->lockLevel()->value < LockLevel::Registration->value) {
                $this->seats->lock($claim->pool, $context);
            }
        }
    }

    /**
     * Locks the registration's row and returns its status; null when there is no such registration.
     */
    public function lock(RegistrationKey $key, Context $context): ?RegistrationStatus
    {
        return $this->registrations->findForUpdate($key, $context)?->status;
    }

    /**
     * Writes the field's value, or removes it when `$new` is null: the one writer of a corrected value, so the
     * audit item is the same `field:<key>` as for a form's write. The registration's row must be held.
     */
    public function write(RegistrationKey $key, string $field, ?string $new, Context $context): void
    {
        $stored = $this->values->findForUpdate(
            ['eventId' => $key->eventId, 'code' => $key->code, 'fieldKey' => $field],
            $context
        );
        if ($new === null) {
            if ($stored !== null) {
                $this->values->delete($stored, $context);
            }

            return;
        }
        if ($stored === null) {
            $this->values->upsert(new FieldValue($key, $field, $new), $context);

            return;
        }
        $stored->value = $new;
        $this->values->upsert($stored, $context);
    }

    /**
     * The fields whose pool would be full when the correction takes its seat: what `move()` would report, with
     * nothing moved. Only a `registered` registration takes a seat, so none for any other status. Pools after
     * the registration in the lock order are locked here, as `move()` does.
     *
     * @return list<string> field keys
     */
    public function wouldOverfill(
        FieldCorrectionPlan $plan,
        RegistrationKey $key,
        RegistrationStatus $status,
        Context $context,
    ): array {
        return $this->take($plan, $key, $status, $context, false);
    }

    /**
     * Moves the seats of the plan: frees those of the old value and takes those of the new one, through
     * `Seats::retake()` (the pool may go over its limit). A registration that is not `registered`
     * holds no seat, so nothing moves (admission claims later). The registration's row must be held and the
     * pools of the seats to take locked (`lockPools()`).
     *
     * @return list<string> the fields whose pool stood full before the seat was taken
     */
    public function move(
        FieldCorrectionPlan $plan,
        RegistrationKey $key,
        RegistrationStatus $status,
        Context $context,
    ): array {
        if (!$status->isActive()) {
            return [];
        }
        foreach ($plan->free as $claim) {
            $this->seats->release($claim->pool, $key, $context);
        }

        return $this->take($plan, $key, $status, $context, true);
    }

    /**
     * @return list<string> field keys
     */
    private function take(
        FieldCorrectionPlan $plan,
        RegistrationKey $key,
        RegistrationStatus $status,
        Context $context,
        bool $apply,
    ): array {
        if (!$status->isActive()) {
            return [];
        }
        $over = [];
        foreach ($this->seats->inLockOrder($plan->take) as $claim) {
            // The pool's row first (a pool after the registration in the lock order is locked here), so the count
            // below is the one the take adds to.
            if (!$apply) {
                $this->seats->lock($claim->pool, $context);
                if ($this->seats->wouldOverfill($claim->pool, $key)) {
                    $over[$claim->fieldKey] = true;
                }
                continue;
            }
            if ($this->seats->retake($claim->pool, $key, SeatAct::Placement, $context) === SeatOutcome::Over) {
                $over[$claim->fieldKey] = true;
            }
        }

        return array_map(strval(...), array_keys($over));
    }

    /**
     * The registration's stored values, for the plan and the comparisons inside this class only.
     *
     * @return array<string, string> field key → the stored value
     */
    private function values(RegistrationKey $key): array
    {
        $values = [];
        foreach ($this->values->forRegistration($key) as $value) {
            /** @var FieldValue $value */
            $values[$value->fieldKey] = $value->value;
        }

        return $values;
    }

    /** @param array<string, string> $values */
    private static function fingerprint(array $values): string
    {
        ksort($values, SORT_STRING);

        return hash('sha256', (string) wp_json_encode($values));
    }

    /**
     * Every claim of the values, by pool key.
     *
     * @param array<string, string> $values
     * @return array<string, SeatClaim>
     */
    private function claimsOf(Event $event, array $values): array
    {
        $answers = new ValidatedAnswers($values, $values);
        $claims = [];
        foreach ($this->seatClaimSources ?? Contributions::current()->seatClaimSources() as $source) {
            foreach ($source->claims($event, $answers) as $claim) {
                $claims[$claim->pool->key()] ??= $claim;
            }
        }

        return $claims;
    }
}
