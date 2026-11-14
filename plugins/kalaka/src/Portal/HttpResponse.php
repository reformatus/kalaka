<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\FormPage;

/** The response of a real request. */
final class HttpResponse implements Response
{
    /** `HttpOnly; SameSite=Lax; Path=/`, `Secure` on https, no `Domain` (SB-5). */
    public function setCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $expires < 0 ? 1 : $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** The one no-cache of Kaláka's pages: `DONOTCACHEPAGE`, and the headers while none are sent. */
    public function noCache(): void
    {
        FormPage::preventCaching();
    }

    public function redirect(string $url): void
    {
        wp_safe_redirect($url, 302);
        exit;
    }
}
