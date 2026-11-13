<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

/**
 * An event's layout: the raw HTML that wraps its mails, with exactly one content placeholder
 * (mail.md, "Configuration" and "Content"). Until one is set, it is exactly `{{content}}`.
 */
final class MailLayout
{
    /** The one placeholder of a layout: where the mail's rendered body goes. */
    public const CONTENT = '{{content}}';

    public function __construct(
        public readonly int $eventId,
        public string $html,
        public int $version = 0,
    ) {
    }

    /** Whether the HTML holds the placeholder exactly once. */
    public static function isValid(string $html): bool
    {
        return substr_count($html, self::CONTENT) === 1;
    }

    /** The layout with the rendered body in the placeholder's place, the rest as written. */
    public function wrap(string $body): string
    {
        return str_replace(self::CONTENT, $body, $this->html);
    }
}
