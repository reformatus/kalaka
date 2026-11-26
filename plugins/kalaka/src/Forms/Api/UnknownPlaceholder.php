<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A document saved with a placeholder typed as `{{key}}` whose key its context does not declare (owner 2026-11-26,
 * N960 option (a)): the save is refused with `kalaka_unknown_placeholder`, 422, and the keys as typed in the data.
 */
final class UnknownPlaceholder extends RuleViolation
{
    /** [UNAPPROVED T990] */
    public const MESSAGE = 'Ismeretlen jelölő: %s. A mentéshez javítani vagy törölni kell.';

    /** @param non-empty-list<string> $keys the unknown placeholders exactly as typed, e.g. `{{foo}}` */
    public function __construct(public readonly array $keys)
    {
        parent::__construct(sprintf(self::MESSAGE, implode(', ', $keys)));
    }

    public function errorCode(): string
    {
        return 'kalaka_unknown_placeholder';
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error(
            $this->errorCode(),
            $this->publicMessage(),
            ['status' => $this->status(), 'correlation' => Correlation::id(), 'keys' => $this->keys]
        );
    }
}
