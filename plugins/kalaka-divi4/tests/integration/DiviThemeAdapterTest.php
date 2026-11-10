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
}
