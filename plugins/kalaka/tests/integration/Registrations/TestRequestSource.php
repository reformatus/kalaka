<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\RequestSource;

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

    public function __construct(private readonly Registrations $registrations = new Registrations())
    {
    }

    public function requestsOf(RegistrationKey $registration, array $values): array
    {
        $requests = [];
        if (($values['support'] ?? '') === 'church') {
            $requests[] = new Request(self::OFFER, RequestKind::Offer, 'Egyházi támogatás');
        }
        if ((float) ($values['aid_asked'] ?? '0') > 0) {
            $requests[] = new Request(self::AID, RequestKind::Aid, 'Támogatási kérelem');
        }

        return $requests;
    }

    public function seatPool(Request $request): ?string
    {
        return $request->kind === RequestKind::Offer ? self::POOL : null;
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
                $context
            );

            return;
        }
        $this->registrations->writeValue($registration, 'aid_award', (string) $answer->amount?->amount(), $context);
    }
}
