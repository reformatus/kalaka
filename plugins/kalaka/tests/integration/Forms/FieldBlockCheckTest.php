<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\Api\EventState;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\FieldBlockCheck;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;

/** AC1 (the blocks), AC8 (D9, D10): the site-wide check of the blocks in published forms. */
final class FieldBlockCheckTest extends FormsTestCase
{
    private const BLOCKS = [
        'kalaka/form',
        'kalaka/name-field',
        'kalaka/email-field',
        'kalaka/phone-field',
        'kalaka/date-field',
        'kalaka/text-field',
        'kalaka/choice-field',
        'kalaka/consent-field',
        'kalaka/if',
        'kalaka/else-if',
        'kalaka/else',
        'kalaka/form-page',
        'kalaka/after-submit',
    ];

    /** @var list<LoadFailure> */
    private array $loadFailures = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->loadFailures = LoadState::current()->failures;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        LoadState::record($this->loadFailures);

        parent::tear_down();
    }

    /** @return list<string> */
    private function logged(\Closure $work): array
    {
        $lines = [];
        $previous = ini_set('error_log', $file = tempnam(sys_get_temp_dir(), 'kalaka'));
        try {
            $work();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        } finally {
            ini_set('error_log', (string) $previous);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
            unlink($file);
        }

        return $lines;
    }

    public function testEveryBlockIsRegisteredFromTheBuild(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $build = dirname(KALAKA_FILE) . '/build/';

        foreach (self::BLOCKS as $name) {
            $type = $registry->get_registered($name);
            self::assertNotNull($type, $name);
            self::assertNotNull($type->render_callback, $name);
            $callback = \Closure::fromCallable($type->render_callback);
            $used = (new \ReflectionFunction($callback))->getClosureUsedVariables();
            $template = $used['template_path'] ?? '';
            self::assertIsString($template);
            self::assertStringStartsWith($build, $template, $name);
            self::assertFileExists($template, $name);
        }

        $form = $registry->get_registered('kalaka/form');
        self::assertNotNull($form);
        self::assertNotSame([], $form->view_script_module_ids);
    }

    public function testTheBuildHoldsTheScriptModuleWithItsMap(): void
    {
        $directory = dirname(KALAKA_FILE) . '/build/Forms/Blocks/form/';

        self::assertFileExists($directory . 'view.js');
        self::assertFileExists($directory . 'view.js.map');
        self::assertFileExists($directory . 'view.asset.php');
        self::assertFileExists($directory . 'block.json');
        self::assertFileExists($directory . 'render.php');
    }

    public function testAMissingFieldBlockInAPublishedFormClosesTheSiteNamingTheBlockAndTheForm(): void
    {
        $event = $this->published();
        $form = $this->insertForm('<!-- wp:kalaka/missing-field {"key":"x"} /-->', ['kalaka_event_id' => $event]);

        $lines = $this->logged(static fn () => (new FieldBlockCheck())->run());

        $state = LoadState::current();
        self::assertFalse($state->isOpen());
        $failure = $state->failures[array_key_last($state->failures)];
        self::assertSame('forms', $failure->moduleId);
        self::assertStringContainsString('kalaka/missing-field', $failure->reason);
        self::assertStringContainsString('Form ' . $form . ' ', $failure->reason);
        // The check only closes the site; the boot logs the failure (when it starts or changes).
        self::assertSame([], $lines);
        self::assertSame([$failure], $state->late);
        $read = $this->events->find($event);
        self::assertNotNull($read);
        self::assertSame(EventState::Unpublished, $read->state());
    }

    public function testABlockInsideAnotherBlockIsFound(): void
    {
        $inner = '<!-- wp:kalaka/if {"condition":{}} -->'
            . '<!-- wp:kalaka/missing-field {"key":"x"} /-->'
            . '<!-- /wp:kalaka/if -->';
        $this->insertForm($inner);

        $this->logged(static fn () => (new FieldBlockCheck())->run());

        self::assertFalse(LoadState::current()->isOpen());
    }

    public function testTheSameBlockInADraftFormLeavesTheSiteOpen(): void
    {
        $this->insertForm('<!-- wp:kalaka/missing-field {"key":"x"} /-->', [], ['post_status' => 'draft']);

        (new FieldBlockCheck())->run();

        self::assertTrue(LoadState::current()->isOpen());
    }

    public function testRegisteredBlocksAndOtherNamespacesLeaveTheSiteOpen(): void
    {
        $this->insertForm(
            $this->slotFields() . '<!-- wp:acme/unknown /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'
        );

        (new FieldBlockCheck())->run();

        self::assertTrue(LoadState::current()->isOpen());
    }

    public function testTheBlockNamesAreCachedUntilAFormChanges(): void
    {
        $form = $this->insertForm($this->slotFields());
        (new FieldBlockCheck())->run();
        self::assertSame([$form => ['kalaka/name-field', 'kalaka/email-field']], get_option(FieldBlockCheck::OPTION));

        // Saved with the cache's hook removed: the check still reads the cached names.
        $clear = [FieldBlockCheck::class, 'onPostChange'];
        remove_action('wp_after_insert_post', $clear, 20);
        try {
            $this->unchecked(['ID' => $form, 'post_content' => '<!-- wp:kalaka/missing-field {"key":"x"} /-->']);
        } finally {
            add_action('wp_after_insert_post', $clear, 20, 2);
        }
        (new FieldBlockCheck())->run();
        self::assertTrue(LoadState::current()->isOpen());

        // A save, here a change of status, clears it; the next check reads the forms again.
        $this->unchecked(['ID' => $form, 'post_status' => 'draft']);
        self::assertNull(get_option(FieldBlockCheck::OPTION, null));
        $this->unchecked(['ID' => $form, 'post_status' => 'publish']);
        self::assertNull(get_option(FieldBlockCheck::OPTION, null));
        $this->logged(static fn () => (new FieldBlockCheck())->run());
        self::assertFalse(LoadState::current()->isOpen());
    }

    /**
     * Updates the form around the save check, which refuses publishing an unknown block.
     *
     * @param array<string, mixed> $post
     */
    private function unchecked(array $post): void
    {
        $check = [DefinitionCheck::class, 'onInsert'];
        remove_filter('wp_insert_post_data', $check, 20);
        try {
            self::assertIsInt(wp_update_post($post, true));
        } finally {
            add_filter('wp_insert_post_data', $check, 20, 2);
        }
    }

    public function testDeletingAFormClearsTheCache(): void
    {
        $form = $this->insertForm($this->slotFields());
        FieldBlockCheck::stored();
        self::assertNotNull(get_option(FieldBlockCheck::OPTION, null));

        wp_delete_post($form, true);

        self::assertNull(get_option(FieldBlockCheck::OPTION, null));
        self::assertSame([], FieldBlockCheck::stored());
    }

    public function testAMissingBuildClosesTheSiteWithTheCacheFilled(): void
    {
        $this->insertForm($this->slotFields());
        (new FieldBlockCheck())->run();
        self::assertTrue(LoadState::current()->isOpen());
        self::assertNotNull(get_option(FieldBlockCheck::OPTION, null));

        // Without `build/` the field blocks are not registered; the cached names are checked again.
        $registry = \WP_Block_Type_Registry::get_instance();
        $type = $registry->unregister('kalaka/email-field');
        self::assertNotFalse($type);
        try {
            $this->logged(static fn () => (new FieldBlockCheck())->run());
        } finally {
            $registry->register($type);
        }

        self::assertFalse(LoadState::current()->isOpen());
        $failure = LoadState::current()->failures[array_key_last(LoadState::current()->failures)];
        self::assertStringContainsString('kalaka/email-field', $failure->reason);
    }

    public function testWithoutTheBuildAPlacedFormShowsTheClosedSitesText(): void
    {
        $form = $this->insertForm($this->slotFields());
        $registry = \WP_Block_Type_Registry::get_instance();
        $built = $registry->unregister('kalaka/form');
        self::assertNotFalse($built);
        try {
            (new Module())->registerEmbedBlock(sys_get_temp_dir() . '/kalaka-no-build');
            $fallback = $registry->get_registered('kalaka/form');
            self::assertNotNull($fallback);
            self::assertSame([], $fallback->view_script_module_ids);
            LoadState::close(new LoadFailure('forms', 'the test closes the site'));

            $shortcode = do_shortcode('[kalaka_form id="' . $form . '"]');
            $block = do_blocks('<!-- wp:kalaka/form {"formId":' . $form . '} /-->');
        } finally {
            $registry->unregister('kalaka/form');
            $registry->register($built);
        }

        self::assertSame('<p>Váratlan hiba történt. Próbáld újra később.</p>', trim($shortcode));
        self::assertSame('<p>Váratlan hiba történt. Próbáld újra később.</p>', trim($block));
    }

    public function testTheCheckRunsOnWpLoaded(): void
    {
        $found = false;
        foreach ($GLOBALS['wp_filter']['wp_loaded']->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                $found = $found || (is_array($function) && $function[0] instanceof FieldBlockCheck);
            }
        }

        self::assertTrue($found);
    }
}
