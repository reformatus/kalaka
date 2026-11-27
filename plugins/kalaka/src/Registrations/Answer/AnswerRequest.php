<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Answer;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\AidAbovePrice;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\AnswerRead;
use Kalaka\Registrations\Api\NoOfferSeats;
use Kalaka\Registrations\Api\OfferSeats;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Registrations\Api\RequestState;

/**
 * "Answer a request" (foundation.md, "Facts, decisions and computed values"; money.md, "Sponsor and church
 * support"): the one act that answers a registration's requests, offers and aid alike, for staff and for a
 * partner (a grant holder whose view holds only their registrations), carried by `kalaka_answer_request`. Giving
 * a different answer later is this act again.
 *
 * - Input: the registration, the request's id, `yes` or `no`, for aid the amount awarded, and a note: optional, a
 *   "yes" to an offer (a partner's approval) too, where it is the reason (owner 2026-11-26: a reason is never
 *   required).
 *   For aid a "no" is an award of 0 Ft (B2) and a "yes" needs an amount above zero and no more than the full
 *   price the participant has to pay (`RequestSource::terms()`; a larger one is `AidAbovePrice`, nothing written);
 *   an offer takes no amount. [UNAPPROVED N1132]
 * - Writes one answer record per act (its id is the key the "answered" mail will take) and the answer as the
 *   source's own participant field value (B3: the source stays that field's only writer). Both are facts; the
 *   registration is then evaluated in the same run, so money, then confirmation, then mail, follow the answer.
 * - A repeat of the latest answer, the same yes or no, the same amount and no new note, writes nothing; a new
 *   note alone is a new act. Only a
 *   `registered` registration is answered (a waitlisted registration is inactive: its request waits until it is
 *   admitted); a waitlisted, withdrawn or removed one is a `RuleViolation`.
 * - A refusal frees the offer's seat; a "yes" after a refusal takes one again, and when the pool is full the
 *   answer stands and `over_limit` says so (B1). Both go through `OfferSeats`, a seam until slice A.
 *
 * Returns `{changed, answer, over_limit}`: the new record's id, null when nothing was written.
 */
final class AnswerRequest implements AdminUseCase
{
    public function __construct(
        private readonly Requests $requests = new Requests(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly AnswerRepository $answers = new AnswerRepository(),
        private readonly OfferSeats $seats = new NoOfferSeats(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/answer-request';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}$'],
                'request' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'answer' => ['type' => 'string', 'enum' => ['yes', 'no']],
                'amount' => ['type' => ['string', 'null'], 'maxLength' => 30],
                'note' => ['type' => ['string', 'null'], 'maxLength' => 500],
            ],
            'required' => ['event_id', 'code', 'request', 'answer'],
            'additionalProperties' => false,
        ];
    }

    public function action(): string
    {
        return Requests::ANSWER;
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{changed: bool, answer: ?int, over_limit: bool}
     */
    public function execute(array $input, Context $context): array
    {
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        $yes = $input['answer'] === 'yes';
        $note = isset($input['note']) ? trim((string) $input['note']) : '';
        if (mb_strlen($note, 'UTF-8') > 500) {
            throw new InvalidInput();
        }

        // The request is found with a plain read first: the seat's pool lock, which the request names, comes
        // before the registration lock. It is read again under the lock.
        $first = self::stateOf($this->requests->states($key), (string) $input['request']);
        $pool = $first->request->kind === RequestKind::Offer ? $first->source->seatPool($first->request) : null;
        if ($pool !== null) {
            $this->seats->lockPool($pool, $context);
        }
        if (!$this->registrations->lock($key, $context)) {
            throw new NotFound();
        }
        $state = self::stateOf($this->requests->states($key), (string) $input['request']);
        $request = $state->request;

        $status = $this->registrations->find($key)?->status;
        if ($status !== RegistrationStatus::Registered) {
            throw new RuleViolation();
        }

        $amount = $this->amountOf($request, $key, $yes, $input['amount'] ?? null);
        $answer = new Answer($yes, $amount, $note === '' ? null : $note);
        $previous = $state->answer;
        // An answer whose only change is a new note is a new act (a new row, so the "answered" mail goes out
        // again); an empty note adds nothing.
        $newNote = $answer->note !== null && $answer->note !== $previous?->note;
        if (
            $previous !== null && $previous->approved === $yes
            && self::sameAmount($previous->amount, $answer->amount) && !$newNote
        ) {
            return ['changed' => false, 'answer' => null, 'over_limit' => false];
        }

        // An aid award is no more than what the participant has to pay: refused, nothing written. The same
        // `terms()` is on the detail read, so the dialog can warn before the send. [UNAPPROVED N1132]
        if ($yes && $request->kind === RequestKind::Aid && $answer->amount !== null) {
            $terms = $state->source->terms($key, $request, $state->values, $context->now());
            if ($terms !== null && $answer->amount->isGreaterThan($terms->maxAmount())) {
                throw new AidAbovePrice();
            }
        }

        $record = $this->answers->insert(new AnswerRead(
            0,
            $key,
            $request->id,
            $answer->approved,
            $answer->amount,
            $answer->note,
            $context->now(),
            self::userOf($context),
        ), $context);
        $state->source->writeAnswer($key, $request, $answer, $context);

        $overLimit = false;
        if ($pool !== null) {
            if (!$yes) {
                $this->seats->release($key, $pool, $context);
            } elseif ($previous !== null && !$previous->approved) {
                $overLimit = !$this->seats->take($key, $pool, $context);
            }
        }

        return ['changed' => true, 'answer' => $record->id, 'over_limit' => $overLimit];
    }

    /** @param list<RequestState> $states */
    private static function stateOf(array $states, string $id): RequestState
    {
        foreach ($states as $state) {
            if ($state->request->id === $id) {
                return $state;
            }
        }

        throw new NotFound();
    }

    /** The award of an aid answer in the event's currency; null for an offer. */
    private function amountOf(Request $request, RegistrationKey $key, bool $yes, mixed $given): ?Money
    {
        if ($request->kind === RequestKind::Offer) {
            if ($given !== null && $given !== '') {
                throw new InvalidInput();
            }

            return null;
        }
        $currency = $this->events->find($key->eventId)?->currency;
        if ($currency === null) {
            throw new NotFound();
        }
        if (!$yes) {
            return Money::zero($currency);
        }
        if (!is_string($given) || preg_match('/^[0-9]{1,18}(\.[0-9]{1,8})?$/', $given) !== 1) {
            throw new InvalidInput();
        }
        $amount = Money::of($given, $currency);
        if (!$amount->isPositive()) {
            throw new InvalidInput();
        }

        return $amount;
    }

    private static function sameAmount(?Money $a, ?Money $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->isEqualTo($b);
    }

    private static function userOf(Context $context): ?int
    {
        $actor = $context->actor();

        return $actor instanceof UserActor || $actor instanceof CliActor ? $actor->userId : null;
    }
}
