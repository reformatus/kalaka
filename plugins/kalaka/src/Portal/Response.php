<?php

declare(strict_types=1);

namespace Kalaka\Portal;

/** What the active-registration handler sends back: cookies, no-cache headers, a redirect. */
interface Response
{
    /** A cookie: a session cookie when `$expires` is 0, deleted when it is negative, else until `$expires`. */
    public function setCookie(string $name, string $value, int $expires): void;

    /** The page is never cached. */
    public function noCache(): void;

    /** A redirect (302 unless `$status` says otherwise) to the URL; the request ends here. */
    public function redirect(string $url, int $status = 302): void;
}
