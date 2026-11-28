<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\AwaitingRule;
use Kalaka\Registrations\Api\FieldTest;
use Kalaka\Registrations\Api\FieldTestKind;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\RequestTerms;
use Kalaka\Registrations\Api\SeatPool;

/**
 * A test source of requests, standing for the offers and aid of slice B0: the field `support` holding `church`
 * is the offer `offer:church`; the field `aid_asked`, filled, is the aid request `aid`. The answers go
 * to `support_answer` (`approved` or `refused`) and `aid_award` (the amount), the source's own fields.
 */
final class TestRequestSource implements RequestSource
{
    public const OFFER = 'offer:church';

    public const AID = 'aid';

    /** The seat pool of the church offer; the aid request has none. */
    public const POOL = 'church';

    /** The module slug the test source writes under (`Registrations::writeValue()`'s declarer). */
    public const DECLARER = 'kalaka-test';

    /**
     * @param ?int $fullPrice the aid request's full price in forints, a stand-in for the aid extension's composed
     *                        price; null gives no terms
     */
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly ?int $fullPrice = null,
        private readonly ?SeatPool $pool = null,
    ) {
    }

    public function requestsOf(RegistrationKey $registration, array $values): array
    {
        $requests = [];
        if (($values['support'] ?? '') === 'church') {
            $requests[] = new Request(self::OFFER, RequestKind::Offer, 'Egyházi támogatás');
        }
        if (($values['aid_asked'] ?? '') !== '') {
            $requests[] = new Request(self::AID, RequestKind::Aid, 'Támogatási kérelem');
        }

        return $requests;
    }

    public function isAnswered(Request $request, array $values): bool
    {
        return $request->kind === RequestKind::Offer
            ? in_array($values['support_answer'] ?? '', ['approved', 'refused'], true)
            : ($values['aid_award'] ?? '') !== '';
    }

    /**
     * The church offer: `support` holds `church` and `support_answer` is neither `approved` nor `refused`; the aid
     * request: `aid_asked` is filled and `aid_award` is empty. Exactly `requestsOf()` and `isAnswered()`.
     */
    public function awaitingRule(int $eventId): ?AwaitingRule
    {
        return new AwaitingRule([
            [
                new FieldTest('support', FieldTestKind::OneOf, ['church']),
                new FieldTest('support_answer', FieldTestKind::NoneOf, ['approved', 'refused']),
            ],
            [
                new FieldTest('aid_asked', FieldTestKind::Filled),
                new FieldTest('aid_award', FieldTestKind::Empty),
            ],
        ]);
    }

    public function seatPool(Request $request): ?SeatPool
    {
        return $request->kind === RequestKind::Offer ? $this->pool : null;
    }

    public function terms(
        RegistrationKey $registration,
        Request $request,
        array $values,
        \DateTimeImmutable $now,
    ): ?RequestTerms {
        if ($request->kind !== RequestKind::Aid || $this->fullPrice === null) {
            return null;
        }
        $canPay = ($values['aid_can_pay'] ?? '') === '' ? null : Money::of($values['aid_can_pay'], Currency::HUF);

        return new RequestTerms(Money::of($this->fullPrice, Currency::HUF), $canPay);
    }

    public function writeAnswer(
        RegistrationKey $registration,
        Request $request,
        Answer $answer,
        Context $context,
    ): void {
        if ($request->kind === RequestKind::Offer) {
            $this->registrations->writeValue(
                $registration,
                'support_answer',
                $answer->approved ? 'approved' : 'refused',
                $context,
                self::DECLARER
            );

            return;
        }
        $this->registrations->writeValue(
            $registration,
            'aid_award',
            (string) $answer->amount?->amount(),
            $context,
            self::DECLARER
        );
    }
}
