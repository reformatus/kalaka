<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A grant holder of the event asked for a registration their views do not hold (or that does not exist): "no
 * access", distinct from "not found", which a user with no grant on the event still gets. It shows the
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
