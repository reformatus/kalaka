<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\View\ViewShape;

/**
 * A view the actor holds on one event: through a grant (with its id), or the master view through full
 * access. Only `Access` makes one, after the access check, and `ViewQuery` reads only through one, so
 * registration data is reached only through a held view or full access (foundation.md, "Views and grants").
 *
 * [UNAPPROVED S36-7]
 */
final readonly class HeldView
{
    /**
     * @internal Made by `Access` only.
     *
     * @param ?ViewShape $shape null: the master view, every column of the event and no filter
     */
    public function __construct(
        public int $eventId,
        public ?int $grantId,
        public ?int $viewId,
        public ?string $name,
        private ?ViewShape $shape,
        private ?string $bound,
    ) {
    }

    /** Whether this is the event's master view (every column, no filter). */
    public function isMaster(): bool
    {
        return $this->shape === null;
    }

    /** @internal For `ViewQuery`. */
    public function shape(): ?ViewShape
    {
        return $this->shape;
    }

    /** @internal For `ViewQuery`. */
    public function bound(): ?string
    {
        return $this->bound;
    }
}
