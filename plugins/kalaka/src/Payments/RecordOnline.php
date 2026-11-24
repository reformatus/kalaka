<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Payments\Api\OnlineItem;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The shared body of recording a paid request and a refund, run as the system (money.md, "Payments"). It locks
 * every registration of the report first, in key order (foundation.md, "Lock order"), then writes one line per
 * item, keyed by the provider's request or refund and the item, so a repeated report writes nothing and queues
 * no second mail. Never gated: the registration's status, the event's state and the amount against the balance
 * are not looked at; a surplus is an overpayment. An item whose registration is gone is reported and skipped.
 */
abstract class RecordOnline implements AdminUseCase
{
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly Events $events = new Events(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly ActionMails $mails = new ActionMails(),
    ) {
    }

    abstract protected function providerId(): string;

    abstract protected function requestRef(): string;

    /** @return list<OnlineItem> */
    abstract protected function items(): array;

    abstract protected function kind(): LineKind;

    /** The idempotency key's middle part of the item's line: `order:<ref>` or `refund:<ref>`. */
    abstract protected function keyPart(): string;

    /** Whether the provider split the amount over the items itself: a mark on the audit entry. */
    protected function allocated(): bool
    {
        return false;
    }

    /** When the money moved: the line's `paidAt`. */
    abstract protected function at(): \DateTimeImmutable;

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'provider' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,31}\\z'],
                'request' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_.-]{1,40}\\z'],
            ],
            'required' => ['provider', 'request'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    /** The registrations of the report, so the audit entry is about them (the system has full access). */
    public function targets(array $input): array
    {
        $keys = [];
        foreach ($this->items() as $item) {
            $keys[$item->registration->eventId . "\0" . $item->registration->code] = $item->registration;
        }

        return array_values($keys);
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input, Context $context): int
    {
        if ($input['provider'] !== $this->providerId() || $input['request'] !== $this->requestRef()) {
            $error = new SystemFailure(null, new \LogicException('The input names another request than the report.'));
            throw $error;
        }

        // Every registration first, in key order, and only then the lines.
        $keys = [];
        foreach ($this->items() as $item) {
            $keys[$item->registration->eventId . "\0" . $item->registration->code] = $item->registration;
        }
        usort(
            $keys,
            static fn (RegistrationKey $a, RegistrationKey $b): int => $a->eventId <=> $b->eventId
                ?: strcmp($a->code, $b->code)
        );
        foreach ($this->items() as $item) {
            if ($item->payer !== Payer::PARTICIPANT) {
                $error = new SystemFailure(
                    null,
                    new \LogicException('Online payment covers the participant payer only.')
                );
                throw $error;
            }
        }
        $held = [];
        foreach ($keys as $key) {
            $held[$key->eventId . "\0" . $key->code] = $this->registrations->lock($key, $context);
        }

        $written = 0;
        foreach ($this->items() as $item) {
            $key = $item->registration;
            if (!$held[$key->eventId . "\0" . $key->code]) {
                Boundary::report(
                    'Recording an online ' . $this->kind()->value,
                    new NotFound('Registration ' . $key->eventId . ':' . $key->code . ' of ' . $this->requestRef())
                );
                continue;
            }
            $written += $this->write($item, $context) ? 1 : 0;
        }

        return $written;
    }

    private function write(OnlineItem $item, Context $context): bool
    {
        $key = $item->registration;
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            $error = new SystemFailure(null, new \LogicException('The event of a held registration is gone.'));
            throw $error;
        }
        $amount = $this->kind() === LineKind::Payment ? $item->amount->negated() : $item->amount;
        $line = new NewLine(
            $key,
            $this->kind(),
            $item->payer,
            $amount,
            new Subject($this->providerId() . '_order', $key->eventId, $this->requestRef()),
            strtolower('online:' . $this->providerId() . ':' . $this->keyPart() . ':' . $item->itemRef),
            null,
            $this->at(),
        );
        if ($this->ledger->keyHoldsDifferentLine($line)) {
            $error = new SystemFailure(
                null,
                new \LogicException('A report repeats a stored line with other content: ' . $line->idempotencyKey)
            );
            throw $error;
        }
        $recorded = $this->ledger->record($line, $context);
        if ($recorded->inserted) {
            $context->change(
                Subject::registration($key),
                $this->kind()->value,
                null,
                [
                    'line' => $recorded->line->id,
                    'payer' => $item->payer,
                    'amount' => $item->amount->amount(),
                    'currency' => $item->amount->currency()->value,
                    'provider' => $this->providerId(),
                    'request' => $this->requestRef(),
                    'allocated' => $this->allocated(),
                ]
            );
        }
        $this->mails->lineRecorded($context, $recorded);

        return $recorded->inserted;
    }
}
