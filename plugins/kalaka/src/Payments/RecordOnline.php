<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Payments\Api\OnlineItem;
use Kalaka\Payments\Api\OnlineRefs;
use Kalaka\Payments\Api\RegistrationRef;
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

    /** What the kind of report adds to the schema: its properties and the ones it requires. */
    /** @return array{properties: array<string, array<string, mixed>>, required: list<string>} */
    abstract protected function extraSchema(): array;

    abstract protected function kind(): LineKind;

    /** The idempotency key's middle part of the item's line: `order:<ref>` or `refund:<ref>`. */
    /** @param array<string, mixed> $input */
    abstract protected function keyPart(array $input): string;

    /** Whether the provider split the amount over the items itself: a mark on the audit entry. */
    /** @param array<string, mixed> $input */
    protected function allocated(array $input): bool
    {
        return false;
    }

    /** When the money moved: the line's `paidAt`. */
    /** @param array<string, mixed> $input */
    abstract protected function at(array $input): \DateTimeImmutable;

    /**
     * The report's part of the input, as the schema takes it.
     *
     * @param list<OnlineItem> $items
     * @return array<string, mixed>
     */
    protected static function reportInput(string $provider, string $request, array $items): array
    {
        return [
            'provider' => $provider,
            'request' => $request,
            'items' => array_map(
                static fn (OnlineItem $item): array => [
                    'event_id' => $item->registration->eventId,
                    'code' => $item->registration->code,
                    'payer' => $item->payer,
                    'amount' => $item->amount->amount(),
                    'currency' => $item->amount->currency()->value,
                    'item_ref' => $item->itemRef,
                ],
                $items
            ),
        ];
    }

    public function inputSchema(): array
    {
        $extra = $this->extraSchema();

        return [
            'type' => 'object',
            'properties' => [
                'provider' => ['type' => 'string', 'pattern' => OnlineRefs::PROVIDER_SCHEMA],
                'request' => ['type' => 'string', 'pattern' => OnlineRefs::REF_SCHEMA],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'integer', 'minimum' => 1],
                            'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}\\z'],
                            'payer' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,49}\\z'],
                            'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?\\z'],
                            'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                            'item_ref' => ['type' => 'string', 'pattern' => OnlineRefs::REF_SCHEMA],
                        ],
                        'required' => ['event_id', 'code', 'payer', 'amount', 'currency', 'item_ref'],
                        'additionalProperties' => false,
                    ],
                ],
                ...$extra['properties'],
            ],
            'required' => ['provider', 'request', 'items', ...$extra['required']],
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
        foreach ($this->items($input) as $item) {
            $keys[$item->registration->eventId . "\0" . $item->registration->code] = $item->registration;
        }

        return array_values($keys);
    }

    /**
     * The report's items, from the checked input.
     *
     * @param array<string, mixed> $input
     * @return list<OnlineItem>
     * @throws \InvalidArgumentException
     */
    private function items(array $input): array
    {
        $items = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = $input['items'];
        foreach ($rows as $row) {
            $currency = Currency::tryFrom((string) $row['currency']);
            if ($currency === null) {
                throw new \InvalidArgumentException('An item names a currency the plugin does not know.');
            }
            $items[] = new OnlineItem(
                new RegistrationKey((int) $row['event_id'], (string) $row['code']),
                (string) $row['payer'],
                Money::of((string) $row['amount'], $currency),
                (string) $row['item_ref']
            );
        }

        return $items;
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input, Context $context): int
    {
        $items = $this->items($input);
        OnlineRefs::check((string) $input['provider'], (string) $input['request'], $items);

        // Every registration first, in key order, and only then the lines.
        $keys = [];
        foreach ($items as $item) {
            $keys[$item->registration->eventId . "\0" . $item->registration->code] = $item->registration;
        }
        usort(
            $keys,
            static fn (RegistrationKey $a, RegistrationKey $b): int => $a->eventId <=> $b->eventId
                ?: strcmp($a->code, $b->code)
        );
        foreach ($items as $item) {
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
        foreach ($items as $item) {
            $key = $item->registration;
            if (!$held[$key->eventId . "\0" . $key->code]) {
                Boundary::report(
                    'Recording an online ' . $this->kind()->value,
                    new NotFound('Registration ' . RegistrationRef::compose($key) . ' of ' . $input['request'])
                );
                continue;
            }
            $written += $this->write($item, $input, $context) ? 1 : 0;
        }

        return $written;
    }

    /** @param array<string, mixed> $input */
    private function write(OnlineItem $item, array $input, Context $context): bool
    {
        $key = $item->registration;
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            $error = new SystemFailure(null, new \LogicException('The event of a held registration is gone.'));
            throw $error;
        }
        // Currency has one case today, so the comparison is constant until a second currency is added.
        // @phpstan-ignore notIdentical.alwaysFalse
        if ($item->amount->currency() !== $event->currency) {
            $error = new SystemFailure(
                null,
                new \LogicException(
                    'An item of ' . (string) $input['request'] . ' is in ' . $item->amount->currency()->value
                    . ', the event\'s currency is ' . $event->currency->value . '.'
                )
            );
            throw $error;
        }
        $amount = $this->kind() === LineKind::Payment ? $item->amount->negated() : $item->amount;
        $line = new NewLine(
            $key,
            $this->kind(),
            $item->payer,
            $amount,
            new Subject((string) $input['provider'] . '_order', $key->eventId, (string) $input['request']),
            'online:' . (string) $input['provider'] . ':' . $this->keyPart($input) . ':' . $item->itemRef,
            null,
            $this->at($input),
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
                    'provider' => (string) $input['provider'],
                    'request' => (string) $input['request'],
                    'allocated' => $this->allocated($input),
                ]
            );
        }
        $this->mails->lineRecorded($context, $recorded);

        return $recorded->inserted;
    }
}
