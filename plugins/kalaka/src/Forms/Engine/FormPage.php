<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormAvailability;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Engine\Condition\ChainMarks;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\Engine\Value\InlineValues;
use Kalaka\Forms\Engine\Value\RenderStack;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The public page of a form (events-and-forms.md, "Forms implementation" and "Conditions"): decides
 * whether the form shows and, if it does, renders its content for its current answers from the form's
 * availability (S76): every branch renders, the held one of each chain shown and the others hidden; the
 * controls of a field that does not show are disabled; a choice field renders only the options it
 * offers. The host's values (`FormHost::facts()`, the event's) are filled in as the value API fills
 * them in a mail. The script holds no condition: it re-fetches the availability and the re-rendered
 * choice fields (`Rest\FormRoutes`). The chains
 * and pages are numbered once, by `Condition\ChainMarks` (`FormEngine::blocks()`); this page renumbers nothing.
 * A multi-page form renders every page, each hidden (`PageMarkup`): the script shows the current one.
 *
 * A form whose host gives an account step (`FormHost::accountStep()`, S15) is not shown to someone not
 * logged in (unless they skipped, in `optional` mode): the sign-up redirect sends them to WordPress's
 * registration page first, and a page it could not see shows the link to it instead. For someone logged
 * in, the form's contact email is filled in with the account's address and stays editable.
 */
final class FormPage
{
    private readonly FormEngine $forms;

    public function __construct(
        private readonly FormHost $host,
        ?FormEngine $engine = null,
        private readonly InlineValues $inline = new InlineValues(),
    ) {
        $this->forms = $engine ?? new FormEngine($host);
    }

    /** @var list<FormAvailability> the availability of each form page rendering, innermost last */
    private static array $rendering = [];

    /** @var list<array<string, string>> the filled-in values of each form page rendering, by field key */
    private static array $filled = [];

    /**
     * The whole form, or its one refusal, inside one error boundary (foundation.md, "Errors"): a form
     * whose field fails to render, or whose definition has a problem, fails as a whole, and the error
     * shows in place of the form. Field blocks have no boundary of their own.
     *
     * @param \Closure(FormPageView): void $print prints the view's markup
     */
    public function html(int $formId, \Closure $print): string
    {
        return Boundary::render('form ' . $formId, function () use ($formId, $print): string {
            $view = $this->open($formId);
            ob_start();
            try {
                $print($view);
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        });
    }

    /**
     * The form page for the form, or its one refusal. A definition problem is not a refusal: its
     * `SystemFailure` is thrown to the boundary of `html()`.
     *
     * @throws SystemFailure
     */
    public function open(int $formId): FormPageView
    {
        // An event form's `refusals()` returns `event_not_open` for a closed site too, so the load state is read first.
        if (!LoadState::current()->isOpen()) {
            return FormPageView::refused(FormPageView::SYSTEM_FAILURE);
        }

        $form = $formId > 0 ? $this->forms->form($formId) : null;
        $facts = $form === null ? null : $this->host->facts($form);
        $post = $form === null ? null : get_post($form->id);
        if ($form === null || $facts === null || !$post instanceof \WP_Post) {
            return FormPageView::refused(FormPageView::UNAVAILABLE);
        }

        $now = Runner::current()->clock()->now();
        $refusals = $this->refusals($form, $post, $now);
        if ($refusals !== []) {
            return $this->refusal($refusals[0], $form, $now, $post);
        }
        $step = $this->host->accountStep($form);
        if ($step !== null && !is_user_logged_in() && !$this->skipped($step, $form)) {
            // The sign-up redirect (`Forms\SignUpRedirect`) sends the visitor to sign up first; a page it could
            // not see (a theme's or a builder's template) shows the link instead of a form that cannot be sent.
            return FormPageView::refused(FormPageView::ACCOUNT_REQUIRED, null, SignUp::url($form->id, self::here()));
        }

        return $this->view($form, $facts, $step);
    }

    /** @return list<AccessRefusal> */
    private function refusals(Form $form, \WP_Post $post, \DateTimeImmutable $now): array
    {
        return $this->host->refusals($form, $now, self::currentRoles(), !post_password_required($post));
    }

    private function refusal(
        AccessRefusal $refusal,
        Form $form,
        \DateTimeImmutable $now,
        \WP_Post $post
    ): FormPageView {
        return match ($refusal) {
            AccessRefusal::PasswordRequired => FormPageView::refused(FormPageView::PASSWORD_REQUIRED, $post),
            AccessRefusal::RoleRequired => FormPageView::refused(FormPageView::ROLE_REQUIRED),
            AccessRefusal::OutsideRegistrationWindow => FormPageView::refused($this->outsideWindow($form, $now)),
            AccessRefusal::EventNotOpen => FormPageView::refused(FormPageView::UNAVAILABLE),
            // Never from `FormHost::refusals()`: the submit's own refusal (S15).
            AccessRefusal::AccountRequired => FormPageView::refused(FormPageView::UNAVAILABLE),
        };
    }

    /** Before the opening instant, from the closing instant on, or, with no opening or closing point, neither. */
    private function outsideWindow(Form $form, \DateTimeImmutable $now): string
    {
        ['opens' => $opens, 'closes' => $closes] = $this->host->window($form);
        if ($opens !== null && $closes !== null) {
            if ($now < $opens) {
                return FormPageView::NOT_STARTED;
            }
            // The window ends exactly at the closing instant (foundation.md: event points are instants).
            if ($now >= $closes) {
                return FormPageView::CLOSED;
            }
        }

        return FormPageView::UNAVAILABLE;
    }

    /**
     * In `optional` mode, the form shows to someone not logged in when the request carries its skip marker
     * (`SignUp::skipUrl()`, the registration page's skip link).
     */
    private function skipped(AccountStep $step, Form $form): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link back from the registration page; no form
        return $step === AccountStep::Offered && SignUp::skipped($form->id, wp_unslash($_GET));
    }

    /** The page the form is on: the singular page's permalink, else the home page. */
    public static function here(): string
    {
        $permalink = is_singular() ? get_permalink() : false;

        return is_string($permalink) ? $permalink : home_url('/');
    }

    /** @throws SystemFailure */
    private function view(Form $form, ValueContext $values, ?AccountStep $step = null): FormPageView
    {
        $definition = $this->forms->definition($form);
        $filled = [];
        if ($step !== null && is_user_logged_in()) {
            // Logged in, the contact email starts with the account's address and stays editable.
            $contact = self::marked($definition, true);
            $email = wp_get_current_user()->user_email;
            if ($contact !== null && $email !== '') {
                $filled[$contact->key] = $email;
            }
        }

        // What the form opens with: the host's stored values as answers (none for a new registration),
        // and what the form shows for them.
        $answers = $this->prefilled($definition, $this->host->prefill($form));
        $availability = $this->forms->availability($definition, $answers, $values);
        $content = FieldMarkup::withPrefill($answers, fn (): string => $this->render(
            $this->blocks($form, $form->version, $availability),
            $values,
            $availability,
            $filled
        ));

        return new FormPageView(null, $content, [
            'formId' => $form->id,
            'version' => $form->version,
            // The submit route (#161's N1), `Rest\FormRoutes::submit()`.
            'url' => rest_url('kalaka-portal/v1/forms/' . $form->id . '/submit'),
            // The re-fetch route, `Rest\FormRoutes::availability()`.
            'availabilityUrl' => rest_url('kalaka-portal/v1/forms/' . $form->id . '/availability'),
            'failure' => (new SystemFailure())->getMessage(),
            // (S12 N3) the REST nonce for a logged-in person, so the routes see the account and not a
            // guest; empty for a guest. The page is never cached (`Api\FormCaching::preventCaching()`).
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            // S15: when that nonce stops working (`Rest\FormRoutes::SESSION_ENDED`), the way back: logging in
            // again, back to this page. [UNAPPROVED #191 T8] the link's text, placeholder: empty, the page shows
            // the address in its place.
            'loginUrl' => is_user_logged_in() ? wp_login_url(self::here()) : '',
            'logIn' => '',
            'triggers' => $availability->triggers,
            // (#167, MP-4) page number → whether it shows; none for a form without pages.
            'pages' => $availability->pages,
        ], null, $this->host->submitToken());
    }

    /** The form's contact email (`$email`) or display name field; null when it has none. */
    private static function marked(FormDefinition $definition, bool $email): ?FormField
    {
        foreach ($definition->fields as $field) {
            if ($email ? $field->isContactEmail() : $field->isDisplayName()) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The answers a form opens with: each of its fields with a stored value, as its type prefills it
     * (`FieldType::prefill()`); a type that refuses, or a value it makes nothing of, opens empty.
     *
     * @param array<string, string> $stored field key → the stored value
     * @return array<string, mixed>
     */
    private function prefilled(FormDefinition $definition, array $stored): array
    {
        $types = Contributions::current()->fieldTypes();
        $answers = [];
        foreach ($definition->fields as $field) {
            $type = $types[$field->blockName] ?? null;
            if ($type === null || !isset($stored[$field->key])) {
                continue;
            }
            $answer = $type->prefill(new FieldTypeInput($stored[$field->key], $field->attributes));
            if ($answer !== null) {
                $answers[$field->key] = $answer;
            }
        }

        return $answers;
    }

    /**
     * The blocks of the form's version as `FormEngine::blocks()` marks them (chains and pages numbered once, by
     * `ChainMarks`), each choice field with option conditions keeping only its offered options.
     *
     * @return list<array<string, mixed>>
     * @throws SystemFailure
     */
    private function blocks(Form $form, int $version, FormAvailability $availability): array
    {
        return array_map(
            fn (array $block): array => $this->offeredOnly($block, $availability),
            $this->forms->blocks($form, $version, $availability)
        );
    }

    /**
     * The blocks rendered in form mode, each field with its error element and any value filled in, then the
     * inline values.
     *
     * @param list<array<string, mixed>|string> $blocks
     * @param array<string, string> $filled field key → the value its control starts with
     */
    private function render(
        array $blocks,
        ValueContext $context,
        FormAvailability $availability,
        array $filled = []
    ): string {
        RenderStack::push($context);
        self::$rendering[] = $availability;
        self::$filled[] = $filled;
        add_filter('render_block', [$this, 'forFormPage'], 10, 2);
        try {
            $html = '';
            foreach ($blocks as $block) {
                if (is_array($block)) {
                    $html .= render_block($block);
                }
            }

            return $this->inline->fill($html, $context);
        } finally {
            remove_filter('render_block', [$this, 'forFormPage'], 10);
            array_pop(self::$rendering);
            array_pop(self::$filled);
            RenderStack::pop();
        }
    }

    /**
     * A choice field block with only the options its field offers, wherever it sits; any other block as it is.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function offeredOnly(array $block, FormAvailability $availability): array
    {
        if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
            $block['innerBlocks'] = array_map(
                fn (mixed $inner): mixed => is_array($inner) ? $this->offeredOnly($inner, $availability) : $inner,
                $block['innerBlocks']
            );
        }
        $key = $block['attrs']['key'] ?? null;
        $options = $block['attrs']['options'] ?? null;
        if (($block['blockName'] ?? null) !== ChoiceFieldType::BLOCK_NAME || !is_string($key) || !is_array($options)) {
            return $block;
        }
        $offered = $availability->offeredOptions($key);
        if ($offered === null) {
            return $block;
        }
        $block['attrs']['options'] = self::offeredOptions($options, $offered);

        return $block;
    }

    /**
     * The options of a choice field block that its field offers, in their order; the form's page and the
     * re-fetch's `parts` (`ChoiceParts`) both keep only these.
     *
     * @param array<array-key, mixed> $options
     * @param list<string> $offered
     * @return list<mixed>
     */
    public static function offeredOptions(array $options, array $offered): array
    {
        return array_values(array_filter(
            $options,
            static fn (mixed $option): bool => is_array($option) && in_array($option['value'] ?? null, $offered, true)
        ));
    }

    /**
     * The current user's roles, as the form's access checks (`FormHost::refusals()`) read them: on the form's
     * page and on its routes (`Rest\FormRoutes`).
     *
     * @return list<string>
     */
    public static function currentRoles(): array
    {
        return array_values(wp_get_current_user()->roles);
    }

    /**
     * The `render_block` filter of a form page. The branch of each chain that shows is shown. The controls
     * of a field that does not show are disabled.
     * Every field has the error element the script writes its message into (`FieldMarkup::errorElement()`,
     * which the core field blocks render themselves); a field block of an extension that renders none gets
     * it at the end of its wrapper, and one that renders no wrapper of its own gets one.
     *
     * @internal
     * @param array<string, mixed> $parsed
     */
    public function forFormPage(string $html, array $parsed): string
    {
        $marks = ChainMarks::of($parsed);
        if ($marks !== null) {
            return $this->shownBranch($html, $marks[0], $marks[1]);
        }
        $name = $parsed['blockName'] ?? null;
        $attributes = is_array($parsed['attrs'] ?? null) ? $parsed['attrs'] : [];
        $key = $attributes['key'] ?? null;
        $isField = is_string($name) && isset(Contributions::current()->fieldTypes()[$name]);
        if (!$isField || !is_string($key) || $html === '') {
            return $html;
        }

        $availability = self::current();
        if ($availability !== null && !$availability->shows($key)) {
            $html = $this->disabled($html);
        }
        $filled = self::$filled === [] ? [] : self::$filled[array_key_last(self::$filled)];
        if (isset($filled[$key])) {
            $html = self::filledIn($html, $key, $filled[$key]);
        }

        $first = new \WP_HTML_Tag_Processor($html);
        $wrapper = $first->next_tag() && $first->get_attribute('data-kalaka-field') === $key
            ? strtolower((string) $first->get_tag())
            : null;
        $error = new \WP_HTML_Tag_Processor($html);
        if ($wrapper !== null && $error->next_tag(['class_name' => 'kalaka-field__error'])) {
            return $html;
        }
        $end = $wrapper === null ? false : strrpos($html, '</' . $wrapper . '>');
        if ($end !== false) {
            return substr($html, 0, $end) . FieldMarkup::errorElement($key) . substr($html, $end);
        }

        return '<div class="kalaka-field" data-kalaka-field="' . esc_attr($key) . '">' . $html
            . FieldMarkup::errorElement($key) . '</div>';
    }

    /**
     * A branch's wrapper (`BranchMarkup`, hidden by default) shown when it is the branch of its chain that
     * shows for the form's current answers, so the page without the script is the form for
     * its prefilled answers (none for a new registration).
     */
    private function shownBranch(string $html, int $chain, int $branch): string
    {
        $availability = self::current();
        if ($availability === null || $availability->heldBranch($chain) !== $branch) {
            return $html;
        }
        // The wrapper is `BranchMarkup`'s own `<div data-kalaka-chain="N" data-kalaka-branch="i" hidden>`.
        $end = strpos($html, '>');
        if ($end === false || !str_starts_with($html, '<div data-kalaka-chain=')) {
            return $html;
        }
        $open = substr($html, 0, $end + 1);

        return str_ends_with($open, ' hidden>')
            ? substr($open, 0, -strlen(' hidden>')) . '>' . substr($html, $end + 1)
            : $html;
    }

    private static function current(): ?FormAvailability
    {
        return self::$rendering === [] ? null : self::$rendering[array_key_last(self::$rendering)];
    }

    /** The field's text control (`name` = its key) starting with the value. */
    private static function filledIn(string $html, string $key, string $value): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        while ($tags->next_tag(['tag_name' => 'INPUT'])) {
            if ($tags->get_attribute('name') === $key) {
                $tags->set_attribute('value', $value);
                break;
            }
        }

        return $tags->get_updated_html();
    }

    private function disabled(string $html): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        while ($tags->next_tag()) {
            if (in_array($tags->get_tag(), ['INPUT', 'SELECT', 'TEXTAREA'], true)) {
                $tags->set_attribute('disabled', true);
            }
        }

        return $tags->get_updated_html();
    }
}
