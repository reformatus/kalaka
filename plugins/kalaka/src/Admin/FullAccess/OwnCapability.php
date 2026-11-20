<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Admin\Api\AdminAccess;

/**
 * Full access is the user's own capability, never a role's (foundation.md, "Views and grants"). WordPress
 * merges a role's capabilities into the user's before it asks the `user_has_cap` filter, so without this
 * filter a role editor plugin could give full access to every member of a role silently. `FullAccessQuery`
 * reads the same rule from `usermeta`. A role named exactly `kalaka_full_access` puts that key into each
 * member's `caps`, so both `holders()` and this filter count its members as holders: that is consistent
 * and not a bypass, but it is a way round "never a role".
 */
final class OwnCapability
{
    /**
     * `user_has_cap`: keeps `kalaka_full_access` only when the user has it in their own capabilities, not
     * through a role. On multisite a super admin passes every check before this filter, as documented.
     *
     * @param array<string, mixed> $allcaps
     * @param list<string> $caps
     * @param array<int, mixed> $args
     * @return array<string, mixed>
     */
    public static function filter(array $allcaps, array $caps, array $args, \WP_User $user): array
    {
        $own = $user->caps[AdminAccess::FULL_ACCESS] ?? false;
        if (in_array(AdminAccess::FULL_ACCESS, $caps, true) && true !== $own) {
            unset($allcaps[AdminAccess::FULL_ACCESS]);
        }

        return $allcaps;
    }
}
