<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Actor;

final readonly class CliActor implements Actor
{
    public function __construct(public ?int $userId)
    {
    }

    /** The command line as WP-CLI's `--user` has it: that user, or no user. */
    public static function current(): self
    {
        $id = get_current_user_id();

        return new self($id > 0 ? $id : null);
    }

    public function side(): Side
    {
        return Side::Admin;
    }

    public function type(): string
    {
        return 'cli';
    }
}
