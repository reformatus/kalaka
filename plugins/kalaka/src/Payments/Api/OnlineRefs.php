<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** What a paid or refunded report must hold, checked once for both. */
final class OnlineRefs
{
    public const PROVIDER_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

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
    }

    /** @throws \InvalidArgumentException */
    public static function checkRef(string $ref): void
    {
        if (preg_match('/\A[a-z0-9_.-]{1,40}\z/', $ref) !== 1) {
            throw new \InvalidArgumentException('A request or refund ref is 1 to 40 of [a-z0-9_.-].');
        }
    }
}
