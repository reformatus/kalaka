<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\ThemeAdapters;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\SubmitToken;

/** AC2, AC3: the embed block on a page: the form, or the one refusal that keeps it from showing. */
final class FormBlockTest extends FormsTestCase
{
    private const FAILURE = 'Váratlan hiba történt. Próbáld újra később.';

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
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
        wp_set_current_user(0);

        parent::tear_down();
    }

    private function day(int $offset): string
    {
        // The runner's clock: the tests run at a fixed instant (TestClock).
        return Runner::current()->clock()->now()->setTimezone(wp_timezone())->setTime(0, 0)
            ->modify(sprintf('%+d days', $offset))->format('Y-m-d H:i');
    }

    /** A published event whose registration window runs from `$opens` to `$closes` days from today. */
    private function eventWithWindow(int $opens = -1, int $closes = 10, string $name = 'Nyári tábor'): int
    {
        $id = $this->create($name);
        $this->update($id, [
            'name' => $name,
            'registration_opens_at' => $this->day($opens),
            'registration_closes_at' => $this->day($closes),
            'starts_at' => $this->day($closes + 20),
            'ends_at' => $this->day($closes + 26),
        ]);
        $this->transition(new PublishEvent(), $id);

        return $id;
    }

    /** @param array<string, mixed> $meta */
    private function formOf(string $more = '', ?int $event = null, array $meta = [], array $post = []): int
    {
        return $this->insertForm(
            $this->slotFields() . $more,
            ['kalaka_event_id' => $event ?? $this->eventWithWindow()] + $meta,
            $post
        );
    }

    private function render(int $formId): string
    {
        return render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $formId],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    private function onlyParagraph(string $html): string
    {
        self::assertMatchesRegularExpression('#\A<p>[^<]+</p>\z#', trim($html));

        return wp_strip_all_tags($html);
    }

    /** @param array<string, mixed> $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        $attributes = $condition === null ? '' : ' ' . wp_json_encode(['condition' => $condition]);

        return '<!-- wp:kalaka/' . $name . $attributes . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    /** @return array<string, mixed> */
    private function compare(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]];
    }

    /** @return list<string> the opening tags of the branch wrappers, in document order */
    private function branches(string $html): array
    {
        preg_match_all('/<div data-kalaka-chain="[^>]*>/', $html, $matches);

        return $matches[0];
    }

    /** Whether every control of the field is disabled. */
    private function isDisabled(string $html, string $key): bool
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        $controls = 0;
        while ($tags->next_tag()) {
            $name = $tags->get_attribute('name');
            $isControl = in_array($tags->get_tag(), ['INPUT', 'TEXTAREA', 'SELECT'], true);
            if ($isControl && ($name === $key || $name === $key . '[]')) {
                ++$controls;
                if ($tags->get_attribute('disabled') === null) {
                    return false;
                }
            }
        }
        self::assertGreaterThan(0, $controls, $key);

        return true;
    }

    /** An after-submit section: a thank-you with the reference code. */
    private function afterSubmit(): string
    {
        return '<!-- wp:kalaka/after-submit --><!-- wp:paragraph --><p>Köszönjük! Kódod: '
            . '<span data-kalaka-value="registration.code">kód</span></p><!-- /wp:paragraph -->'
            . '<!-- /wp:kalaka/after-submit -->';
    }

    /** A choice field whose second and third options are offered only for the fee `extra`. */
    private function modules(): string
    {
        $extra = $this->compare('fee', 'extra');

        return $this->field('choice', 'modules', ['multiple' => true, 'options' => [
            ['value' => 'ifi_a', 'label' => 'IFI A'],
            ['value' => 'pro_a', 'label' => 'PRO A', 'condition' => $extra],
            ['value' => 'pro_b', 'label' => 'PRO B', 'condition' => $extra],
        ]]);
    }

    private function sizes(): string
    {
        return $this->field('choice', 'fee', ['options' => [
            ['value' => 'basic', 'label' => 'Alap'],
            ['value' => 'extra', 'label' => 'Támogatott'],
        ]]);
    }

    public function testTheFormRendersTheWarningTheFormTheMessageAreaAndTheDisabledButtonInOrder(): void
    {
        $first = $this->compare('fee', 'basic');
        $second = $this->compare('fee', 'extra');
        $more = $this->sizes()
            . $this->block('core/paragraph', [])
            . '<!-- wp:group --><div class="wp-block-group">'
            . $this->branch('if', $first, $this->field('text', 'one'))
            . $this->branch('else-if', $second, $this->branch('if', $first, $this->field('text', 'inner')))
            . $this->branch('else', null, $this->field('text', 'other'))
            . '</div><!-- /wp:group -->';
        $id = $this->formOf($more);

        $html = $this->render($id);

        $warning = strpos(
            $html,
            '<noscript><p>Az űrlap kitöltéséhez kapcsold be a JavaScriptet a böngésződben.</p></noscript>'
        );
        $form = strpos($html, '<form novalidate ');
        $alert = strpos($html, '<div role="alert"');
        $button = strpos($html, 'data-kalaka-send disabled>Küldés</button>');
        self::assertSame(0, $warning);
        self::assertTrue($warning < $form && $form < $alert && $alert < $button);
        self::assertStringContainsString('data-wp-interactive="kalaka/form"', $html);
        self::assertStringContainsString(
            '<label for="kalaka-field-name">name<span class="kalaka-field__required"',
            $html
        );
        self::assertStringEndsWith('</form>', trim($html));
        // A form without pages has neither Vissza nor Tovább; the spinner sits hidden at the end.
        self::assertStringNotContainsString('Vissza', $html);
        self::assertStringNotContainsString('Tovább', $html);
        self::assertMatchesRegularExpression(
            '#<div data-kalaka-spinner hidden>\s*<svg class="kalaka-spinner".*</svg>\s*</div>\s*'
            . '<div role="status" class="kalaka-visually-hidden" data-kalaka-status\s+'
            . 'data-kalaka-loading="Betöltés…"></div>\s*</form>\s*\z#s',
            $html
        );

        // The chains are numbered as the definition numbers them: the outer chain 0, the one inside its
        // `else-if` 1. With no answers only the `else` holds; the inner chain sits in a branch that does not.
        self::assertSame([
            '<div data-kalaka-chain="0" data-kalaka-branch="0" hidden>',
            '<div data-kalaka-chain="0" data-kalaka-branch="1" hidden>',
            '<div data-kalaka-chain="1" data-kalaka-branch="0" hidden>',
            '<div data-kalaka-chain="0" data-kalaka-branch="2">',
        ], $this->branches($html));
        self::assertStringNotContainsString('kalakaChain', $html);
        self::assertStringNotContainsString('data-wp-context=\'{&quot;earlier', $html);
        self::assertTrue($this->isDisabled($html, 'one'));
        self::assertTrue($this->isDisabled($html, 'inner'));
        self::assertFalse($this->isDisabled($html, 'other'));
        self::assertFalse($this->isDisabled($html, 'fee'));
    }

    /** The theme adapter's form classes and error colour go on the `<form>`, its submit-button classes on the button. */
    public function testTheAdaptersClassesGoOnTheFormAndTheButtonAndItsColourOnTheForm(): void
    {
        $before = Contributions::current();
        $id = $this->formOf();
        // The field types stay; the test's adapter comes first, before any of the site's.
        $contributions = new Contributions();
        foreach ($before->fieldTypes() as $type) {
            $contributions->fieldType($type);
        }
        $contributions->themeAdapter(new TestThemeAdapter(new ThemeAdaptation(
            ['form' => ['theme_form', 'clearfix'], 'submit_button' => ['theme_button']],
            errorColour: '#ff0000'
        )));
        Contributions::record($contributions);
        ThemeAdapters::reset();
        try {
            $html = $this->render($id);
        } finally {
            Contributions::record($before);
            ThemeAdapters::reset();
        }

        self::assertMatchesRegularExpression('/<form novalidate class="theme_form clearfix" /', $html);
        self::assertMatchesRegularExpression(
            '/<form novalidate [^>]*style="--kalaka-error-color: #ff0000"/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<button type="submit" class="theme_button"\s+data-kalaka-send disabled>Küldés<\/button>/',
            $html
        );
    }

    public function testABranchAndAFieldShowForAnEventValueTheConditionReads(): void
    {
        $started = ['match' => 'all', 'items' => [
            ['key' => 'event.name', 'operator' => 'equals', 'value' => 'Teli tabor'],
        ]];
        $id = $this->formOf(
            $this->branch('if', $started, $this->field('text', 'one'))
            . $this->branch('else', null, $this->field('text', 'other')),
            $this->eventWithWindow(name: 'Teli tabor')
        );

        $html = $this->render($id);

        // A chain that reads an event value is withheld (#156 N11): only its held branch ships, shown.
        self::assertStringContainsString('<div data-kalaka-chain="0" data-kalaka-branch="0">', $html);
        self::assertStringNotContainsString('data-kalaka-branch="1"', $html);
        self::assertFalse($this->isDisabled($html, 'one'));
        self::assertStringNotContainsString('name="other"', $html);
    }

    public function testAChoiceFieldRendersOnlyTheOptionsItOffers(): void
    {
        $html = $this->render($this->formOf($this->sizes() . $this->modules()));

        self::assertStringContainsString('value="ifi_a"', $html);
        self::assertStringNotContainsString('value="pro_a"', $html);
        self::assertStringNotContainsString('value="pro_b"', $html);
        self::assertStringContainsString('value="extra"', $html);
    }

    public function testTheContextCarriesTheVersionTheUrlsTheFailureTextAndTheTriggers(): void
    {
        $id = $this->formOf($this->sizes() . $this->branch('if', $this->compare('fee', 'extra'), ''));

        $html = $this->render($id);

        $pattern = "/<form novalidate data-wp-interactive=\"kalaka\\/form\"\\s+data-wp-context='([^']*)'/";
        self::assertSame(1, preg_match($pattern, $html, $match));
        $context = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($id, $context['formId']);
        self::assertSame($this->readForm($id)->version, $context['version']);
        self::assertSame(rest_url('kalaka-portal/v1/forms/' . $id . '/submit'), $context['url']);
        self::assertSame(
            rest_url('kalaka-portal/v1/forms/' . $id . '/availability'),
            $context['availabilityUrl']
        );
        self::assertSame(self::FAILURE, $context['failure']);
        self::assertSame(['fee'], $context['triggers']);
        self::assertArrayNotHasKey('fields', $context);
    }

    public function testAnInlineValueShowsTheEventsNameAndNoTokenIsInTheOutput(): void
    {
        $paragraph = '<!-- wp:paragraph --><p>Jelentkezés: <span data-kalaka-value="event.name">x</span></p>'
            . '<!-- /wp:paragraph -->';
        $id = $this->formOf($paragraph, $this->eventWithWindow(name: 'Őszi tábor'));

        $html = $this->render($id);

        self::assertStringContainsString(
            '<p class="wp-block-paragraph">Jelentkezés: <span >Őszi tábor</span></p>',
            $html
        );
        self::assertSame(0, preg_match('/[0-9a-f]{32}/', $html));
    }

    public function testEveryFieldHasOneErrorElementAndTheFormAddsNoSecond(): void
    {
        $html = $this->render($this->formOf($this->sizes()));

        self::assertSame(3, substr_count($html, 'class="kalaka-field__error" hidden></span>'));
        self::assertSame(1, substr_count($html, 'id="kalaka-field-fee-error"'));
    }

    public function testAnExtensionsFieldBlockGetsTheErrorElementAndAWrapperWhenItHasNone(): void
    {
        $contributions = \Kalaka\Foundation\Api\Module\Contributions::current();
        self::assertArrayHasKey('kalaka/name-field', $contributions->fieldTypes());
        $page = new \Kalaka\Forms\FormPage();
        $error = '<span id="kalaka-field-r-error" class="kalaka-field__error" hidden></span>';
        $parsed = ['blockName' => 'kalaka/name-field', 'attrs' => ['key' => 'r']];

        self::assertSame(
            '<div class="kalaka-field" data-kalaka-field="r"><input name="r">' . $error . '</div>',
            $page->forFormPage('<input name="r">', $parsed)
        );
        self::assertSame(
            '<p data-kalaka-field="r"><input name="r">' . $error . '</p>',
            $page->forFormPage('<p data-kalaka-field="r"><input name="r"></p>', $parsed)
        );
        self::assertSame(
            '<p>x</p>',
            $page->forFormPage('<p>x</p>', ['blockName' => 'core/paragraph', 'attrs' => ['key' => 'r']])
        );
    }

    public function testAClosedSiteRendersTheSystemFailureTextAndNoneOfTheForm(): void
    {
        $id = $this->formOf();
        LoadState::record([new LoadFailure('x', 'y')]);

        self::assertSame(self::FAILURE, $this->onlyParagraph($this->render($id)));
    }

    public function testADefinitionProblemGoesThroughTheBoundaryWithItsIdAndIsLogged(): void
    {
        // A registration-slot form without its contact email.
        $broken = $this->insertForm(
            $this->field('name', 'name', ['displayName' => true]),
            ['kalaka_event_id' => $this->eventWithWindow()]
        );
        $file = (string) tempnam(sys_get_temp_dir(), 'kalaka-log');
        $previous = ini_set('error_log', $file);
        try {
            $html = $this->render($broken);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
        $log = (string) file_get_contents($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
        unlink($file);

        self::assertStringStartsWith('<p>' . self::FAILURE . '</p><p><small>Hibaazonosító: ', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('Küldés', $html);
        self::assertStringContainsString('form ' . $broken . ' failed', $log);
        self::assertStringContainsString('contact_email_count', $log);
        // The problem code is for the log only.
        self::assertStringNotContainsString('contact_email_count', $html);
    }

    public function testABlockThatFailsToRenderFailsTheWholeFormInsideOneBoundary(): void
    {
        register_block_type('kalaka-test/fails', [
            'render_callback' => static function (): never {
                throw new \RuntimeException('cannot render');
            },
        ]);
        $id = $this->formOf('<!-- wp:kalaka-test/fails /-->');
        $log = ini_set('error_log', '/dev/null');
        try {
            $html = $this->render($id);
        } finally {
            ini_set('error_log', (string) $log);
            unregister_block_type('kalaka-test/fails');
        }

        self::assertStringStartsWith('<p>' . self::FAILURE . '</p><p><small>Hibaazonosító: ', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<noscript>', $html);
        self::assertStringNotContainsString('Küldés', $html);
    }

    public function testTheShortcodeRendersTheSameFormAndBothRenderersPreventCaching(): void
    {
        $id = $this->formOf();

        $html = do_shortcode('[kalaka_form id="' . $id . '"]');

        self::assertStringContainsString('<form novalidate data-wp-interactive="kalaka/form"', $html);
        self::assertStringContainsString('"formId":' . $id . ',', $html);
        // `FormCaching::preventCaching()` ran from the renderer: the page-cache plugins' constant is set.
        self::assertTrue(defined('DONOTCACHEPAGE'));
        self::assertSame(
            'Ez az űrlap jelenleg nem érhető el.',
            $this->onlyParagraph(do_shortcode('[kalaka_form id="999999"]'))
        );
        self::assertTrue(shortcode_exists('kalaka_form'));
    }

    public function testAnUnknownFormAndAFormThatIsNotPublishedAreNotAvailable(): void
    {
        $text = 'Ez az űrlap jelenleg nem érhető el.';
        $draft = $this->formOf('', null, [], ['post_status' => 'draft']);

        self::assertSame($text, $this->onlyParagraph($this->render(999999)));
        self::assertSame($text, $this->onlyParagraph($this->render(0)));
        self::assertSame($text, $this->onlyParagraph($this->render($draft)));
    }

    /** @return iterable<string, array{string}> */
    public static function eventsNotOpen(): iterable
    {
        yield 'a draft' => ['draft'];
        yield 'an unpublished event' => ['unpublished'];
        yield 'an archived event' => ['archived'];
    }

    /** @dataProvider eventsNotOpen */
    public function testAnEventThatIsNotPublishedIsNotAvailable(string $state): void
    {
        $event = match ($state) {
            'draft' => $this->filled(),
            'unpublished' => $this->unpublished(),
            default => $this->archived(),
        };

        self::assertSame(
            'Ez az űrlap jelenleg nem érhető el.',
            $this->onlyParagraph($this->render($this->formOf('', $event)))
        );
    }

    public function testBeforeTheOpeningDayAndAfterTheClosingDay(): void
    {
        $before = $this->formOf('', $this->eventWithWindow(5, 15));
        $after = $this->formOf('', $this->eventWithWindow(-15, -5));

        self::assertSame('A jelentkezés még nem kezdődött el.', $this->onlyParagraph($this->render($before)));
        self::assertSame('A jelentkezés lezárult.', $this->onlyParagraph($this->render($after)));
    }

    public function testASlotFormOfAnEventWithoutAnOpeningOrClosingDayIsNotAvailable(): void
    {
        global $wpdb;
        $event = $this->eventWithWindow();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}kalaka_events SET registration_closes_at = NULL WHERE id = %d",
            $event
        ));
        \Kalaka\Foundation\ObjectCache::clear();

        self::assertSame(
            'Ez az űrlap jelenleg nem érhető el.',
            $this->onlyParagraph($this->render($this->formOf('', $event)))
        );
    }

    public function testAnAdditionalFormIsNotBoundToTheWindow(): void
    {
        $id = $this->insertForm(
            $this->field('text', 'note'),
            ['kalaka_event_id' => $this->eventWithWindow(-15, -5), 'kalaka_placement' => 'additional']
        );

        self::assertStringContainsString('<form novalidate ', $this->render($id));
    }

    public function testAFormWithAPasswordShowsWordPressesPasswordFormUntilThereIsTheCookie(): void
    {
        $id = $this->formOf('', null, [], ['post_password' => 'titok']);

        $html = $this->render($id);

        $action = esc_url(site_url('wp-login.php?action=postpass', 'login_post'));
        self::assertStringContainsString('action="' . $action . '"', $html);
        self::assertStringContainsString('name="post_password"', $html);
        self::assertStringNotContainsString('<noscript>', $html);

        require_once ABSPATH . WPINC . '/class-phpass.php';
        $_COOKIE['wp-postpass_' . COOKIEHASH] = (new \PasswordHash(8, true))->HashPassword('titok');

        self::assertStringContainsString('<form novalidate ', $this->render($id));
    }

    public function testARequiredRoleTheCurrentUserLacksIsRefused(): void
    {
        $id = $this->formOf('', null, ['kalaka_required_role' => 'editor']);
        $text = 'Az űrlap kitöltéséhez jelentkezz be egy megfelelő jogosultságú fiókkal.';

        self::assertSame($text, $this->onlyParagraph($this->render($id)));

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        self::assertSame($text, $this->onlyParagraph($this->render($id)));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        self::assertStringContainsString('<form novalidate ', $this->render($id));
    }

    public function testWithSeveralRefusalsOnlyTheFirstInTheOrderOfTheRulesShows(): void
    {
        // Before the window, with a password and a required role: the window comes first.
        $id = $this->formOf(
            '',
            $this->eventWithWindow(5, 15),
            ['kalaka_required_role' => 'editor'],
            ['post_password' => 'titok']
        );
        self::assertSame('A jelentkezés még nem kezdődött el.', $this->onlyParagraph($this->render($id)));

        // In the window, with a password and a required role: the password comes before the role.
        $id = $this->formOf('', null, ['kalaka_required_role' => 'editor'], ['post_password' => 'titok']);
        self::assertStringContainsString('name="post_password"', $this->render($id));
        self::assertStringNotContainsString('jogosult', $this->render($id));
    }

    public function testTheAfterSubmitBlockNeverRendersWithTheForm(): void
    {
        $html = $this->render($this->formOf($this->afterSubmit()));

        self::assertStringContainsString('<form', $html);
        self::assertStringNotContainsString('Köszönjük!', $html);
        self::assertStringNotContainsString('Kódod', $html);
    }

    public function testThePageCarriesAFreshSubmitTokenInAHiddenField(): void
    {
        $id = $this->formOf();

        preg_match('/<input type="hidden" data-kalaka-token value="([^"]*)">/', $this->render($id), $first);
        preg_match('/<input type="hidden" data-kalaka-token value="([^"]*)">/', $this->render($id), $second);

        self::assertTrue(SubmitToken::isValid($first[1] ?? ''));
        self::assertTrue(SubmitToken::isValid($second[1] ?? ''));
        self::assertNotSame($first[1], $second[1]);
    }
}
