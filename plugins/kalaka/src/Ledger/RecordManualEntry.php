<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The shared body of "record payment" and "record refund" (money.md, "Recording payments by hand"): one
 * registration, one payer, one amount, with the method, the value date and an optional free-text note. It
 * writes one manual line and its note, under the registration's lock. The registration's status does not
 * matter, and neither does the amount against the balance (owner ruling 2026-11-18). The caller's key is
 * prefixed (`manual:`) so it cannot meet a key of the materializer in the registration's one key space.
 */
abstract class RecordManualEntry implements AdminUseCase
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

    /** The kind of line this use case writes. */
    abstract protected function kind(): LineKind;

    /**
     * Whether the input marks the line a correction.
     *
     * @param array<string, mixed> $input
     */
    protected function isCorrection(array $input): bool
    {
        return false;
    }

    /**
     * What the schema takes besides the shared input.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function extraProperties(): array
    {
        return [];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}$'],
                'payer' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,49}$', 'default' => Payer::PARTICIPANT],
                'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'method' => ['type' => 'string', 'enum' => array_map(
                    static fn (PaymentMethod $method): string => $method->value,
                    PaymentMethod::cases()
                )],
                'value_date' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                'note' => ['type' => 'string', 'maxLength' => 500],
                'idempotency_key' => ['type' => 'string', 'pattern' => self::KEY_PATTERN],
                ...$this->extraProperties(),
            ],
            'required' => ['event_id', 'code', 'amount', 'currency', 'method', 'value_date', 'idempotency_key'],
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
            !$amount->isPositive()
            || ManualLine::decimals((string) $input['amount']) > $currency->minorUnitDecimals()
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
        $method = PaymentMethod::tryFrom((string) $input['method']) ?? throw new InvalidInput();
        $valueDate = (string) $input['value_date'];
        $today = $context->now()->setTimezone(wp_timezone())->format('Y-m-d');
        if (Instant::startOfDay($valueDate) === null || $valueDate > $today) {
            throw new InvalidInput();
        }
        $note = isset($input['note']) ? trim((string) $input['note']) : '';
        $correction = $this->isCorrection($input);
        if ($correction && $note === '') {
            throw new InvalidInput();
        }

        if (!$this->registrations->lock($key, $context)) {
            throw new NotFound();
        }
        $kind = $this->kind();
        $line = new NewLine(
            $key,
            $kind,
            $payer,
            $kind === LineKind::ManualPayment ? $amount->negated() : $amount,
            ManualLine::sourceOf($context->actor()),
            'manual:' . $input['idempotency_key'],
            $valueDate,
            null,
            $method,
            $correction,
            $note === '' ? null : $note,
        );
        if ($this->ledger->keyHoldsDifferentLine($line)) {
            throw new IdempotencyConflict();
        }
        $recorded = $this->ledger->record($line, $context);

        // What was recorded, and never the note: free text does not belong in an audit that outlives erasure.
        // A replay of the same line writes nothing, so only a line written now is a change.
        if ($recorded->inserted) {
            $context->change(
                Subject::registration($key),
                $kind->value,
                null,
                [
                    'line' => $recorded->line->id,
                    'payer' => $payer,
                    'amount' => $amount->amount(),
                    'currency' => $currency->value,
                    'method' => $method->value,
                    'value_date' => $valueDate,
                    'correction' => $correction,
                ]
            );
        }

        return ['id' => $recorded->line->id];
    }
}
