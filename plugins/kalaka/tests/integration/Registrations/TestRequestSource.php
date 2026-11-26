<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\SeatPool;

/**
 * A test source of requests, standing for the offers and aid of slice B0: the field `support` holding `church`
 * is the offer `offer:church`; the field `aid_asked`, a positive amount, is the aid request `aid`. The answers go
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

    public function __construct(private readonly Registrations $registrations = new Registrations())
    {
    }

    public function requestsOf(RegistrationKey $registration, array $values): array
    {
        $requests = [];
        if (($values['support'] ?? '') === 'church') {
            $requests[] = new Request(
                self::OFFER,
                RequestKind::Offer,
                'Egyházi támogatás',
                ['support'],
                'support_answer',
            );
        }
        if ((float) ($values['aid_asked'] ?? '0') > 0) {
            $requests[] = new Request(self::AID, RequestKind::Aid, 'Támogatási kérelem', ['aid_asked'], 'aid_award');
        }

        return $requests;
    }

    public function isAnswered(Request $request, array $values): bool
    {
        return $request->kind === RequestKind::Offer
            ? in_array($values['support_answer'] ?? '', ['approved', 'refused'], true)
            : ($values['aid_award'] ?? '') !== '';
    }

    public function seatPool(RegistrationKey $registration, Request $request): ?SeatPool
    {
        if ($request->kind !== RequestKind::Offer) {
            return null;
        }

        return new class ($registration->eventId) implements SeatPool {
            public function __construct(private readonly int $event)
            {
            }

            public function eventId(): int
            {
                return $this->event;
            }

            public function key(): string
            {
                return TestRequestSource::POOL;
            }

            public function lockLevel(): LockLevel
            {
                return LockLevel::Other;
            }

            public function lockRow(): array
            {
                return ['kalaka_test_pool', [TestRequestSource::POOL]];
            }

            public function limit(): ?int
            {
                return null;
            }

            public function lock(Context $context): ?int
            {
                return null;
            }
        };
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
