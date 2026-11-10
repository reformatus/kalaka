<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Database;

use Kalaka\Foundation\Database\Statements;

/**
 * A MariaDB named lock (`GET_LOCK`) for work that must not overlap with itself, such as the mail sender.
 * It belongs to the connection, so a crash frees it, and it is taken without waiting. Named locks are
 * server-wide, so the name is scoped to this site's database and table prefix: two WordPress installs on
 * one MariaDB server never share it. Not for use inside a use case: it is not a row lock and knows no
 * lock order.
 */
final class NamedLock
{
    /** MariaDB's limit on a lock name. */
    private const MAX_LENGTH = 64;

    private readonly Statements $statements;

    private bool $held = false;

    /**
     * // [UNAPPROVED S8-7] a Foundation Api class over GET_LOCK, scoped to the site.
     *
     * @param string $name `[a-z][a-z0-9_]*`, at most 40 characters
     * @throws \InvalidArgumentException
     */
    public function __construct(private readonly string $name)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || strlen($name) > 40) {
            throw new \InvalidArgumentException('A lock name is [a-z][a-z0-9_]*, at most 40 characters.');
        }
        $this->statements = new Statements();
    }

    /** @return bool whether this request holds the lock now; false when another connection holds it */
    public function acquire(): bool
    {
        $this->held = $this->statements->getLock($this->scoped());

        return $this->held;
    }

    /** Frees the lock if this request took it. */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        $this->statements->releaseLock($this->scoped());
    }

    /** `kalaka_<12 hex of the database and prefix>_<name>`, within MariaDB's 64 characters. */
    public function scoped(): string
    {
        $database = defined('DB_NAME') ? (string) DB_NAME : '';
        $site = substr(md5($database . '|' . $this->statements->prefixed('')), 0, 12);
        $scoped = 'kalaka_' . $site . '_' . $this->name;

        return substr($scoped, 0, self::MAX_LENGTH);
    }
}
