<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Portal\Response;

/** A response that records what the handler sent. */
class RecordingResponse implements Response
{
    /** @var array<string, array{string, int}> name → [value, expires], the last one sent */
    public array $cookies = [];

    public int $noCache = 0;

    public ?string $redirect = null;

    public int $redirectStatus = 0;

    public function setCookie(string $name, string $value, int $expires): void
    {
        $this->cookies[$name] = [$value, $expires];
    }

    public function noCache(): void
    {
        $this->noCache++;
    }

    public function redirect(string $url, int $status = 302): void
    {
        $this->redirect = $url;
        $this->redirectStatus = $status;
    }
}
