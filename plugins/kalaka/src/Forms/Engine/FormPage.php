<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormAvailability;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormDraft;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Engine\Condition\ChainMarks;
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
 * A form whose host gives an account step (`FormHost::accountStep()`, S15) shows to everyone (owner ruling
 * 2026-11-18): its account block (`Api\SignUp::BLOCK`, `AccountMarkup`) offers the login in a window of its
 * own, and in `mandatory` mode only the submit refuses someone not logged in (`account_required`). For
 * someone logged in, the form's contact email opens with the account's address and stays editable (the host's
 * `FormHost::prefill()`, like any other value a form opens with).
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

    /** @var list<array{AccountStep, string}|null> the account step and the page of each rendering; null for none */
    private static array $accounts = [];

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

        return $this->view($form, $facts, $this->host->accountStep($form));
    }

    /**
     * What keeps the form from showing: the host's refusals without the account (owner ruling 2026-11-18). In
     * `mandatory` mode the whole form shows to someone not logged in; only its submit refuses them.
     *
     * @return list<AccessRefusal>
     */
    private function refusals(Form $form, \WP_Post $post, \DateTimeImmutable $now): array
    {
        return self::withoutAccount(self::refusalsOf($this->host, $form, $post, $now));
    }

    /**
     * The one "can this form be used now" check, for the form's page, its routes and the draft handoff: the
     * host's refusals for the current user's roles and whether the post's password was given.
     *
     * @return list<AccessRefusal>
     */
    public static function refusalsOf(FormHost $host, Form $form, \WP_Post $post, \DateTimeImmutable $now): array
    {
        return $host->refusals($form, $now, self::currentRoles(), !post_password_required($post));
    }

    /**
     * The refusals without `AccountRequired`: the form's page and its re-fetch show the form to someone not
     * logged in; the submit keeps the refusal.
     *
     * @param list<AccessRefusal> $refusals
     * @return list<AccessRefusal>
     */
    public static function withoutAccount(array $refusals): array
    {
        return array_values(array_filter(
            $refusals,
            static fn (AccessRefusal $refusal): bool => $refusal !== AccessRefusal::AccountRequired
        ));
    }

    private function refusal(
        AccessRefusal $refusal,
        Form $form,
        \DateTimeImmutable $now,
        \WP_Post $post
    ): FormPageView {
        return match ($refusal) {
            AccessRefusal::PasswordRequired => FormPageView::refused(FormPageView::PASSWORD_REQUIRED, $post),
            // Someone not logged in may hold the role: the refusal links to WordPress's login, back to the page.
            AccessRefusal::RoleRequired => FormPageView::refused(
                FormPageView::ROLE_REQUIRED,
                null,
                is_user_logged_in() ? '' : wp_login_url(self::here())
            ),
            AccessRefusal::OutsideRegistrationWindow => FormPageView::refused($this->outsideWindow($form, $now)),
            // The account never refuses the page (`withoutAccount()`); listed for the match only.
            AccessRefusal::EventNotOpen, AccessRefusal::AccountRequired => FormPageView::refused(
                FormPageView::UNAVAILABLE
            ),
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

    /** The page the form is on: the singular page's permalink, else the home page. */
    public static function here(): string
    {
        $permalink = is_singular() ? get_permalink() : false;

        return is_string($permalink) ? $permalink : home_url('/');
    }

    /** @throws SystemFailure */
    private function view(Form $form, ValueContext $values, ?AccountStep $step = null): FormPageView
    {
        $definition = $this->forms->definition($form, null, true);

        // What the form opens with: the host's stored values (none for a new registration) with the person's
        // draft over them (S74), as answers, and what the form shows for them. A draft's text its type finds an
        // error in opens as typed; any other draft answer this version's types do not accept opens empty.
        $draft = $this->host->draft($form);
        $answers = $this->prefilled(
            $definition,
            array_replace($this->host->prefill($form), $this->forms->acceptedValues($definition, $draft->answers))
        ) + $this->forms->rejectedText($definition, $draft->answers);
        $availability = $this->forms->availability($definition, $answers, $values);
        $here = self::here();
        $content = FieldMarkup::withPrefill($answers, fn (): string => $this->render(
            $this->blocks($form, $form->version, $availability),
            $values,
            $availability,
            $step === null ? null : [$step, $here]
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
            // S15: when that nonce stops working (`Rest\FormRoutes::SESSION_ENDED`), the way back:
            // logging in again, back to this page, and the link's text (#191 T8, owner 2026-11-17). A form with an
            // account step logs in again through its account block's window (`account`) instead.
            'loginUrl' => is_user_logged_in() && $step === null ? wp_login_url($here) : '',
            'logIn' => 'Bejelentkezés',
            // S15 (owner ruling 2026-11-18): the account block's window, for a form with an account step.
            'account' => $step === null ? null : self::account($form, $here),
            'triggers' => $availability->triggers,
            // The answers the page opened with, of the triggers that show: the script re-fetches at start-up only
            // when the browser restored others (#193's review).
            'opened' => self::opened($answers, $availability),
            // (#167, MP-4) page number → whether it shows; none for a form without pages.
            'pages' => $availability->pages,
        ] + self::draftData($form, $draft), null, $this->host->submitToken());
    }

    /**
     * The answers the page opened with of the triggers that show, which the script compares at start-up with
     * what the browser holds. A trigger that does not show is not sent, and the script does not read it; of
     * a choice, only the options it offers, the ones its markup renders.
     *
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private static function opened(array $answers, FormAvailability $availability): array
    {
        $opened = [];
        foreach ($availability->triggers as $key) {
            if (!$availability->shows($key) || !array_key_exists($key, $answers)) {
                continue;
            }
            $answer = $answers[$key];
            $offered = $availability->offeredOptions($key);
            if ($offered !== null && is_array($answer)) {
                $answer = array_values(array_intersect($answer, $offered));
            } elseif ($offered !== null && is_string($answer) && !in_array($answer, $offered, true)) {
                continue;
            }
            $opened[$key] = $answer;
        }

        return $opened;
    }

    /**
     * The script's part of the draft (S74): the autosave route while the person's (or a guest's) draft is
     * saved, and the claim route while a link's answers wait for the person's choice against the draft.
     * Nothing otherwise.
     *
     * @return array<string, mixed>
     */
    private static function draftData(Form $form, FormDraft $draft): array
    {
        $data = [];
        if ($draft->autosave) {
            // `Rest\FormRoutes::saveDraft()`.
            $data['draftUrl'] = rest_url('kalaka-portal/v1/forms/' . $form->id . '/draft');
            // The draft's claim count, sent back with every save: a save from before a claim is refused.
            $data['draftClaims'] = $draft->claims;
            if ($draft->guestStart) {
                // A guest with no guest draft: the first answer is saved at once, which gives the cookie.
                $data['draftStart'] = true;
            }
        }
        if ($draft->claimConflict) {
            // `Rest\FormRoutes::resolveDraftClaim()`; the choice is the person's (the popup, UX).
            $data['draftClaimUrl'] = rest_url('kalaka-portal/v1/forms/' . $form->id . '/draft/claim');
            // How many fields the link's answers would change (pass 2): the merge choice's line.
            $data['draftClaimChanges'] = $draft->linkChanges;
            // The popup's texts (`Blocks/form/claim.js`), the count in merge's line.
            $data['draftClaimTexts'] = DraftClaimTexts::popup($draft->linkChanges, $draft->guestChoice);
        }
        if ($draft->claimNotice !== null) {
            // The choice that settled the claim, on the load after it, and its one-line notice, shown once
            // above the form by the form's script.
            $data['draftClaimNotice'] = $draft->claimNotice->value;
            $data['draftClaimNoticeText'] = DraftClaimTexts::notice($draft->claimNotice);
        }

        return $data;
    }

    /**
     * What the page's script needs for the account block's window: the marked login page
     * (`SignUp::url()`, returning through the return page), where it reads the REST nonce once someone has
     * logged in (WordPress's own `admin-ajax.php?action=rest-nonce`, which answers only a logged-in
     * request), the form's page, which the re-fetch's re-rendered block returns to on logout, and the block's
     * error in `mandatory` mode.
     *
     * @return array{url: string, nonceUrl: string, page: string, loggedIn: bool, error: string}
     */
    private static function account(Form $form, string $here): array
    {
        return [
            'url' => SignUp::url($form->id, $here),
            'nonceUrl' => admin_url('admin-ajax.php?action=rest-nonce'),
            'page' => $here,
            'loggedIn' => is_user_logged_in(),
            'error' => AccountMarkup::ERROR,
        ];
    }

    /**
     * The account block of the form being rendered (`Blocks/account/render.php`): its markup for the current
     * visitor, or nothing outside a form page or for a form without an account step (an additional form, or
     * an event whose account mode is `off`).
     */
    public static function accountBlock(): string
    {
        $account = self::$accounts === [] ? null : self::$accounts[array_key_last(self::$accounts)];

        return $account === null ? '' : AccountMarkup::html(...$account);
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
     * @param array{AccountStep, string}|null $account the account step and the form's page; null for none
     */
    private function render(
        array $blocks,
        ValueContext $context,
        FormAvailability $availability,
        ?array $account = null
    ): string {
        RenderStack::push($context);
        self::$rendering[] = $availability;
        self::$accounts[] = $account;
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
            array_pop(self::$accounts);
            RenderStack::pop();
        }
    }

    /**
     * A choice or request field block with only the options its field offers, wherever it sits; any other
     * block as it is.
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
        if (!ChoiceParts::offersOptions($block['blockName'] ?? null) || !is_string($key) || !is_array($options)) {
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
