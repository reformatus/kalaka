<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Registrations\Api\RegistrantCookie;

/** The response of a real request. */
final class HttpResponse implements Response
{
    /** The registrant cookie policy, shared with the guest draft's claim (`RegistrantCookie`, SB-5). */
    public function setCookie(string $name, string $value, int $expires): void
    {
        RegistrantCookie::set($name, $value, $expires);
    }

    /** The one no-cache of Kaláka's pages: `DONOTCACHEPAGE`, and the headers while none are sent. */
    public function noCache(): void
    {
        FormCaching::preventCaching();
    }

    public function redirect(string $url): void
    {
        wp_safe_redirect($url, 302);
        exit;
    }
}
