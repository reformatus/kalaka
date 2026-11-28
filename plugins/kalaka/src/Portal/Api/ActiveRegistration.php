<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The active registration (registrations-and-identity.md, "Access"): the one registration the site acts
 * for on the participant side, set by a secret link and remembered in a signed cookie. Resolved once per
 * request at `init`; never "logged in".
 */
final class ActiveRegistration
{
    /** What a page says when this request follows a secret link that did not match. */
    public const BAD_LINK_TEXT = 'Ez a link nem érvényes. Nyisd meg a legutóbb kapott e-mailben lévő linket.';

    /** The active registration's key, or null when there is none. */
    public static function current(): ?RegistrationKey
    {
        return ActiveRegistrationState::active();
    }

    /** Whether this request follows a secret link that did not match (shown once). */
    public static function followsBadLink(): bool
    {
        return ActiveRegistrationState::badLink();
    }
}
