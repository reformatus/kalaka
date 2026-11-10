<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\BlockHost;
use Kalaka\Forms\BlockGate;
use Kalaka\Foundation\Api\Module\Contributions;
use WP_UnitTestCase;

/** Kaláka's form blocks are gated by post type through `allowed_block_types_all`. */
final class BlockGateTest extends WP_UnitTestCase
{
    private const FIELDS = [
        'kalaka/name-field',
        'kalaka/email-field',
        'kalaka/phone-field',
        'kalaka/date-field',
        'kalaka/text-field',
        'kalaka/choice-field',
        'kalaka/consent-field',
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The form block is the front end's; a stand-in here so the gate's handling of it shows.
        register_block_type(BlockGate::FORM_BLOCK, []);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        unregister_block_type(BlockGate::FORM_BLOCK);

        parent::tear_down();
    }

    /** @return bool|array<string> */
    private function allowedIn(string $postType): bool|array
    {
        $post = get_post(self::factory()->post->create(['post_type' => $postType]));
        self::assertInstanceOf(\WP_Post::class, $post);

        return get_allowed_block_types(new \WP_Block_Editor_Context(['post' => $post]));
    }

    public function testAFormAllowsTheFieldBlocksAndCoreBlocksButNotTheFormBlock(): void
    {
        $allowed = $this->allowedIn('kalaka_form');

        self::assertIsArray($allowed);
        foreach ([...self::FIELDS, 'core/paragraph', 'core/heading', 'core/group'] as $name) {
            self::assertContains($name, $allowed, $name);
        }
        self::assertNotContains(BlockGate::FORM_BLOCK, $allowed);
    }

    public function testAPostAndAPageAllowTheFormBlockButNoFieldBlock(): void
    {
        foreach (['post', 'page'] as $postType) {
            $allowed = $this->allowedIn($postType);

            self::assertIsArray($allowed, $postType);
            self::assertContains(BlockGate::FORM_BLOCK, $allowed, $postType);
            self::assertContains('core/paragraph', $allowed, $postType);
            self::assertSame([], array_values(array_intersect(self::FIELDS, $allowed)), $postType);
        }
    }

    public function testAListFromAnotherFilterIsNarrowedElsewhereAndGetsTheFieldsBackInAForm(): void
    {
        $narrow = static fn (): array => ['core/paragraph', 'kalaka/text-field', BlockGate::FORM_BLOCK];
        add_filter('allowed_block_types_all', $narrow, 5);
        try {
            $inPost = $this->allowedIn('post');
            $inForm = $this->allowedIn('kalaka_form');
        } finally {
            remove_filter('allowed_block_types_all', $narrow, 5);
        }

        self::assertSame(['core/paragraph', BlockGate::FORM_BLOCK], $inPost);
        self::assertIsArray($inForm);
        self::assertContains('core/paragraph', $inForm);
        self::assertNotContains('core/heading', $inForm);
        self::assertNotContains(BlockGate::FORM_BLOCK, $inForm);
        self::assertSame([], array_values(array_diff(self::FIELDS, $inForm)));
    }

    public function testFalseFromAnotherFilterStaysFalse(): void
    {
        $none = static fn (): bool => false;
        add_filter('allowed_block_types_all', $none, 5);
        try {
            self::assertFalse($this->allowedIn('kalaka_form'));
        } finally {
            remove_filter('allowed_block_types_all', $none, 5);
        }
    }

    public function testABlockHostAllowsTheBlocksItNamesInThePostTypeOrEditorItNames(): void
    {
        $contributions = new Contributions();
        foreach (Contributions::current()->fieldTypes() as $type) {
            $contributions->fieldType($type);
        }
        $contributions->blockHost(new BlockHost(['kalaka/text-field'], 'kalaka_mail'));
        $contributions->blockHost(new BlockHost(['kalaka/date-field'], null, 'core/edit-site'));
        $all = ['core/paragraph', ...self::FIELDS];

        self::assertSame(
            ['core/paragraph', 'kalaka/text-field'],
            BlockGate::allowed($all, 'kalaka_mail', 'core/edit-post', $contributions)
        );
        self::assertSame(['core/paragraph'], BlockGate::allowed($all, 'post', 'core/edit-post', $contributions));
        self::assertSame(
            ['core/paragraph', 'kalaka/date-field'],
            BlockGate::allowed($all, null, 'core/edit-site', $contributions)
        );
        $this->expectException(\InvalidArgumentException::class);
        new BlockHost(['kalaka/if']);
    }
}
