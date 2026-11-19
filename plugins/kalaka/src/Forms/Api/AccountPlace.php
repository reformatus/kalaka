<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Where a form's account block (`SignUp::BLOCK`, owner ruling 2026-11-18) sits: its page and the branches
 * around it, like a field's (`FormField`). In the event's `mandatory` mode it validates like a required
 * field on its page: while it shows, someone not logged in cannot move past that page.
 */
final readonly class AccountPlace
{
    /**
     * @param int|null $page the number of the page it sits on; null in a form without pages
     * @param list<FormBranch> $branches the branches it sits in, outermost first; none outside any
     */
    public function __construct(
        public ?int $page = null,
        public array $branches = [],
    ) {
    }
}
