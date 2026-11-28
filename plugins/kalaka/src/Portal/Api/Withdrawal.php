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
 * here is `WithdrawBadNonce` (a missing or stale form nonce), `WithdrawStale` (no active registration, another
 * code) or `WithdrawUnavailable` (status). [UNAPPROVED N660, N664, N1166]
 *
 * A real withdrawal must carry the nonce of its own page (`nonceField()`, verified by `nonceIsValid()`): the
 * participant's identity is the cookie, not a WordPress user, and WordPress's nonce of a logged-out visitor is one
 * value shared by every guest, so the nonce is an HMAC of the action, the time tick and the registration's own
 * code under `wp_salt('nonce')`. It lives two nonce ticks, like `wp_verify_nonce()`'s (12 to 24 hours by default),
 * and one registration's nonce never opens another's withdrawal. A preview writes nothing and needs none.
 *
 * Allowed from `registered` (confirmed or not) and `waitlisted`, until the event's registration closes (the use case
 * refuses it after as `WithdrawClosed`); undo is only staff's restore.
 */
final class Withdrawal
{
    /** The form field that carries the nonce. */
    public const NONCE_FIELD = 'kalaka_withdraw_nonce';

    /** The nonce's action, mixed into its HMAC like a WordPress nonce's action. */
    public const NONCE_ACTION = 'kalaka-portal/withdraw-registration';

    public function __construct(
        private readonly OwnWithdrawal $act = new OwnWithdrawal(),
        private readonly StatusActs $acts = new StatusActs(),
    ) {
    }

    /**
     * The page's hidden nonce field, for the form that posts `code`: `nonceField($code)`.
     *
     * @param string $code the reference code the form posts
     */
    public static function nonceField(string $code): string
    {
        return '<input type="hidden" name="' . self::NONCE_FIELD . '" value="'
            . esc_attr(self::nonceValue($code)) . '">';
    }

    /**
     * The nonce of this registration's withdrawal, as the page prints it and a script sends it.
     *
     * @param string $code the reference code the form posts
     */
    public static function nonceValue(string $code): string
    {
        return self::nonceFor($code, (int) wp_nonce_tick(self::NONCE_ACTION));
    }

    /**
     * Whether the nonce is this code's nonce, now or in the tick before (so it lives 12 to 24 hours by default,
     * like `wp_verify_nonce()`'s).
     *
     * @param string $nonce the nonce the request carried; an empty one (none) never matches
     * @param string $code the reference code the request carried
     */
    public static function nonceIsValid(string $nonce, string $code): bool
    {
        if ($nonce === '' || $code === '') {
            return false;
        }
        $tick = (int) wp_nonce_tick(self::NONCE_ACTION);
        foreach ([$tick, $tick - 1] as $candidate) {
            if (hash_equals(self::nonceFor($code, $candidate), $nonce)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nonce as WordPress's: ten characters of an HMAC of the tick, the action and the code under the `nonce`
     * salt. Nothing about a visitor is in it: the code is what binds it, so it is the same for a guest as for an
     * account, and only that one registration's page produced it.
     */
    private static function nonceFor(string $code, int $tick): string
    {
        return substr(wp_hash($tick . '|' . self::NONCE_ACTION . '|' . $code, 'nonce'), -12, 10);
    }

    /**
     * @param RegistrationKey|null $active the active registration (`ActiveRegistration::current()`), null for none
     * @param string $code the reference code the page sent
     * @param bool $preview true: `{allowed, paid, owed_after, retained, surplus}` for the registrant's own payer, nothing
     *     written
     * @param string|null $nonce the form's nonce (`nonceValue()`), required for a real withdrawal, ignored in a
     *     preview
     * @return array<string, mixed> `{changed, status}`, or the preview
     * @throws WithdrawBadNonce a real withdrawal without its page's nonce (missing, wrong, or older than its window)
     * @throws WithdrawStale no active registration, or another registration's code (the preview too)
     * @throws WithdrawUnavailable a status that cannot withdraw (a real withdrawal only: the preview answers
     *     `allowed: false` for it)
     * @throws \Kalaka\Foundation\Api\Error\KalakaException as the use case throws it
     */
    public function run(
        ?RegistrationKey $active,
        string $code,
        bool $preview = false,
        ?string $nonce = null
    ): array {
        if (!$preview && !self::nonceIsValid($nonce ?? '', $code)) {
            throw new WithdrawBadNonce();
        }
        if ($active === null || $code !== $active->code) {
            throw new WithdrawStale();
        }
        if (!$preview && !in_array(StatusAct::Withdraw, $this->acts->allowedFor($active), true)) {
            throw new WithdrawUnavailable();
        }

        return $this->act->run($active, $preview);
    }
}
