<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Catalogue\ActionMailRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Where a use case that performs an action decides its action mail (mail.md, "Five kinds of mail"): inside
 * its own transaction, keyed by the record that caused it. The mail is queued only when the event has it
 * switched on; the event's state is not looked at, since action mails queue while it is unpublished
 * (mail.md, "Pausing"). Switching a mail off later does not drop what was queued.
 * // [UNAPPROVED N18] the trigger interface of the action mails; the mail's key is the action's.
 */
final class ActionMails
{
    public function __construct(
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
        private readonly MailQueue $queue = new MailQueue(),
    ) {
    }

    /**
     * @param Subject $cause the record that caused the mail: the registration, the payment line, the
     *                       confirmation record, …; its event is null or the registration's
     * @return bool whether a delivery was queued: false when the mail is off or was already queued
     * @throws SystemFailure on a cause of another event
     */
    public function due(Context $context, ActionMail $action, RegistrationKey $registration, Subject $cause): bool
    {
        $entry = $this->mails->find(['eventId' => $registration->eventId, 'action' => $action]);
        if ($entry === null || !$entry->enabled) {
            return false;
        }

        return $this->queue->add($context, $registration, $action->value, MailKind::Action, $cause);
    }
}
