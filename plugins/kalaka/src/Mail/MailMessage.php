<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/** One composed mail: what `wp_mail` is called with, besides the address, and the version of the body. */
final readonly class MailMessage
{
    /**
     * @param string $body HTML
     * @param list<string> $headers
     * @param string $version at most 64 characters
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $subject,
        public string $body,
        public array $headers,
        public string $version,
    ) {
        if (mb_strlen($version, 'UTF-8') > 64) {
            throw new \InvalidArgumentException('A mail version is at most 64 characters.');
        }
    }
}
