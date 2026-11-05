<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Audit;

/** The ID that ties together everything one request or WP-CLI command audits. */
final class Correlation
{
    private static ?string $id = null;

    /** A UUID v4, made on first use and kept for the rest of the request. */
    public static function id(): string
    {
        return self::$id ??= wp_generate_uuid4();
    }
}
