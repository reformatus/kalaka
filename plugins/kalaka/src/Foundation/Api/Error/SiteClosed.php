<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

/**
 * The load failed, so the site is closed (`LoadState`): every public entry point and every use case
 * is refused with this. A 503 with `SystemFailure`'s text and no `Hibaazonosító` line: the failure is
 * the site's, logged when it starts or changes, not the person's request. foundation.md, "Errors".
 */
final class SiteClosed extends SystemFailure
{
    public function errorCode(): string
    {
        return 'kalaka_site_closed';
    }

    public function status(): int
    {
        return 503;
    }

    public function displayMessage(): string
    {
        return $this->getMessage();
    }
}
