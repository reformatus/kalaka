<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SiteClosed;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Gives or takes a user's full access (S36-1, owner ruling 2026-11-10): the check and the audit run in the
 * use case; the capability, which is WordPress's data, is written after the commit, with a fresh
 * `WP_User`, and is safe to repeat. The last holder keeps it.
 *
 * [UNAPPROVED N4] The capability write is an after-commit step.
 */
final class FullAccessChange
{
    public function __construct(private readonly FullAccessQuery $query = new FullAccessQuery())
    {
    }

    /** @return array<string, mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['user_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * What the use case would refuse, checked before it runs, outside any run: the profile screen asks
     * this before WordPress saves the rest of its form. The use case checks the same again in its run.
     *
     * @throws SiteClosed while the load failed
     * @throws NotFound when the user does not exist
     * @throws RuleViolation when the last holder would lose it
     */
    public function check(int $userId, bool $full): void
    {
        LoadState::current()->requireOpen();
        if (!$this->query->userExists($userId)) {
            throw new NotFound();
        }
        $this->checkLastHolder($userId, $full);
    }

    /**
     * @throws NotFound when the user does not exist
     * @throws RuleViolation when the last holder would lose it
     */
    public function apply(int $userId, bool $full, Context $context): null
    {
        if (!$this->query->userExists($userId)) {
            throw new NotFound();
        }

        $subject = new Subject('user', null, (string) $userId);
        $context->touch($subject);
        $before = $this->query->holds($userId);
        if ($before === $full) {
            return null;
        }
        $this->checkLastHolder($userId, $full);

        $context->change($subject, AdminAccess::FULL_ACCESS, $before, $full);
        $context->afterCommit(static function () use ($userId, $full): void {
            $user = new \WP_User($userId);
            if (!$user->exists()) {
                return;
            }
            if ($full) {
                $user->add_cap(AdminAccess::FULL_ACCESS);
            } else {
                $user->remove_cap(AdminAccess::FULL_ACCESS);
            }
            Access::forget();
        });

        return null;
    }

    /** @throws RuleViolation when taking it would leave nobody with full access */
    private function checkLastHolder(int $userId, bool $full): void
    {
        if (!$full && $this->query->holders() === [$userId]) {
            $error = new RuleViolation(Texts::LAST_HOLDER);
            throw $error;
        }
    }
}
