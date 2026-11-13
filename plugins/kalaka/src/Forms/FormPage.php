<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormAvailability;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Condition\ChainMarks;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Forms\Value\InlineValues;
use Kalaka\Forms\Value\RenderStack;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * The public page of a form (events-and-forms.md, "Forms implementation" and "Conditions"): decides
 * whether the form shows and, if it does, renders its content for its current answers from the form's
 * availability (S76): every branch renders, the held one of each chain shown and the others hidden; the
 * controls of a field that does not show are disabled; a choice field renders only the options it
 * offers. The event values are filled in as the value API fills them in a mail. The script holds no
 * condition: it re-fetches the availability and the re-rendered choice fields (`refetch()`). The chains
 * and pages are numbered once, by `Condition\ChainMarks` (`Forms::blocks()`); this page renumbers nothing.
 * A multi-page form renders every page, each hidden (`PageMarkup`): the script shows the current one.
 */
final class FormPage
{
    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
        private readonly InlineValues $inline = new InlineValues(),
        private readonly RegistrationSubmit $submit = new RegistrationSubmit(),
    ) {
    }

    /** @var list<FormAvailability> the availability of each form page rendering, innermost last */
    private static array $rendering = [];



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
        // `refusals()` returns `event_not_open` for a closed site too, so the load state is read first.
        if (!LoadState::current()->isOpen()) {
            return FormPageView::refused(FormPageView::SYSTEM_FAILURE);
        }

        $form = $formId > 0 ? $this->forms->form($formId) : null;
        $event = $form === null ? null : $this->events->find($form->eventId);
        $post = $form === null ? null : get_post($form->id);
        if ($form === null || $event === null || !$post instanceof \WP_Post) {
            return FormPageView::refused(FormPageView::UNAVAILABLE);
        }

        $now = Runner::current()->clock()->now();
        $refusals = $this->refusals($form, $event, $post, $now);
        if ($refusals !== []) {
            return $this->refusal($refusals[0], $event, $now, $post);
        }

        return $this->view($form, $event);
    }

    /**
     * What the form shows for the answers sent from its page, and its choice fields with option conditions
     * re-rendered for them (S76): `{"availability": FormAvailability::toArray(), "parts": {key: html}}`.
     * The same checks as the page: a form that would not show is refused, and a version that is not the
     * latest is `FormOutdated` (409 `kalaka_form_outdated`). With `$page` (Tovább, (#167, MP-9)) the
     * availability carries that page's errors.
     *
     * [STUB S12] the body of the re-fetch route `POST kalaka-portal/v1/forms/<id>/availability`, which S12
     * builds (registered through `Boundary::route()`). No route is registered here.
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @return array{availability: array<string, mixed>, parts: array<string, string>}
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function refetch(int $formId, int $version, array $answers, ?int $page = null): array
    {
        LoadState::current()->requireOpen();
        $form = $formId > 0 ? $this->forms->form($formId) : null;
        $event = $form === null ? null : $this->events->find($form->eventId);
        $post = $form === null ? null : get_post($form->id);
        if ($form === null || $event === null || !$post instanceof \WP_Post) {
            throw new NotFound();
        }
        if ($this->refusals($form, $event, $post, Runner::current()->clock()->now()) !== []) {
            throw new PermissionDenied();
        }

        $definition = $this->forms->latestDefinition($form, $version);
        $values = new ValueContext(EventValues::of($event));
        $availability = $this->forms->availability($definition, $answers, $values, $page);
        $parts = [];
        foreach ($this->choiceBlocks($this->blocks($form, $version, $availability), $availability) as $key => $block) {
            $parts[$key] = $this->render([$block], $values, $availability);
        }

        return ['availability' => $availability->toArray(), 'parts' => $parts];
    }

    /**
     * Submits the form's answers as the page sends them, `{"version": <form version>, "answers": {key: answer},
     * "token": "<SubmitToken>"}`, through the submit seam (`RegistrationSubmit`), as a guest or as the
     * logged-in account, with the user's roles and whether the form's password was given. The answer is the
     * seam's result as it gives it (`SubmitResult::toArray()`): `{"event", "code", "status", "repeated": false,
     * "after_submit"}`, or for a repeat (the same token and answers) `{"repeated": true, "after_submit"}`.
     * Refusals are thrown as the seam throws them.
     *
     * [STUB S12] the body of the submit route `POST kalaka-portal/v1/forms/<id>/submit` (#161's N1), which
     * S12 builds (registered through `Boundary::route()`). No route is registered here.
     *
     * @param array<array-key, mixed> $body the request's JSON body
     * @return array<string, mixed>
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function submit(int $formId, array $body): array
    {
        $answers = $body['answers'] ?? null;
        $token = $body['token'] ?? null;
        $version = $body['version'] ?? null;
        if (!is_array($answers) || !is_string($token) || !is_int($version)) {
            throw new InvalidInput();
        }
        $post = $formId > 0 ? get_post($formId) : null;
        $user = wp_get_current_user();
        $request = new SubmitRequest(
            $formId,
            $version,
            $answers,
            $token,
            array_values($user->roles),
            $post instanceof \WP_Post && !post_password_required($post),
        );
        $actor = $user->ID > 0 ? new AccountActor($user->ID) : new GuestActor();
        return $this->submit->submit($request, $actor)->toArray();
    }

    /** @return list<AccessRefusal> */
    private function refusals(Form $form, Event $event, \WP_Post $post, \DateTimeImmutable $now): array
    {
        $roles = array_values(wp_get_current_user()->roles);

        return $this->forms->refusals($form, $event, $now, $roles, !post_password_required($post));
    }

    private function refusal(
        AccessRefusal $refusal,
        Event $event,
        \DateTimeImmutable $now,
        \WP_Post $post
    ): FormPageView {
        return match ($refusal) {
            AccessRefusal::PasswordRequired => FormPageView::refused(FormPageView::PASSWORD_REQUIRED, $post),
            AccessRefusal::RoleRequired => FormPageView::refused(FormPageView::ROLE_REQUIRED),
            AccessRefusal::OutsideRegistrationWindow => FormPageView::refused($this->outsideWindow($event, $now)),
            AccessRefusal::EventNotOpen => FormPageView::refused(FormPageView::UNAVAILABLE),
        };
    }

    /** Before the opening instant, from the closing instant on, or, with no opening or closing point, neither. */
    private function outsideWindow(Event $event, \DateTimeImmutable $now): string
    {
        $opens = $event->registrationOpens;
        $closes = $event->registrationCloses;
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

    /** @throws SystemFailure */
    private function view(Form $form, Event $event): FormPageView
    {
        $definition = $this->forms->definition($form);

        // No answers yet: what the form shows before anything is filled in.
        $values = new ValueContext(EventValues::of($event));
        $availability = $this->forms->availability($definition, [], $values);
        $content = $this->render($this->blocks($form, $form->version, $availability), $values, $availability);

        return new FormPageView(null, $content, [
            'formId' => $form->id,
            'version' => $form->version,
            // [STUB S12] the submit route (#161's N1); `submit()` is its body.
            'url' => rest_url('kalaka-portal/v1/forms/' . $form->id . '/submit'),
            // [STUB S12] the re-fetch route; `refetch()` is its body.
            'availabilityUrl' => rest_url('kalaka-portal/v1/forms/' . $form->id . '/availability'),
            'failure' => (new SystemFailure())->getMessage(),
            'triggers' => $availability->triggers,
            // (#167, MP-4) page number → whether it shows; none for a form without pages.
            'pages' => $availability->pages,
        ], null, SubmitToken::generate());
    }

    /**
     * The blocks of the form's version as `Forms::blocks()` marks them (chains and pages numbered once, by
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
     * The blocks rendered in form mode, each field with its error element, then the inline values.
     *
     * @param list<array<string, mixed>|string> $blocks
     */
    private function render(array $blocks, ValueContext $context, FormAvailability $availability): string
    {
        RenderStack::push($context);
        self::$rendering[] = $availability;
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
        $block['attrs']['options'] = array_values(array_filter(
            $options,
            static fn (mixed $option): bool => is_array($option) && in_array($option['value'] ?? null, $offered, true)
        ));

        return $block;
    }

    /**
     * The prepared choice field blocks whose field has option conditions, by key, wherever they sit.
     *
     * @param list<array<string, mixed>|string> $blocks
     * @return array<string, array<string, mixed>>
     */
    private function choiceBlocks(array $blocks, FormAvailability $availability): array
    {
        $found = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $key = $block['attrs']['key'] ?? null;
            if (
                ($block['blockName'] ?? null) === ChoiceFieldType::BLOCK_NAME
                && is_string($key)
                && $availability->offeredOptions($key) !== null
            ) {
                $found[$key] = $block;
            }
            $inner = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : [];
            $found += $this->choiceBlocks($inner, $availability);
        }

        return $found;
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
     * shows for the form's current answers, so the page without the script is the form for no answers.
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
