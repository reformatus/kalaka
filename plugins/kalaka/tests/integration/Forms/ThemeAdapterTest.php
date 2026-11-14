<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldWrapper;
use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Engine\ThemeAdapters;
use Kalaka\Foundation\Api\Module\Contributions;
use WP_UnitTestCase;

/** The theme-adapter point: classes per element role and the wrapper, applied by core to the field markup. */
final class ThemeAdapterTest extends WP_UnitTestCase
{
    private Contributions $before;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->before = Contributions::current();
        ThemeAdapters::reset();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Contributions::record($this->before);
        ThemeAdapters::reset();

        parent::tear_down();
    }

    private function adapters(TestThemeAdapter ...$adapters): void
    {
        $contributions = new Contributions();
        foreach ($adapters as $adapter) {
            $contributions->themeAdapter($adapter);
        }
        Contributions::record($contributions);
        ThemeAdapters::reset();
    }

    /** @param array<string, mixed> $attributes */
    private function render(string $type, array $attributes): string
    {
        return render_block([
            'blockName' => 'kalaka/' . $type . '-field',
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    private function divi(): ThemeAdaptation
    {
        return new ThemeAdaptation(
            [
                'field' => ['et_pb_contact_field'],
                'label' => ['et_pb_contact_form_label'],
                'text_control' => ['input'],
                'choice_group' => ['et_pb_contact_field_options_wrapper'],
                'option' => ['et_pb_contact_field_checkbox'],
                'choice_control' => ['input', 'kalaka-native'],
                'required_marker' => ['et_required'],
                'error_message' => ['et-pb-contact-message'],
            ],
            FieldWrapper::P,
            '#e02b20'
        );
    }

    public function testTheAdapterIsAskedWithTheActiveTheme(): void
    {
        $adapter = new TestThemeAdapter(null);
        $this->adapters($adapter);

        ThemeAdapters::current();

        self::assertNotNull($adapter->askedWith);
        self::assertSame(get_stylesheet(), $adapter->askedWith->stylesheet);
        self::assertSame(get_template(), $adapter->askedWith->template);
    }

    public function testASingleControlFieldGetsTheClassesAndThePWrapper(): void
    {
        $this->adapters(new TestThemeAdapter($this->divi()));

        $html = $this->render('email', ['key' => 'email', 'label' => 'E-mail', 'required' => true]);

        self::assertMatchesRegularExpression(
            '/\A\s*<p class="kalaka-field et_pb_contact_field wp-block-kalaka-email-field"/',
            $html
        );
        self::assertStringContainsString('<label for="kalaka-field-email" class="et_pb_contact_form_label">', $html);
        self::assertStringContainsString('name="email" autocomplete="email" class="input" required>', $html);
        self::assertStringContainsString(
            '<span class="kalaka-field__required et_required" aria-hidden="true">*</span>',
            $html
        );
        self::assertStringContainsString('class="kalaka-field__error et-pb-contact-message" hidden>', $html);
        self::assertMatchesRegularExpression('#</p>\s*\z#', $html);
        self::assertSame('#e02b20', ThemeAdapters::current()->errorColour);
    }

    public function testAConsentStaysInADivAndTheFormRoleIsThere(): void
    {
        $this->adapters(new TestThemeAdapter(new ThemeAdaptation(['form' => ['et_pb_contact']], FieldWrapper::P)));

        $html = $this->render('consent', ['key' => 'gdpr', 'label' => 'Elfogadom']);

        self::assertMatchesRegularExpression('/\A\s*<div class="kalaka-field wp-block-kalaka-consent-field"/', $html);
        self::assertMatchesRegularExpression('#</div>\s*\z#', $html);
        self::assertSame(['et_pb_contact'], ThemeAdapters::current()->classes(ElementRole::Form));
    }

    public function testAChoiceStaysInADivWithTheClassesOfItsRoles(): void
    {
        $this->adapters(new TestThemeAdapter($this->divi()));

        $html = $this->render('choice', [
            'key' => 'size',
            'label' => 'Méret',
            'options' => [['value' => 'kicsi', 'label' => 'Kicsi']],
        ]);

        self::assertMatchesRegularExpression('/\A\s*<div class="kalaka-field et_pb_contact_field/', $html);
        self::assertStringContainsString('<fieldset class="et_pb_contact_field_options_wrapper">', $html);
        self::assertStringContainsString('<legend class="et_pb_contact_form_label">', $html);
        self::assertStringContainsString('<label class="et_pb_contact_field_checkbox">', $html);
        self::assertStringContainsString('value="kicsi" class="input kalaka-native">', $html);
    }

    public function testTheFirstAdapterThatAppliesIsTheOnlyOne(): void
    {
        $this->adapters(
            new TestThemeAdapter(null),
            new TestThemeAdapter(new ThemeAdaptation(['field' => ['first']])),
            new TestThemeAdapter(new ThemeAdaptation(['field' => ['second']]))
        );

        $html = $this->render('date', ['key' => 'born', 'label' => 'Született']);

        self::assertStringContainsString('class="kalaka-field first wp-block-kalaka-date-field"', $html);
        self::assertStringNotContainsString('second', $html);
    }

    public function testAFailingAdapterIsSkippedAndTheFieldStillRenders(): void
    {
        $this->adapters(
            new TestThemeAdapter(static fn (): ThemeAdaptation => throw new \RuntimeException('broken adapter')),
            new TestThemeAdapter(static fn (): ThemeAdaptation => new ThemeAdaptation(['label' => ['two classes']])),
            new TestThemeAdapter(static fn (): ThemeAdaptation => new ThemeAdaptation([], FieldWrapper::Div, 'red')),
            new TestThemeAdapter(static fn (): ThemeAdaptation => new ThemeAdaptation(['menu' => ['x']])),
        );
        $logged = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $html = $this->render('date', ['key' => 'born', 'label' => 'Született']);
        } finally {
            ini_set('error_log', (string) $logged);
        }

        self::assertStringContainsString(
            '<div class="kalaka-field wp-block-kalaka-date-field" data-kalaka-field="born">',
            $html
        );
        self::assertStringContainsString('<input type="date" id="kalaka-field-born" name="born">', $html);
    }

    public function testWithNoAdapterTheMarkupHasOnlyCoresClassesAndADiv(): void
    {
        $this->adapters();

        $adaptation = ThemeAdapters::current();

        self::assertSame(FieldWrapper::Div, $adaptation->wrapper);
        self::assertNull($adaptation->errorColour);
        self::assertStringStartsWith(
            '<div class="kalaka-field wp-block-kalaka-phone-field"',
            trim($this->render('phone', ['key' => 'tel', 'label' => 'Telefon']))
        );
    }
}
