<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActRefused;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;

/**
 * The one lock-and-recheck of every status act: admit, restore, the staff withdraw and remove, and the participant's
 * own withdrawal. It locks the registration's row and checks the status again under it, so a repeat that lost the
 * race writes nothing and a status that moved in between is refused. [UNAPPROVED N510, N512]
 *
 * @internal Registrations' status acts only
 */
final class StatusLock
{
    public function __construct(private readonly RegistrationRepository $registrations = new RegistrationRepository())
    {
    }

    /**
     * Null when the registration is already in the act's target state (a repeat that lost the race), else the
     * locked row.
     *
     * @throws NotFound
     * @throws StatusActRefused the status under the lock does not allow the act
     */
    public function locked(StatusAct $act, RegistrationKey $key, Context $context): ?Registration
    {
        $registration = $this->registrations->findForUpdate($key, $context) ?? throw new NotFound();
        if ($act->isRepeatFrom($registration->status)) {
            return null;
        }
        if (!$act->allowedFrom($registration->status)) {
            throw new StatusActRefused();
        }

        return $registration;
    }
}
