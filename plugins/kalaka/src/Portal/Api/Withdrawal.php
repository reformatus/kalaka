<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Registrations\Api\OwnWithdrawal;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActs;

/**
 * The participant's own withdrawal from the status page (`kalaka-portal/withdraw-registration`), held to the
 * active registration: it acts only for the registration the visitor holds (a secret link and the signed cookie),
 * and the `code` sent must be that registration's own. It reads and decides nothing else: whether the status
 * allows the act is `StatusAct::Withdraw`'s table (through `StatusActs`), the act itself Registrations'. A refusal
 * here is `WithdrawUnavailable`. [UNAPPROVED N660, N661, N664]
 *
 * Allowed from `registered` (confirmed or not) and `waitlisted`; no deadline; undo is only staff's restore.
 * [UNAPPROVED N661]
 */
final class Withdrawal
{
    public function __construct(
        private readonly OwnWithdrawal $act = new OwnWithdrawal(),
        private readonly StatusActs $acts = new StatusActs(),
    ) {
    }

    /**
     * @param RegistrationKey|null $active the active registration (`ActiveRegistration::current()`), null for none
     * @param string $code the reference code the page sent
     * @param bool $preview true: `{allowed, paid, owed_after}` for the registrant's own payer, nothing written
     * @return array<string, mixed> `{changed, status}`, or the preview
     * @throws WithdrawUnavailable no active registration, another registration's code, or a status that cannot
     *     withdraw (a real withdrawal only: the preview answers `allowed: false` for it)
     * @throws \Kalaka\Foundation\Api\Error\KalakaException as the use case throws it
     */
    public function run(?RegistrationKey $active, string $code, bool $preview = false): array
    {
        if ($active === null || $code !== $active->code) {
            throw new WithdrawUnavailable();
        }
        if (!$preview && !in_array(StatusAct::Withdraw, $this->acts->allowedFor($active), true)) {
            throw new WithdrawUnavailable();
        }

        return $this->act->run($active, $preview);
    }
}
