<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

/**
 * Keeps a form's prefill across the login of the account step (S15-5, [UNAPPROVED]): the quiz POSTs its
 * prefill to the form's page, and WordPress's login page would lose it. The prefill is kept on the server
 * under a random token for `LIFETIME` seconds, and only the token travels through `redirect_to`
 * (`PARAMETER`), so nothing personal goes into a URL. Taking it deletes it: a token is good once.
 *
 * The token is 128 random bits, URL-safe (22 characters), never written into a log line; it is stored only
 * as its SHA-256, in a WordPress transient named after the hash. The kept prefill carries its form and the
 * instant it was kept, checked against the runner's clock, so it expires on the clock the rest of Kaláka
 * runs on; the transient's own expiry only clears what is never taken.
 *
 * Nothing calls it before S73 builds the prefill read (TODO(S73) in `FormPage`). One class, so the handoff can
 * be swapped for another without touching the form page.
 */
final class PrefillHandoff
{
    /** The query parameter of the return address. */
    public const PARAMETER = 'kalaka_handoff';

    /** About 30 minutes. */
    public const LIFETIME = 1800;

    private const PREFIX = 'kalaka_handoff_';

    private const TOKEN = '/\A[A-Za-z0-9_-]{22}\z/D';

    /**
     * Keeps the prefill of the form and gives the token that takes it back.
     *
     * @param array<string, string> $prefill field key → value
     */
    public function keep(int $formId, array $prefill, \DateTimeImmutable $now): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        set_transient(self::name($token), [
            'form' => $formId,
            'kept' => $now->getTimestamp(),
            'prefill' => $prefill,
        ], self::LIFETIME + 60);

        return $token;
    }

    /**
     * The prefill kept under the token for the form, deleted as it is taken; null when there is none: an
     * unknown or malformed token, one already taken, one kept for another form, or one older than `LIFETIME`.
     *
     * @return array<string, string>|null
     */
    public function take(int $formId, string $token, \DateTimeImmutable $now): ?array
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }
        $name = self::name($token);
        $kept = get_transient($name);
        if ($kept === false) {
            return null;
        }
        delete_transient($name);
        if (
            !is_array($kept)
            || ($kept['form'] ?? null) !== $formId
            || !is_int($kept['kept'] ?? null)
            || !is_array($kept['prefill'] ?? null)
        ) {
            return null;
        }
        $age = $now->getTimestamp() - $kept['kept'];
        if ($age >= self::LIFETIME || $age < 0) {
            return null;
        }
        $prefill = [];
        foreach ($kept['prefill'] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $prefill[$key] = $value;
            }
        }

        return $prefill;
    }

    private static function name(string $token): string
    {
        return self::PREFIX . substr(hash('sha256', $token), 0, 40);
    }
}
