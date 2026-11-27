<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Foundation\Api\UseCase\PreviewableUseCase;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActRefused;
use Kalaka\Registrations\Api\WithdrawClosed;
use Kalaka\Registrations\RegistrationRepository;

/**
 * The participant's own withdrawal (`kalaka-portal/withdraw-registration`): the registrant leaves the registered
 * or waitlisted registration they hold, from the status page. It is the staff withdrawal's own act
 * (`StatusAct::Withdraw`, `Leaving`): the status, the version and every seat freed, written once; the runner's
 * evaluation follows in the same run (money with the event's retention, then confirmation). The audit actor is the
 * secret-link actor; no reason; no mail is queued (no withdrawal mail kind exists). Nobody on the waitlist moves up.
 * Undo is only staff's restore. [UNAPPROVED N660, N666]
 *
 * - Input: `event_id` and `code` of the registration, and `preview`. The portal's own check (`Portal\Api\Withdrawal`)
 *   has already held the actor to the active registration and refused a status that cannot withdraw
 *   (`WithdrawUnavailable`); a status that changed under the lock is `StatusActRefused`, and a registration
 *   already withdrawn when this runs is `{changed: false}`. From the event's registration close
 *   (`Event::registrationHasClosed()`) only staff can withdraw: the run is refused as `WithdrawClosed`
 *   (`kalaka_withdraw_closed`) and the preview answers `allowed: false`.
 * - Returns `{changed, status}`.
 * - Preview: `{allowed, paid, owed_after}` and nothing written (no audit entry): `allowed` is whether the
 *   registration may withdraw now; `paid` and `owed_after` are the registrant's OWN payer's (`participant`) amount
 *   paid and signed balance once the withdrawal is recorded (above zero owed, below zero overpaid), from the same
 *   composition the staff preview uses, and no other payer's. Both are null when `allowed` is false or the fee
 *   cannot be priced. [UNAPPROVED N662]
 */
final class WithdrawOwnRegistration implements PortalUseCase, PreviewableUseCase
{
    public function __construct(
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly Events $events = new Events(),
        private readonly ?Leaving $leaving = null,
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/withdraw-registration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}$'],
                'preview' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'code'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    public function isPreview(array $input): bool
    {
        return ($input['preview'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input, Context $context): array
    {
        $act = StatusAct::Withdraw;
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        $leaving = $this->leaving ?? new Leaving();
        $stored = $this->registrations->find($key) ?? throw new NotFound();
        $status = $stored->status;
        // N661: the participant may withdraw only until the event's registration closes; after that, only staff can.
        $closed = ($this->events->find($key->eventId) ?? throw new NotFound())
            ->registrationHasClosed($context->now());

        if ($this->isPreview($input)) {
            return $this->preview($act->allowedFrom($status) && !$closed, $leaving, $act, $key, $context);
        }
        if ($status === $act->target()) {
            return ['changed' => false, 'status' => $status->value];
        }
        if (!$act->allowedFrom($status)) {
            throw new StatusActRefused();
        }
        if ($closed) {
            throw new WithdrawClosed();
        }

        $changed = $leaving->leave($act, $key, $context);

        return ['changed' => $changed, 'status' => $act->target()->value];
    }

    /** @return array{allowed: bool, paid: ?string, owed_after: ?string} */
    private function preview(
        bool $allowed,
        Leaving $leaving,
        StatusAct $act,
        RegistrationKey $key,
        Context $context,
    ): array {
        $rows = $allowed ? $leaving->money($act->target(), $key, $context) : null;
        if ($rows === null) {
            return ['allowed' => $allowed, 'paid' => null, 'owed_after' => null];
        }
        // Only the registrant's own payer: another payer's amounts (a sponsor's, a congregation's) are not theirs.
        foreach ($rows as $row) {
            if ($row['payer'] === Payer::PARTICIPANT) {
                return ['allowed' => true, 'paid' => $row['paid'], 'owed_after' => $row['balance_after']];
            }
        }
        $event = $this->events->find($key->eventId) ?? throw new NotFound();
        $zero = Money::zero($event->currency)->amount();

        return ['allowed' => true, 'paid' => $zero, 'owed_after' => $zero];
    }
}
