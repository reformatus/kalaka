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

    private bool $standIn = false;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The form block is the front end's; a stand-in here so the gate's handling of it shows, only when
        // the real one is not registered (its teardown would unregister the real one otherwise).
        $this->standIn = !\WP_Block_Type_Registry::get_instance()->is_registered(BlockGate::FORM_BLOCK);
        if ($this->standIn) {
            register_block_type(BlockGate::FORM_BLOCK, []);
        }
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        if ($this->standIn) {
            unregister_block_type(BlockGate::FORM_BLOCK);
        }

        parent::tear_down();
    }

    /** @return bool|array<string> */
    private function allowedIn(string $postType): bool|array
    {
        $post = get_post(self::factory()->post->create(['post_type' => $postType]));
        self::assertInstanceOf(\WP_Post::class, $post);

        return get_allowed_block_types(new \WP_Block_Editor_Context(['post' => $post]));
    }

    public function testAFormAllowsExactlyItsOwnBlocksAndTheNamedCoreBlocks(): void
    {
        $allowed = $this->allowedIn('kalaka_form');

        self::assertIsArray($allowed);
        self::assertEqualsCanonicalizing(
            [
                ...self::FIELDS,
                'kalaka/if',
                'kalaka/else-if',
                'kalaka/else',
                'kalaka/form-page',
                'kalaka/after-submit',
                'core/paragraph',
                'core/heading',
                'core/list',
                'core/list-item',
                'core/separator',
                'core/image',
                'core/group',
            ],
            $allowed
        );
        self::assertNotContains(BlockGate::FORM_BLOCK, $allowed);
        self::assertNotContains('core/table', $allowed);
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
        self::assertContains('kalaka/form-page', $inForm);
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
