<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\SeatPool;

/**
 * The aid extension's request source (money.md, "Core money scope"): an aid request, `aid`, exists while the
 * registration holds a value in `aid_can_pay`, the amount the participant states they can pay, or in `aid_award`, and
 * the event has aid (Q-B0-7: any stored value; staff answer 0 when nothing is needed). The one answer act writes the
 * answer through `writeAnswer()`, which writes the award `aid_award`: 0 for a "no", the amount for a "yes". This
 * source is the award's only writer. It refuses nothing about the amount's size but what the field cannot hold: the
 * component caps it (Q-B0-8). It reads only the registration's field values and the event's settings. If staff empty
 * `aid_can_pay` after an answer, the request stays because the award holds a value, so staff can still change the
 * award through the answer act (N124); with both fields empty there is no request.
 * [UNAPPROVED N115, N116, N117, N124, N363]
 */
final class AidRequests implements RequestSource
{
    public const ID = 'aid';

    /** The participant field the participant states what they can pay in, form-writable. [UNAPPROVED N115] */
    public const CAN_PAY_FIELD = 'aid_can_pay';

    /** What the number field `aid_award` can hold: core's number field takes at most this many integer digits. */
    public const MAX_INTEGER_DIGITS = 12;

    public function __construct(
        private readonly AidSettingsRepository $settings = new AidSettingsRepository(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    public function requestsOf(RegistrationKey $registration, array $values): array
    {
        if (($values[self::CAN_PAY_FIELD] ?? '') === '' && ($values[AidComponent::AWARD_FIELD] ?? '') === '') {
            return [];
        }
        $settings = $this->settings->find($registration->eventId);

        return $settings === null ? [] : [new Request(self::ID, RequestKind::Aid, $settings->label)];
    }

    /** The award is the truth: empty is awaiting, `0` is a refusal, an amount is granted. [UNAPPROVED N363] */
    public function isAnswered(Request $request, array $values): bool
    {
        return ($values[AidComponent::AWARD_FIELD] ?? '') !== '';
    }

    public function seatPool(RegistrationKey $registration, Request $request): ?SeatPool
    {
        return null;
    }

    public function writeAnswer(
        RegistrationKey $registration,
        Request $request,
        Answer $answer,
        Context $context,
    ): void {
        if ($answer->amount === null || ($answer->approved && !$answer->amount->isPositive())) {
            throw new InvalidInput();
        }
        $award = $answer->approved ? PlainAmount::of($answer->amount) : '0';
        // An award the number field cannot hold would be stored but read as no award: refuse it here.
        if (strlen(explode('.', $award)[0]) > self::MAX_INTEGER_DIGITS) {
            throw new InvalidInput();
        }
        $this->registrations->writeValue(
            $registration,
            AidComponent::AWARD_FIELD,
            $award,
            $context,
            SetAid::DECLARER
        );
    }
}
