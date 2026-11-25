<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** What a paid or refunded report must hold, checked once for both. */
final class OnlineRefs
{
    /** The one source of the report's ref patterns, without delimiters; the schemas and the checks build on them. */
    public const PROVIDER = '[a-z][a-z0-9_]{0,31}';

    public const REF = '[a-z0-9_.-]{1,40}';

    public const PROVIDER_PATTERN = '/\A' . self::PROVIDER . '\z/';

    public const REF_PATTERN = '/\A' . self::REF . '\z/';

    /** The patterns as a JSON schema takes them. */
    public const PROVIDER_SCHEMA = '^' . self::PROVIDER . '\\z';

    public const REF_SCHEMA = '^' . self::REF . '\\z';

    /** An instant as `Instant::toStorage()` writes it (UTC). */
    public const INSTANT_SCHEMA = '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\\.[0-9]{6}\\z';

    private function __construct()
    {
    }

    /**
     * @param list<OnlineItem> $items
     * @throws \InvalidArgumentException
     */
    public static function check(string $providerId, string $requestRef, array $items): void
    {
        if (preg_match(self::PROVIDER_PATTERN, $providerId) !== 1 || $items === []) {
            throw new \InvalidArgumentException('A report has a provider ID and at least one item.');
        }
        self::checkRef($requestRef);
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item->itemRef])) {
                throw new \InvalidArgumentException('A report names each item ref once.');
            }
            $seen[$item->itemRef] = true;
        }
    }

    /** @throws \InvalidArgumentException */
    public static function checkRef(string $ref): void
    {
        if (preg_match(self::REF_PATTERN, $ref) !== 1) {
            throw new \InvalidArgumentException('A request or refund ref is 1 to 40 of [a-z0-9_.-].');
        }
    }
}
