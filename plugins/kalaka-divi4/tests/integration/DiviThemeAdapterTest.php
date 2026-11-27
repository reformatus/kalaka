<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Divi4;

use Kalaka\Divi4\DiviThemeAdapter;
use Kalaka\Divi4\Module;
use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldWrapper;
use Kalaka\Forms\Api\ThemeAdapterInput;
use WP_UnitTestCase;

/** The Divi 4 adapter: it applies only to Divi 4 (also under a child theme), with Divi's contact-form look. */
final class DiviThemeAdapterTest extends WP_UnitTestCase
{
    /** An adapter that sees the given Divi version. */
    private static function divi(string $version): DiviThemeAdapter
    {
        return new DiviThemeAdapter(static fn (string $template): string => $version);
    }

    public function testItAppliesOnlyToDivi4(): void
    {
        self::assertNull(self::divi('4.27.4')->adapt(new ThemeAdapterInput('twentytwentyfive', 'twentytwentyfive')));
        self::assertNotNull(self::divi('4.27.4')->adapt(new ThemeAdapterInput('Divi', 'Divi')));
        self::assertNotNull(self::divi('4.27.4')->adapt(new ThemeAdapterInput('divi-child', 'Divi')));
    }

    public function testDivi5AndAnUnknownVersionGetNoAdaptation(): void
    {
        $input = new ThemeAdapterInput('Divi', 'Divi');

        self::assertNull(self::divi('5.0.0')->adapt($input));
        self::assertNull(self::divi('5.0.0-public-beta.1')->adapt($input));
        self::assertNull(self::divi('')->adapt($input));
        self::assertTrue(self::divi('4.0')->applies('Divi'));
        self::assertFalse(self::divi('5.1')->applies('Divi'));
    }

    public function testTheVersionIsTheInstalledThemesByDefault(): void
    {
        $adapter = new DiviThemeAdapter();

        // No Divi in the test environment: its version is unknown, so the adapter does not apply.
        self::assertFalse($adapter->applies('Divi'));
        self::assertFalse($adapter->applies('twentytwentyfive'));
    }

    public function testDivisContactFormClassesThePWrapperAndDivisRed(): void
    {
        $adaptation = self::divi('4.27.4')->adapt(new ThemeAdapterInput('Divi', 'Divi'));

        self::assertNotNull($adaptation);
        self::assertSame(['et_pb_contact', 'et_pb_contact_form'], $adaptation->classes(ElementRole::Form));
        self::assertSame(['et_pb_contact_field'], $adaptation->classes(ElementRole::Field));
        self::assertSame(['input', 'et_pb_contact_message'], $adaptation->classes(ElementRole::TextControl));
        self::assertSame(['et_pb_contact_submit', 'et_pb_button'], $adaptation->classes(ElementRole::SubmitButton));
        // Visible labels and the browser's own radio buttons and checkboxes: no Divi class on them.
        self::assertSame([], $adaptation->classes(ElementRole::Label));
        self::assertSame([], $adaptation->classes(ElementRole::ChoiceControl));
        self::assertSame(FieldWrapper::P, $adaptation->wrapper);
        self::assertSame('#ff0000', $adaptation->errorColour);
    }

    public function testAFormInTheContentIsTheBlockOrTheShortcode(): void
    {
        self::assertTrue(Module::holdsForm('<!-- wp:kalaka/form {"formId":8} /-->'));
        self::assertTrue(
            Module::holdsForm('[et_pb_section][et_pb_text][kalaka_form id="8"][/et_pb_text][/et_pb_section]')
        );
        self::assertFalse(Module::holdsForm('<!-- wp:paragraph --><p>kalaka_form</p><!-- /wp:paragraph -->'));
    }

    public function testDivisFormStylesAreAskedForOnlyWhereAFormIsAndOnlyUnderDivi4(): void
    {
        $module = new Module();
        $form = '[kalaka_form id="8"]';

        self::assertSame([], $module->requiredAssets([], $form), 'not Divi');

        $template = static fn (): string => 'Divi';
        add_filter('template', $template);
        try {
            // Divi is not installed here, so its version is unknown: not Divi 4.
            self::assertSame([], $module->requiredAssets([], $form), 'no Divi 4');

            $divi4 = new Module(self::divi('4.27.4'));
            self::assertSame(['et_pb_contact_form'], $divi4->requiredAssets([], $form));
            self::assertSame(['et_pb_contact_form'], $divi4->requiredAssets(['et_pb_contact_form'], $form));
            self::assertSame([], $divi4->requiredAssets([], 'no form here'));
            self::assertSame([], (new Module(self::divi('5.0.0')))->requiredAssets([], $form), 'Divi 5');
        } finally {
            remove_filter('template', $template);
        }
    }

    public function testUnderDivi4APageWithAFormGetsTheBrowsersOwnCheckboxesAndRadiosBackOnAnIphone(): void
    {
        $post = self::factory()->post->create(['post_content' => '[kalaka_form id="8"]']);
        $this->go_to((string) get_permalink($post));
        // A fresh style queue: the tests share WordPress's global one.
        $GLOBALS['wp_styles'] = null;
        $template = static fn (): string => 'Divi';
        add_filter('template', $template);
        try {
            (new Module(self::divi('4.27.4')))->enqueue();
        } finally {
            remove_filter('template', $template);
        }

        self::assertTrue(wp_style_is('kalaka-divi4', 'enqueued'));
        $css = implode('', (array) wp_styles()->get_data('kalaka-divi4', 'after'));
        self::assertStringContainsString(Module::IPHONE_CONTROLS, $css);
        // Scoped to Kaláka's fields inside the form Divi styles, and only to checkboxes and radio buttons;
        // the ranking's +/× toggle, a checkbox Kaláka draws itself, is left out.
        self::assertSame(
            '.iphone .et_pb_contact_form .kalaka-field input[type=checkbox]:not([data-kalaka-toggle]),'
                . '.iphone .et_pb_contact_form .kalaka-field input[type=radio]'
                . '{-webkit-appearance:auto;appearance:auto}',
            Module::IPHONE_CONTROLS
        );
    }

    public function testUnderDivi4ThePageMapsCoresNeutralBaseOntoDivisFormLook(): void
    {
        $post = self::factory()->post->create(['post_content' => '[kalaka_form id="8"]']);
        $this->go_to((string) get_permalink($post));
        // A fresh style queue: the tests share WordPress's global one.
        $GLOBALS['wp_styles'] = null;
        $template = static fn (): string => 'Divi';
        add_filter('template', $template);
        try {
            (new Module(self::divi('4.27.4')))->enqueue();
        } finally {
            remove_filter('template', $template);
        }
        $css = implode('', (array) wp_styles()->get_data('kalaka-divi4', 'after'));

        // The properties, set on Divi's contact form: Divi's input greys, no border or radius, the filled +/×.
        self::assertStringContainsString(
            '.et_pb_contact_form{--kalaka-control-background:#eee;--kalaka-control-color:#666;'
                . '--kalaka-control-hover-background:#ddd;--kalaka-border-color:transparent;--kalaka-radius:0;'
                . '--kalaka-button-background:#eee;--kalaka-button-color:#666;--kalaka-button-hover-background:#ddd}',
            $css
        );
        // Every property it sets or reads is one core's base documents (Blocks/form/view.css): no misspelt name.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file
        $base = (string) file_get_contents(dirname(__DIR__, 3) . '/kalaka/src/Forms/Blocks/form/view.css');
        preg_match_all('/--kalaka-[a-z-]+/', $css, $used);
        self::assertCount(8, array_unique($used[0]));
        foreach (array_unique($used[0]) as $property) {
            self::assertStringContainsString('`' . $property . '`', $base, $property);
        }
        // What the properties cannot reach: the ranking's outlined ↑/↓, and the dragged row's lift.
        self::assertStringContainsString(
            '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]>button'
                . '{background:transparent;color:var(--kalaka-control-color);border:2px solid #eee;'
                . 'border-radius:var(--kalaka-radius)}',
            $css
        );
        self::assertStringContainsString(
            '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-dragging] [data-kalaka-row]'
                . '{background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15)}',
            $css
        );
    }

    public function testAMoneyTableInTheContentIsTheStatusBlockOrTheShortcode(): void
    {
        self::assertTrue(Module::holdsMoneyTable('<!-- wp:kalaka/status /-->'));
        self::assertTrue(Module::holdsMoneyTable('[et_pb_section][et_pb_text][kalaka_status][/et_pb_text]'));
        self::assertFalse(Module::holdsMoneyTable('<!-- wp:kalaka/transfer /-->[kalaka_transfer]'));
        self::assertFalse(Module::holdsMoneyTable('<!-- wp:paragraph --><p>kalaka_status</p><!-- /wp:paragraph -->'));
    }

    /** The adapter's CSS of a page with the given content, under Divi 4 ('' when none is enqueued). */
    private function divi4Css(string $content): string
    {
        $post = self::factory()->post->create(['post_content' => $content]);
        $this->go_to((string) get_permalink($post));
        // A fresh style queue: the tests share WordPress's global one.
        $GLOBALS['wp_styles'] = null;
        $template = static fn (): string => 'Divi';
        add_filter('template', $template);
        try {
            (new Module(self::divi('4.27.4')))->enqueue();
        } finally {
            remove_filter('template', $template);
        }

        return wp_style_is('kalaka-divi4', 'enqueued')
            ? implode('', (array) wp_styles()->get_data('kalaka-divi4', 'after'))
            : '';
    }

    public function testUnderDivi4AStatusPageGetsTheMoneyTablesRowLineAndNothingOfTheForm(): void
    {
        $css = $this->divi4Css('<!-- wp:kalaka/status /-->');

        // Divi's `#eee` line of a `td`, on the row header too; not on the rows core separates itself.
        self::assertSame(
            '.kalaka-status table.kalaka-money tr:not(.kalaka-rule)>th{border-top:1px solid #eee}',
            $css
        );
    }

    public function testAFormPageGetsNoMoneyTableRule(): void
    {
        self::assertStringNotContainsString('kalaka-money', $this->divi4Css('[kalaka_form id="8"]'));
        self::assertStringContainsString('et_pb_contact_form', $this->divi4Css('[kalaka_form id="8"]'));
    }

    public function testAPageWithoutAFormGetsNoStylesheet(): void
    {
        $post = self::factory()->post->create(['post_content' => 'Nincs itt űrlap.']);
        $this->go_to((string) get_permalink($post));
        // A fresh style queue: the tests share WordPress's global one.
        $GLOBALS['wp_styles'] = null;
        $template = static fn (): string => 'Divi';
        add_filter('template', $template);
        try {
            (new Module(self::divi('4.27.4')))->enqueue();
        } finally {
            remove_filter('template', $template);
        }

        self::assertFalse(wp_style_is('kalaka-divi4', 'enqueued'));
    }
}
