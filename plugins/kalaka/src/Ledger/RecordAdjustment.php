<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * Records one `adjustment` line (money.md, "Ledger" and "Recording payments by hand"): what a payer owes
 * changes by the amount, with a required reason. A waiver of a short transfer's rest is negative; either
 * sign is allowed, never zero. The reason is the line's note, kept in the payment notes, never on the line. An
 * adjustment is not a payment fact and not a fee component: it moves the balance, not what was paid. Carried
 * by the per-event action `kalaka_record_payment` (the money-write action) inside its grant's view, or full
 * access. Written under the registration's lock; the caller's key is prefixed (`manual:`) like the manual
 * entries'. Returns the line's `id`.
 */
final class RecordAdjustment implements AdminUseCase
{
    /** The longest caller key: the stored key is `manual:` and the caller's. */
    private const KEY_PATTERN = '^[a-z0-9_:.-]{1,100}$';

    public function __construct(
        private readonly Ledger $ledger = new Ledger(),
        private readonly Events $events = new Events(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly PayerKinds $payerKinds = new PayerKinds(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/record-adjustment';
    }

    public function action(): string
    {
        return Ledger::RECORD_PAYMENT;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}$'],
                'payer' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,49}$', 'default' => Payer::PARTICIPANT],
                'amount' => ['type' => 'string', 'pattern' => '^-?[0-9]{1,18}(\\.[0-9]{1,8})?$'],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'idempotency_key' => ['type' => 'string', 'pattern' => self::KEY_PATTERN],
            ],
            'required' => ['event_id', 'code', 'amount', 'currency', 'reason', 'idempotency_key'],
            'additionalProperties' => false,
        ];
    }

    public function event(array $input): ?int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int}
     */
    public function execute(array $input, Context $context): array
    {
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            throw new NotFound();
        }
        $currency = Currency::tryFrom((string) $input['currency']);
        if ($currency !== $event->currency) {
            throw new InvalidInput();
        }
        $amount = Money::of((string) $input['amount'], $currency);
        if (
            !($amount->isPositive() || $amount->isNegative())
            || ManualLine::decimals(ltrim((string) $input['amount'], '-')) > $currency->minorUnitDecimals()
        ) {
            throw new InvalidInput();
        }
        $payer = isset($input['payer']) ? (string) $input['payer'] : Payer::PARTICIPANT;
        $declared = array_map(
            static fn (PayerKind $kind): string => $kind->id,
            $this->payerKinds->of($event->id)
        );
        if (!in_array($payer, $declared, true)) {
            throw new InvalidInput();
        }
        $reason = trim((string) $input['reason']);
        if ($reason === '') {
            throw new InvalidInput();
        }

        if (!$this->registrations->lock($key, $context)) {
            throw new NotFound();
        }
        $line = new NewLine(
            $key,
            LineKind::Adjustment,
            $payer,
            $amount,
            ManualLine::sourceOf($context->actor()),
            'manual:' . $input['idempotency_key'],
            note: $reason,
        );
        if ($this->ledger->keyHoldsDifferentLine($line)) {
            throw new IdempotencyConflict();
        }
        $recorded = $this->ledger->record($line, $context);

        // What was recorded, and never the reason: free text does not belong in an audit that outlives erasure.
        if ($recorded->inserted) {
            $context->change(
                Subject::registration($key),
                LineKind::Adjustment->value,
                null,
                [
                    'line' => $recorded->line->id,
                    'payer' => $payer,
                    'amount' => $amount->amount(),
                    'currency' => $currency->value,
                ]
            );
        }

        return ['id' => $recorded->line->id];
    }
}
