<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A grant holder of the event asked for an existing registration their views do not hold: "no access",
 * distinct from "not found", which an unknown code and a user with no grant on the event get. It shows the
 * permission refusal's text, so no new text. [UNAPPROVED Shapes]
 */
final class RegistrationNotInView extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Ehhez nincs jogosultságod.';
    }

    public function errorCode(): string
    {
        return 'kalaka_registration_not_in_view';
    }

    public function status(): int
    {
        return 403;
    }
}
