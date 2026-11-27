<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LedgerEntry;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Ledger\Api\Side;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Pricing\Api\Confirmations;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\NoOfferSeats;
use Kalaka\Registrations\Api\OfferSeats;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\RequestState;
use Kalaka\Registrations\Api\Requests;

/**
 * The admin detail read of one registration (the data of `GET kalaka-admin/v1/events/{event}/registrations/{code}`).
 * Everything in it goes through views and grants: the registration data is read through `ViewQuery` for the
 * views that hold the registration (`Access::holding()`), or full access, so it shows only what their columns
 * list, and the money block only to a holder of a money action of a grant whose view holds the registration.
 * The manual confirmation flag shows behind the same gate as the money. The requests (and whether they are
 * answered) show only to a holder of the answer action of a grant whose view holds the registration.
 * [UNAPPROVED Access] [UNAPPROVED N11] [UNAPPROVED N6]
 */
final class RegistrationDetail
{
    public function __construct(
        private readonly Access $access = new Access(),
        private readonly ViewQuery $views = new ViewQuery(),
        private readonly Events $events = new Events(),
        private readonly Forms $forms = new Forms(),
        private readonly Decisions $decisions = new Decisions(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly PayerKinds $payerKinds = new PayerKinds(),
        private readonly LineNames $lineNames = new LineNames(),
        private readonly ManualConfirmations $manualConfirmations = new ManualConfirmations(),
        private readonly ActionMails $actionMails = new ActionMails(),
        private readonly Confirmations $confirmations = new Confirmations(),
        private readonly Requests $requests = new Requests(),
        private readonly OfferSeats $seats = new NoOfferSeats(),
    ) {
    }

    /**
     * @return array<string, mixed> the read, as JSON-ready data
     * @throws RegistrationNotInView when the actor holds a grant on the event and the registration exists, but
     *                               no view of theirs holds it
     * @throws NotFound when the event or the registration does not exist, or the actor has no grant on the event
     *                  and no full access
     */
    public function of(Actor $actor, int $eventId, string $code): array
    {
        try {
            $key = new RegistrationKey($eventId, $code);
        } catch (InvalidInput) {
            // Not a code at all: no such registration.
            throw new NotFound();
        }
        $event = $this->events->find($eventId);
        if ($event === null) {
            throw new NotFound();
        }

        $full = $this->access->isFull($actor);
        $holding = $full ? [] : $this->access->holding($actor, $key);
        if (!$full && $holding === []) {
            $error = $this->refusal($actor, $key);
            throw $error;
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
        $record = $carried(Ledger::RECORD_MONEY);
        $viewMoney = $carried(Ledger::VIEW_MONEY) || $record;
        // [UNAPPROVED A1] one money-write action carries a payment, a refund, a correction, a manual charge
        // and a manual discount.
        // The owner's topic 1 ruling, H3: confirmation applies only to a registered registration.
        $confirmManually = $carried(ManualConfirmations::CONFIRM_MANUALLY);
        // The status is read from the registration itself: the view may not list the status column.
        $registered = $this->manualConfirmations->canSet($key);
        $confirmable = $confirmManually && $registered;
        $confirmed = $this->confirmations->isConfirmed($key);
        $canUnconfirm = $confirmManually && $confirmed;

        $read = [
            'registration' => $this->registrationPart($registration),
            'fields' => $this->fieldsPart($registration),
        ];
        $read['registration']['confirmed'] = $confirmed;
        if ($canUnconfirm) {
            $reconfirms = $this->unconfirmReconfirms($key);
            $read['registration']['unconfirm_reconfirms'] = $reconfirms === null ? null
                : ($viewMoney ? $reconfirms : 'yes');
            $read['registration']['unconfirm_warning'] = $this->unconfirmWarning($key, $viewMoney, $reconfirms);
        }
        if ($viewMoney) {
            $read['registration']['manual_confirmation'] = $this->manualConfirmationPart($key);
            $read['money'] = $this->moneyPart($key, $event->currency->value);
        }
        $answer = $carried(Requests::ANSWER);
        if ($answer) {
            $read['requests'] = $this->requestsPart($key, $event->currency->value, $registered, $viewMoney);
        }
        $read['can'] = [
            // A request is answerable only while the registration is registered, as the grid column says (N655).
            'answer_request' => $answer && $registered,
            'view_money' => $viewMoney,
            'record_money' => $record,
            // A manual charge or discount is offered only while the registration is registered ([UNAPPROVED]).
            'record_charge' => $record && $registered,
            'record_discount' => $record && $registered,
            'confirm_manually' => $confirmable,
            'unconfirm' => $canUnconfirm,
        ];

        return $read;
    }

    /**
     * The owner's ruling 2026-11-19: a grant holder of the event is told "does not exist" for an unknown code
     * and "no access" for one that exists outside their views (probing codes is accepted); a user with no grant
     * on the event gets "not found" either way.
     */
    private function refusal(Actor $actor, RegistrationKey $key): KalakaException
    {
        if (!in_array($key->eventId, $this->access->grantedEventIds($actor), true)) {
            return new NotFound();
        }

        return $this->views->filterHolds($key->eventId, null, null, [$key])
            ? new RegistrationNotInView()
            : new NotFound();
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

    /**
     * The registration's requests with their latest answers, for the answer act's block: `state` is `awaiting`
     * while the source's answer field holds none (the field is the truth) and the registration is registered,
     * `inactive` for such an unanswered request on a registration that is not registered (waitlisted, withdrawn,
     * removed: the grid column's meaning, N655), else the act's record says
     * `approved` or `refused`; `amount` is the aid award as text (0 for a refused aid request), null for an offer
     * and before an answer; `seat_full` is true for a refused offer whose pool is full now, the warning before a
     * "yes" that would go over the limit (B1). An aid item also carries `aid`, the money context (`aidTerms()`).
     * No hold is read: awaiting is
     * computed.
     *
     * The money figures — the item's `amount` and an aid item's `aid` — follow the read's money gate (owner
     * 2026-11-27, the access sweep): a holder of the answer action whose view and grant give no money fields reads
     * the requests without them, so no figure reaches a holder the view/grant does not give the money fields.
     * [UNAPPROVED N1134]
     *
     * @return array{currency: string, items: list<array<string, mixed>>}
     */
    private function requestsPart(RegistrationKey $key, string $currency, bool $registered, bool $viewMoney): array
    {
        $items = [];
        foreach ($this->requests->states($key) as $state) {
            $answer = $state->answer;
            $pool = $state->request->kind === RequestKind::Offer ? $state->source->seatPool($state->request) : null;
            $item = [
                'id' => $state->request->id,
                'kind' => $state->request->kind->value,
                'label' => $state->request->label,
                'state' => $state->awaits()
                    ? ($registered ? 'awaiting' : 'inactive')
                    : ($answer?->approved === true ? 'approved' : 'refused'),
                'amount' => $viewMoney ? $answer?->amount?->amount() : null,
                'answered_at' => $answer === null ? null : self::instant($answer->answeredAt),
                'seat_full' => $state->request->kind === RequestKind::Offer && $answer !== null && !$answer->approved
                    && $pool !== null && $this->seats->isFull($key, $pool),
            ];
            if ($state->request->kind === RequestKind::Aid && $viewMoney) {
                $item['aid'] = $this->aidTerms($key, $state);
            }
            $items[] = $item;
        }

        return ['currency' => $currency, 'items' => $items];
    }

    /**
     * The aid request's money context, from the source's `terms()`: `full_price` (what the participant has to pay
     * before the aid), `can_pay` (what they stated, null when none) and `asked` (the difference, never below
     * zero) and `max_award` (the most an award can be: the full price today, named apart so the rule can change),
     * money amounts as text in the read's currency, formatted as the item's `amount`; the dialog computes nothing:
     * it hints `asked` and warns above `max_award`, which the act refuses. The part follows the read's money gate,
     * as the item's `amount` does (`requestsPart()`): answering sets a money amount, but the figures are only for a
     * holder whose view/grant gives the money fields. Null when the source gives no terms or a fee component
     * fails.
     * [UNAPPROVED N1131]
     *
     * @return ?array{full_price: string, can_pay: ?string, asked: ?string, max_award: string}
     */
    private function aidTerms(RegistrationKey $key, RequestState $state): ?array
    {
        try {
            $terms = $state->source->terms(
                $key,
                $state->request,
                $state->values,
                Runner::current()->clock()->now()
            );
        } catch (FeeComponentFailed) {
            return null;
        }

        return $terms === null ? null : [
            'full_price' => $terms->fullPrice->amount(),
            'can_pay' => $terms->canPay?->amount(),
            'asked' => $terms->asked()?->amount(),
            'max_award' => $terms->maxAmount()->amount(),
        ];
    }

    /**
     * Whether the unconfirm would confirm the registration again at once, and why: `'payments'`, `'nothing_due'`
     * or null (also when a fee component fails: the act itself would fail then, too). The caller has read that
     * the registration is confirmed.
     *
     * @return 'payments'|'nothing_due'|null
     */
    private function unconfirmReconfirms(RegistrationKey $key): ?string
    {
        try {
            return $this->manualConfirmations->reconfirmsOnUnconfirm($key, Runner::current()->clock()->now(), true);
        } catch (FeeComponentFailed) {
            return null;
        }
    }

    /**
     * The warning to show before staff's unconfirm: the text when confirmation would decide `confirmed` again
     * at once, null otherwise. A holder with money-view gets the reason, payments or nothing due; a holder
     * without it gets one text that names neither a reason nor money, so the read never tells them what the
     * payments are. While the event's confirmed mail is on, one sentence is appended: the registrant gets that
     * mail again (the owner's ruling 2026-11-23, topic 15, Q4). It reads and writes nothing else.
     * [UNAPPROVED N11, Q4, T5a, T5b, T5c, T6]
     */
    private function unconfirmWarning(RegistrationKey $key, bool $viewMoney, ?string $reconfirms): ?string
    {
        if ($reconfirms === null) {
            return null;
        }
        if (!$viewMoney) {
            $text = 'A jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.'; // [UNAPPROVED T5c]
        } elseif ($reconfirms === 'payments') {
            // [UNAPPROVED T5a]
            $text = 'A befizetések már elérik a fizetendő összeget, ezért a jelentkezés a visszavonás után azonnal '
                . 'újra visszaigazolt lesz.';
        } else {
            // [UNAPPROVED T5b]
            $text = 'Nincs fizetendő összeg, ezért a jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.';
        }

        return $this->actionMails->isOn($key->eventId, ActionMail::Confirmed)
            ? $text . ' Ekkor a jelentkező megkapja a „Jelentkezés véglegesítve” levelet.' // [UNAPPROVED T6]
            : $text;
    }

    /**
     * The manual confirmation flag while it is set: its set time, its reason and the display name of who set
     * it (null for a run without a user); null when it is not set.
     *
     * @return ?array{set_at: string, reason: string, set_by: ?string}
     */
    private function manualConfirmationPart(RegistrationKey $key): ?array
    {
        $flag = $this->manualConfirmations->of($key);
        if ($flag === null) {
            return null;
        }
        $by = null;
        if ($flag->setBy !== null) {
            $user = get_userdata($flag->setBy);
            $by = $user === false ? null : (string) $user->display_name;
        }

        return ['set_at' => self::instant($flag->setAt), 'reason' => $flag->reason, 'set_by' => $by];
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
     * refund positive), shown with its `kind`; a payer's `charged`, `paid` and `balance` come from the Ledger's
     * own summary, with `charged` and `paid` positive and `balance` signed (above zero due, below zero
     * overpaid). The read invents no display amount.
     *
     * @return array<string, mixed>
     */
    private function moneyPart(RegistrationKey $key, string $currency): array
    {
        $entries = $this->ledger->entriesOf($key);
        $summary = $this->ledger->summaryOf($key);
        $money = Currency::from($currency);
        $zero = Money::zero($money);

        // The participant first, then the event's other payer kinds (with or without lines), then payers that
        // have lines but no declared kind, in the order the lines are shown. Each payer's totals are the
        // Ledger's, never recomputed here.
        $order = [];
        foreach ($this->payerKinds->of($key->eventId) as $kind) {
            $order[$kind->id] = $kind->label;
        }
        foreach ($entries as $entry) {
            $order[$entry->payer] ??= null;
        }

        $payers = [];
        $charged = $zero;
        $paid = $zero;
        foreach ($order as $payer => $label) {
            $sum = $summary->of($payer, $money);
            $charged = $charged->plus($sum->charged);
            $paid = $paid->plus($sum->paid);
            $payers[] = [
                'payer' => $payer,
                'label' => $label,
                'charged' => $sum->charged->amount(),
                'paid' => $sum->paid->amount(),
                'balance' => $sum->balance->amount(),
            ];
        }

        // The owed lines are named from their source: the fixed price, a manual charge or discount.
        $owedSources = [];
        foreach ($entries as $entry) {
            if ($entry->kind->side() === Side::Owed) {
                $owedSources[] = $entry->source;
            }
        }
        $lineNames = $owedSources === [] ? [] : $this->lineNames->of($key, $owedSources);
        $names = $this->userNames($entries, $lineNames);

        return [
            'currency' => $currency,
            'payers' => $payers,
            'total' => [
                'charged' => $charged->amount(),
                'paid' => $paid->amount(),
                'balance' => $charged->minus($paid)->amount(),
            ],
            'lines' => array_map(fn (LedgerEntry $entry): array => $this->line($entry, $names, $lineNames), $entries),
            'methods' => array_map(
                static fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases()
            ),
        ];
    }

    /**
     * @param array<int, string> $names display names by user ID
     * @param array<string, LineName> $lineNames the names of the owed lines, by source key
     * @return array<string, mixed>
     */
    private function line(LedgerEntry $entry, array $names, array $lineNames): array
    {
        $by = $entry->source->type === 'wp_user' ? ($names[(int) $entry->source->key] ?? null) : null;
        $side = $entry->kind->side();
        // `type` is the discriminator of the „Típus” column: the paid kinds as they are, an owed line by what
        // names it. A manual line carries its title, and who recorded it comes from its fact.
        $name = $side === Side::Owed ? ($lineNames[$entry->source->key] ?? null) : null;
        if ($name !== null) {
            $by = $name->recordedBy === null ? $by : ($names[$name->recordedBy] ?? null);
        }

        return [
            'id' => $entry->id,
            'kind' => $entry->kind->value,
            'type' => $name->type ?? $entry->kind->value,
            'side' => $side->value,
            'kind_label' => $name->label ?? $entry->kind->label(),
            'title' => $name?->title,
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
     * @param array<string, LineName> $lineNames
     * @return array<int, string>
     */
    private function userNames(array $entries, array $lineNames): array
    {
        $ids = [];
        foreach ($lineNames as $name) {
            if ($name->recordedBy !== null) {
                $ids[$name->recordedBy] = true;
            }
        }
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
