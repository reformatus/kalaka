<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * Admin use cases as WordPress abilities (foundation.md, "Cross-cutting rules"). Each module registers
 * its own from `Module::boot()`. WordPress checks the input against the use case's schema and gives
 * the REST routes.
 *
 * [UNAPPROVED S36-2] The permission callback lets through only a user with full access or at least one
 * grant (`Access::mayReachAdmin()`); anyone else gets WordPress's refusal and no audit entry. The runner's
 * coarse check still decides each call.
 */
final class Abilities
{
    /** The category of every Kaláka ability; Admin registers it. */
    public const CATEGORY = 'kalaka';

    /** Registers the ability category, once WordPress asks for categories. */
    public static function registerCategory(): void
    {
        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category(self::CATEGORY, [
                'label' => 'Kaláka', // [UNAPPROVED S36-10]
                'description' => 'A Kaláka műveletei.', // [UNAPPROVED S36-10]
            ]);
        });
    }

    /** Registers the use case as the ability named by `name()`, once WordPress asks for abilities. */
    public static function register(AdminUseCase $useCase, string $label, string $description): void
    {
        add_action('wp_abilities_api_init', static function () use ($useCase, $label, $description): void {
            $name = $useCase::name();
            if (strtolower($name) !== $name || !str_contains($name, '/')) {
                throw new \LogicException('A use case name is not a lowercase "namespace/name" ability name.');
            }
            wp_register_ability($name, [
                'label' => $label,
                'description' => $description,
                'category' => self::CATEGORY,
                'input_schema' => $useCase->inputSchema(),
                'permission_callback' => static fn (): bool => self::permitted(),
                'execute_callback' => static fn (mixed $input = null): mixed => self::run($useCase, $input),
                'meta' => ['show_in_rest' => true],
            ]);
        });
    }

    /** Whether the current user may reach the admin side's abilities at all. */
    public static function permitted(): bool
    {
        try {
            return Access::mayReachAdmin(get_current_user_id());
        } catch (\Throwable $e) {
            Boundary::shown('the abilities permission check', $e);

            return false;
        }
    }

    /** @return mixed what the use case returned, or the `WP_Error` of what it threw */
    private static function run(AdminUseCase $useCase, mixed $input): mixed
    {
        try {
            $actor = new UserActor(get_current_user_id());

            return Runner::current()->run($useCase, is_array($input) ? $input : [], $actor);
        } catch (\Throwable $e) {
            return Boundary::shown('ability ' . $useCase::name(), $e)->toWpError();
        }
    }
}
