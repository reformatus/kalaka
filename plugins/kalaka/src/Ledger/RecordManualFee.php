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
use Kalaka\Ledger\Api\ManualFee;
use Kalaka\Ledger\Api\ManualFeeType;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The shared body of "record charge" and "record discount" (money.md, "Recording payments by hand"): a manual
 * charge or discount of one `registered` registration, one payer, a positive amount and a required title the
 * registrant sees. It writes the fact (`kalaka_manual_fees`); the evaluation of the same run composes it into
 * its own `charge` line. With `completed` it also records, in the same run, the payment (for a charge) or the
 * refund (for a discount) of the same amount, with its method, value date and an optional comment, as a
 * second, plain entry: never a correction, and no link between the two. One audit row, one caller key: the
 * fact's key is `manual_fee:<key>` and the paid line's `manual_fee:<key>:paid`, so a replay writes nothing and
 * the same key with another input, the tick included, is an `IdempotencyConflict`. Neither the title nor the
 * comment reaches the audit.
 */
abstract class RecordManualFee implements AdminUseCase
{
    private const TITLE_MAX = 191;

    public function __construct(
        private readonly Ledger $ledger = new Ledger(),
        private readonly Events $events = new Events(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly PayerKinds $payerKinds = new PayerKinds(),
        private readonly ActionMails $mails = new ActionMails(),
    ) {
    }

    abstract protected function type(): ManualFeeType;

    public function action(): string
    {
        return Ledger::RECORD_MONEY;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}\\z'],
                'payer' => [
                    'type' => 'string',
                    'pattern' => '^[a-z][a-z0-9_]{0,49}\\z',
                    'default' => Payer::PARTICIPANT,
                ],
                'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?\\z'],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::TITLE_MAX],
                'completed' => ['type' => 'boolean', 'default' => false],
                ...ManualEntryParts::entrySchema(),
                'idempotency_key' => ['type' => 'string', 'pattern' => ManualEntryParts::KEY_PATTERN],
            ],
            'required' => ['event_id', 'code', 'amount', 'currency', 'title', 'idempotency_key'],
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
     * @return array{id: int, line?: int}
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
        $decimals = ManualEntryParts::decimals((string) $input['amount']);
        if (!$amount->isPositive() || $decimals > $currency->minorUnitDecimals()) {
            throw new InvalidInput();
        }
        $payer = isset($input['payer']) ? (string) $input['payer'] : Payer::PARTICIPANT;
        $declared = array_map(static fn (PayerKind $kind): string => $kind->id, $this->payerKinds->of($event->id));
        if (!in_array($payer, $declared, true)) {
            throw new InvalidInput();
        }
        $title = trim((string) $input['title']);
        if ($title === '' || mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            throw new InvalidInput();
        }
        $completed = ($input['completed'] ?? false) === true;
        $method = null;
        $valueDate = null;
        if ($completed) {
            $method = PaymentMethod::tryFrom((string) ($input['method'] ?? ''));
            $valueDate = (string) ($input['value_date'] ?? '');
            if ($method === null || !ManualEntryParts::isPastDay($valueDate, $context->now())) {
                throw new InvalidInput();
            }
        } elseif (isset($input['method']) || isset($input['value_date']) || isset($input['note'])) {
            // The payment's own controls belong to the ticked box only.
            throw new InvalidInput();
        }
        $comment = trim((string) ($input['note'] ?? ''));

        if (!$this->registrations->lock($key, $context)) {
            throw new NotFound();
        }
        $type = $this->type();
        $userId = ManualEntryParts::userIdOf($context->actor());
        $fee = new ManualFee(
            0,
            $key,
            $type,
            $payer,
            $amount,
            $title,
            'manual_fee:' . $input['idempotency_key'],
            $userId,
            $context->now(),
        );
        $paid = $completed
            ? $this->paidLine($key, $type, $payer, $amount, $method, $valueDate, $comment, $fee, $context)
            : null;

        $stored = $this->ledger->manualFeeUnderKey($key, $fee->idempotencyKey);
        if ($stored !== null) {
            // A replay: the same entry, and the same tick, writes nothing.
            if (
                $this->ledger->keyHoldsDifferentFee($fee)
                || $this->ledger->holdsLineKey($key, $fee->idempotencyKey . ':paid') !== $completed
                || ($paid !== null && $this->ledger->keyHoldsDifferentLine($paid))
            ) {
                throw new IdempotencyConflict();
            }

            return $this->result($stored, $paid, $context);
        }
        $status = $this->registrations->find($key)?->status;
        if ($status !== RegistrationStatus::Registered) {
            // Only a registered registration composes a fee, so only it can owe the entry.
            throw new InvalidInput();
        }

        $recorded = $this->ledger->recordManualFee($fee, $context);
        $recordedPaid = $paid === null ? null : $this->ledger->record($paid, $context);
        $line = $recordedPaid?->line;
        // What was recorded, never the title or the comment: free text does not belong in an audit that
        // outlives erasure.
        if ($line !== null) {
            $context->change(
                Subject::registration($key),
                $line->kind->value,
                null,
                [
                    'line' => $line->id,
                    'payer' => $payer,
                    'amount' => $amount->amount(),
                    'currency' => $currency->value,
                    'method' => $line->method?->value,
                    'value_date' => $valueDate,
                    'correction' => false,
                ]
            );
        }

        if ($recordedPaid !== null) {
            // The paid entry is a full payment (or refund) like any other: it decides the "payment recorded" mail
            // by the same rule, keyed by its line. [UNAPPROVED N1165]
            $this->mails->lineRecorded($context, $recordedPaid);
        }

        return ['id' => $recorded->fee->id] + ($line === null ? [] : ['line' => $line->id]);
    }

    /** The paid entry of a completed record: a payment for a charge, a refund for a discount. */
    private function paidLine(
        RegistrationKey $key,
        ManualFeeType $type,
        string $payer,
        Money $amount,
        ?PaymentMethod $method,
        ?string $valueDate,
        string $comment,
        ManualFee $fee,
        Context $context,
    ): NewLine {
        $charge = $type === ManualFeeType::Charge;

        return new NewLine(
            $key,
            $charge ? LineKind::ManualPayment : LineKind::ManualRefund,
            $payer,
            $charge ? $amount->negated() : $amount,
            ManualEntryParts::sourceOf($context->actor()),
            $fee->idempotencyKey . ':paid',
            $valueDate,
            null,
            $method,
            false,
            $comment === '' ? null : $comment,
        );
    }

    /**
     * What a replay returns: the stored entry, and the paid line when the call was ticked.
     *
     * @return array{id: int, line?: int}
     */
    private function result(ManualFee $stored, ?NewLine $paid, Context $context): array
    {
        $line = $paid === null ? null : $this->ledger->record($paid, $context)->line;

        return ['id' => $stored->id] + ($line === null ? [] : ['line' => $line->id]);
    }
}
