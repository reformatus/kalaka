<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Engine\FormPosts;

/**
 * [UNAPPROVED N28] The account block (`SignUp::BLOCK`, owner ruling 2026-11-18) in a form's editor and its save:
 *
 * - `allowed()`: the block is not offered in the editor of a form that cannot have accounts: an additional
 *   form, or a form whose event's account mode is `off`. It stays offered while the form's event or placement
 *   is not known yet. Where it is present anyway, it renders nothing (the form has no account step).
 * - `problem()`: a published or scheduled registration form of an event whose account mode is `mandatory` or
 *   `optional` must hold it (`account_block_missing`). Only that it exists is checked, not where it sits: in
 *   the form's body, outside the after-submit section.
 */
final class AccountBlock
{
    /** The save check's problem code. */
    public const MISSING = 'account_block_missing';

    /**
     * `allowed_block_types_all`, after `Engine\BlockGate` (priority 11): the account block taken out of a form
     * editor's list when the form cannot have accounts.
     *
     * @param bool|array<string> $allowed
     * @return bool|array<string>
     */
    public static function allowed(bool|array $allowed, \WP_Block_Editor_Context $context): bool|array
    {
        $post = $context->post;
        if (!is_array($allowed) || !$post instanceof \WP_Post || $post->post_type !== FormPosts::POST_TYPE) {
            return $allowed;
        }
        $placement = FormPlacement::tryFrom((string) get_post_meta($post->ID, FormPosts::META_PLACEMENT, true));
        if (!self::offered($placement, FormEvent::stored($post->ID))) {
            return array_values(array_diff($allowed, [SignUp::BLOCK]));
        }

        return $allowed;
    }

    /**
     * Whether a form of that placement in that event may have the account block: not an additional form, and
     * not in an event whose account mode is `off`. Unknown (null) counts as may.
     */
    public static function offered(?FormPlacement $placement, ?int $eventId): bool
    {
        $event = $eventId === null ? null : (new Events())->find($eventId);

        return $event === null
            ? $placement !== FormPlacement::Additional
            : EventFormHost::stepFor($placement ?? FormPlacement::Registration, $event->accountMode) !== null;
    }

    /**
     * The save check's problem for a live registration form's definition: the event has accounts and the form
     * has no account block.
     *
     * @return array{string, string}|null
     */
    public static function problem(FormDefinition $definition, FormPlacement $placement, ?int $eventId): ?array
    {
        $event = $eventId === null ? null : (new Events())->find($eventId);
        if (
            $event === null
            || $definition->account !== null
            || EventFormHost::stepFor($placement, $event->accountMode) === null
        ) {
            return null;
        }

        return [self::MISSING, SignUp::BLOCK];
    }
}
