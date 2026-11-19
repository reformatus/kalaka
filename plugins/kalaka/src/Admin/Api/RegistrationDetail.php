<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LedgerEntry;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The admin detail read of one registration (the data of `GET kalaka-admin/v1/events/{event}/registrations/{code}`).
 * Everything in it goes through views and grants: the registration data is read through `ViewQuery` for the
 * views that hold the registration (`Access::holding()`), or full access, so it shows only what their columns
 * list, and the money block only to a holder of a money action of a grant whose view holds the registration.
 * [UNAPPROVED Access]
 */
final class RegistrationDetail
{
    /** The per-event action that lets a grant holder see the money of the registrations of its view. */
    public const VIEW_MONEY = 'kalaka_view_money';

    public const RECORD_PAYMENT = 'kalaka_record_payment';

    public const RECORD_REFUND = 'kalaka_record_refund';

    public function __construct(
        private readonly Access $access = new Access(),
        private readonly ViewQuery $views = new ViewQuery(),
        private readonly Events $events = new Events(),
        private readonly Forms $forms = new Forms(),
        private readonly Decisions $decisions = new Decisions(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly PayerKinds $payerKinds = new PayerKinds(),
    ) {
    }

    /**
     * @return array<string, mixed> the read, as JSON-ready data
     * @throws NotFound when the event or registration does not exist, or the actor's grants and views do not
     *                  hold it: the same answer, so a code's existence is never revealed
     */
    public function of(Actor $actor, int $eventId, string $code): array
    {
        try {
            $key = new RegistrationKey($eventId, $code);
        } catch (InvalidInput) {
            throw new NotFound();
        }
        $event = $this->events->find($eventId);
        if ($event === null) {
            throw new NotFound();
        }

        $full = $this->access->isFull($actor);
        $holding = $full ? [] : $this->access->holding($actor, $key);
        if (!$full && $holding === []) {
            throw new NotFound();
        }
        $views = $full
            ? [$this->access->master($actor, $eventId)]
            : array_map(static fn (array $held): HeldView => $held['view'], $holding);
        $registration = $this->views->registration($views, $code);
        if ($registration === null) {
            throw new NotFound();
        }

        $carried = static function (string $action) use ($full, $holding): bool {
            if ($full) {
                return true;
            }
            foreach ($holding as $held) {
                if (in_array($action, $held['actions'], true)) {
                    return true;
                }
            }

            return false;
        };
        // The owner's ruling 2026-11-18: money-write includes money-view.
        $record = $carried(self::RECORD_PAYMENT);
        $refund = $carried(self::RECORD_REFUND);
        $viewMoney = $carried(self::VIEW_MONEY) || $record || $refund;

        $read = [
            'registration' => $this->registrationPart($registration),
            'fields' => $this->fieldsPart($registration),
        ];
        if ($viewMoney) {
            $read['money'] = $this->moneyPart($key, $event->currency->value);
        }
        $read['can'] = ['view_money' => $viewMoney, 'record_payment' => $record, 'record_refund' => $refund];

        return $read;
    }

    /** @return array<string, mixed> */
    private function registrationPart(ViewRegistration $registration): array
    {
        $part = [];
        if ($registration->lists('code')) {
            $part['code'] = $registration->key->code;
        }

        $keys = $registration->slotForm === null
            ? ['email' => null, 'name' => null]
            : $this->forms->contactKeys($registration->slotForm['form_id'], $registration->slotForm['form_version']);
        foreach (['name', 'email'] as $header) {
            $field = $keys[$header];
            if ($field !== null && $registration->lists('field:' . $field)) {
                $part[$header] = $registration->fields[$field] ?? null;
            }
        }

        if ($registration->lists('serial')) {
            $part['serial'] = $registration->serial;
        }
        if ($registration->status !== null) {
            $part['status'] = $registration->status->value;
            // The word of the status as the grid shows it: the same source, its first letter capitalised.
            $word = $registration->status->word();
            $part['status_label'] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_substr($word, 1, null, 'UTF-8');
        }
        if ($registration->registeredAt !== null) {
            $part['registered_at'] = self::instant($registration->registeredAt);
        }
        if ($registration->submittedAt !== null) {
            $part['submitted_at'] = self::instant($registration->submittedAt);
        }

        $declared = [];
        foreach ($this->decisions->kinds() as $kind) {
            $declared[$kind->kind] = $kind;
        }
        $part['decisions'] = [];
        foreach ($declared as $kind => $declaredKind) {
            $current = $registration->decisions[$kind] ?? null;
            if ($current === null) {
                continue;
            }
            $part['decisions'][] = [
                'kind' => $kind,
                'label' => $declaredKind->label,
                'state' => $current->state,
                'state_label' => $declaredKind->states[$current->state] ?? $current->state,
                'effective_at' => self::instant($current->effectiveAt),
            ];
        }

        return $part;
    }

    /** @return list<array<string, mixed>> */
    private function fieldsPart(ViewRegistration $registration): array
    {
        $stored = [];
        foreach ($registration->fields as $key => $value) {
            if ($value !== null) {
                $stored[$key] = $value;
            }
        }
        $fields = [];
        foreach ($this->forms->participantFieldValues($registration->key->eventId, $stored) as $pair) {
            $field = $pair['field'];
            if ($field->source === ParticipantField::SOURCE_SYSTEM || !$registration->lists('field:' . $field->key)) {
                continue;
            }
            $answer = $stored[$field->key] ?? null;
            if ($answer === null && $field->retired) {
                continue;
            }
            // The value's data and text are the field type's own: the formatter the grid and the mails use.
            $fields[] = [
                'key' => $field->key,
                'label' => $field->title,
                'type' => $field->type,
                'value' => $pair['value']->data ?? $answer,
                'text' => $pair['value']->text !== '' ? $pair['value']->text : ($answer ?? ''),
            ];
        }

        return $fields;
    }

    /**
     * The money of the registration. A line's `amount` is the stored signed amount (a payment is negative, a
     * refund positive), shown with its `kind`; a payer's `charged` and `paid` are positive sums and its
     * `balance` is signed (above zero due, below zero overpaid). The read invents no display amount.
     *
     * @return array<string, mixed>
     */
    private function moneyPart(RegistrationKey $key, string $currency): array
    {
        $entries = $this->ledger->entriesOf($key);
        $zero = Money::zero(Currency::from($currency));

        // The participant first, then the event's other payer kinds (with or without lines), then payers that
        // have lines but no declared kind. The sums come from the lines this read shows: charged from the lines
        // that are not payment facts, paid from the payment facts (a refund lowers it), so one statement reads
        // the money.
        $order = [];
        foreach ($this->payerKinds->of($key->eventId) as $kind) {
            $order[$kind->id] = $kind->label;
        }
        $sums = [];
        foreach ($entries as $entry) {
            $order[$entry->payer] ??= null;
            $sums[$entry->payer] ??= ['charged' => $zero, 'paid' => $zero];
            if ($entry->kind->isPaymentFact()) {
                $sums[$entry->payer]['paid'] = $sums[$entry->payer]['paid']->minus($entry->amount);
            } else {
                $sums[$entry->payer]['charged'] = $sums[$entry->payer]['charged']->plus($entry->amount);
            }
        }

        $payers = [];
        $charged = $zero;
        $paid = $zero;
        foreach ($order as $payer => $label) {
            $sum = $sums[$payer] ?? ['charged' => $zero, 'paid' => $zero];
            $charged = $charged->plus($sum['charged']);
            $paid = $paid->plus($sum['paid']);
            $payers[] = [
                'payer' => $payer,
                'label' => $label,
                'charged' => $sum['charged']->amount(),
                'paid' => $sum['paid']->amount(),
                'balance' => $sum['charged']->minus($sum['paid'])->amount(),
            ];
        }

        $names = $this->userNames($entries);

        return [
            'currency' => $currency,
            'payers' => $payers,
            'total' => [
                'charged' => $charged->amount(),
                'paid' => $paid->amount(),
                'balance' => $charged->minus($paid)->amount(),
            ],
            'lines' => array_map(fn (LedgerEntry $entry): array => $this->line($entry, $names), $entries),
            'methods' => array_map(
                static fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases()
            ),
        ];
    }

    /**
     * @param array<int, string> $names display names by user ID
     * @return array<string, mixed>
     */
    private function line(LedgerEntry $entry, array $names): array
    {
        $by = $entry->source->type === 'wp_user' ? ($names[(int) $entry->source->key] ?? null) : null;

        return [
            'id' => $entry->id,
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'payer' => $entry->payer,
            'amount' => $entry->amount->amount(),
            'value_date' => $entry->valueDate === null ? null : self::instant($entry->valueDate),
            'paid_at' => $entry->paidAt === null ? null : self::instant($entry->paidAt),
            'recorded_at' => self::instant($entry->recordedAt),
            'method' => $entry->method?->value,
            'method_label' => $entry->method?->label(),
            'note' => $entry->note,
            'correction' => $entry->correction,
            'recorded_by' => $by,
        ];
    }

    /**
     * The display names of the users that recorded the lines: one query for all of them.
     *
     * @param list<LedgerEntry> $entries
     * @return array<int, string>
     */
    private function userNames(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if ($entry->source->type === 'wp_user' && ctype_digit($entry->source->key)) {
                $ids[(int) $entry->source->key] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (get_users(['include' => array_keys($ids), 'fields' => ['ID', 'display_name']]) as $user) {
            $names[(int) $user->ID] = (string) $user->display_name;
        }

        return $names;
    }

    private static function instant(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
