<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\Api\RecordedLine;
use Kalaka\Mail\Catalogue\ActionMailRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Where a use case that performs an action decides its action mail (mail.md, "Five kinds of mail"): inside
 * its own transaction, keyed by the record that caused it. The mail is queued only when the event has it
 * switched on; the event's state is not looked at, since action mails queue while it is unpublished
 * (mail.md, "Pausing"). Switching a mail off later does not drop what was queued.
 */
final class ActionMails
{
    /** The cause type of the "payment recorded" mail: the ledger line, whose ID is the cause's key. */
    public const LINE_CAUSE = 'ledger_line';

    public function __construct(
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
        private readonly MailQueue $queue = new MailQueue(),
    ) {
    }

    /** Whether the event has the action mail switched on: what `due()` would queue now. */
    public function isOn(int $eventId, ActionMail $action): bool
    {
        $entry = $this->mails->find(['eventId' => $eventId, 'action' => $action]);

        return $entry !== null && $entry->enabled;
    }

    /**
     * @param Subject $cause the record that caused the mail: the registration, the payment line, the
     *                       confirmation record, …; its event is null or the registration's
     * @return bool whether a delivery was queued: false when the mail is off or was already queued
     * @throws SystemFailure on a cause of another event
     */
    public function due(Context $context, ActionMail $action, RegistrationKey $registration, Subject $cause): bool
    {
        if (!$this->isOn($registration->eventId, $action)) {
            return false;
        }

        return $this->queue->add($context, $registration, $action->value, MailKind::Action, $cause);
    }

    /**
     * The "payment recorded" mail (mail.md, "Five kinds of mail"), decided by the use case that recorded the
     * line, keyed by the line: money in and money out, by hand or online (a payment or a refund). A correction, a
     * charge (a „Díj” or a „Kedvezmény” is a fact and its charge line), a replay of a line already written and a
     * line of a payer other than the participant send none: the registrant sees only the participant's side
     * (Portal), and the mail's table would not list that line. [UNAPPROVED N16] The one rule of which lines
     * mail, for every act that records a payment fact. Queued only when
     * the event has the mail switched on.
     *
     * @return bool whether a delivery was queued
     */
    public function lineRecorded(Context $context, RecordedLine $recorded): bool
    {
        $line = $recorded->line;
        if (!$recorded->inserted || !Payer::isParticipantMovement($line->kind, $line->payer, $line->correction)) {
            return false;
        }

        return $this->due(
            $context,
            ActionMail::PaymentRecorded,
            $line->registration,
            new Subject(self::LINE_CAUSE, $line->registration->eventId, (string) $line->id)
        );
    }
}
