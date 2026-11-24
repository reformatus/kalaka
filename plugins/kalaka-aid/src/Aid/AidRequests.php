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
 * registration holds a value in `aid_can_pay`, the amount the participant states they can pay, and the event has
 * aid (Q-B0-7: any stored value; staff answer 0 when nothing is needed). The one answer act writes the answer
 * through `writeAnswer()`, which writes the award `aid_award`: 0 for a "no", the amount for a "yes". This source
 * is the award's only writer. It refuses nothing about the amount's size: the component caps it (Q-B0-8). It
 * reads only the registration's field values and the event's settings. [UNAPPROVED N105, N106, N107]
 */
final class AidRequests implements RequestSource
{
    public const ID = 'aid';

    /** The participant field the participant states what they can pay in, form-writable. [UNAPPROVED N105] */
    public const CAN_PAY_FIELD = 'aid_can_pay';

    public function __construct(
        private readonly AidSettingsRepository $settings = new AidSettingsRepository(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    public function requestsOf(RegistrationKey $registration, array $values): array
    {
        if (($values[self::CAN_PAY_FIELD] ?? '') === '') {
            return [];
        }
        $settings = $this->settings->find($registration->eventId);

        return $settings === null ? [] : [new Request(self::ID, RequestKind::Aid, $settings->label)];
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
        $this->registrations->writeValue(
            $registration,
            AidComponent::AWARD_FIELD,
            $answer->approved ? PlainAmount::of($answer->amount) : '0',
            $context
        );
    }
}
