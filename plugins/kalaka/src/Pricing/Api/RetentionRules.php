<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Pricing\KeepEverythingPaid;
use Kalaka\Pricing\KeepNothing;

/**
 * The retention rules registered now: core's two, then the ones extensions contribute
 * (`Contributions::retentionRule()`, read when asked, so after the boot).
 */
final class RetentionRules
{
    /**
     * @return array<string, RetentionRule> by ID
     * @throws \LogicException when a rule repeats another's ID, core's included, or its ID is malformed
     */
    public static function all(): array
    {
        $rules = [];
        $all = [new KeepEverythingPaid(), new KeepNothing(), ...Contributions::current()->retentionRules()];
        foreach ($all as $rule) {
            $id = $rule->id();
            if (preg_match('/\A[a-z][a-z0-9_]{0,19}\z/', $id) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a rule ID, never output
                throw new \LogicException('A retention rule ID must match [a-z][a-z0-9_]{0,19}: ' . $id . '.');
            }
            if (isset($rules[$id])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a rule ID, never output
                throw new \LogicException('Two retention rules have the ID ' . $id . '.');
            }
            $rules[$id] = $rule;
        }

        return $rules;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    public static function find(string $id): ?RetentionRule
    {
        return self::all()[$id] ?? null;
    }

    /** @throws \LogicException when no registered rule has the ID (an extension that gave it was deactivated) */
    public static function get(string $id): RetentionRule
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a rule ID, never output
        return self::find($id) ?? throw new \LogicException('No retention rule is registered with the ID ' . $id . '.');
    }
}
