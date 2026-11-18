<?php

declare(strict_types=1);

namespace Kalaka\Ranking;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

final class Module extends AbstractModule
{
    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(private readonly RankingFieldType $fieldType = new RankingFieldType())
    {
    }

    public function id(): string
    {
        return 'ranking';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function contribute(Contributions $to): void
    {
        $to->fieldType($this->fieldType);
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
    }

    /**
     * Registers the ranking field's block from `build/`, where the JavaScript build writes it (engineering.md,
     * "Builds"). Without a build it is not registered, and a published form holding it closes the site.
     *
     * @internal the `init` action
     */
    public function register(): void
    {
        $block = dirname(__DIR__, 2) . '/build/Ranking/Blocks/ranking-field';
        if (is_file($block . '/block.json')) {
            register_block_type($block);
        }
    }
}
