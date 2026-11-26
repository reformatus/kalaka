<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Rest;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormAvailability;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Engine\AccountMarkup;
use Kalaka\Forms\Engine\ChoiceParts;
use Kalaka\Forms\Engine\FormEngine;
use Kalaka\Forms\Engine\FormPage as FormsPage;
use Kalaka\Forms\Engine\SubmitAttempt;
use Kalaka\Forms\Engine\SubmitGuard;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The public routes of a form's page (S12), in the portal namespace, registered through the error boundary
 * (foundation.md, "Errors"). Both are open to anyone: the form's own access checks (`FormHost::refusals()`)
 * decide, with the current user's roles and whether the form's password was given.
 *
 * - `POST kalaka-portal/v1/forms/{id}/availability` `{version, answers, page?, account?}` →
 *   `{availability, parts, account?}`: what the form shows for the answers (`FormAvailability::toArray()`,
 *   with `page`'s errors) and the choice fields with option conditions re-rendered (`ChoiceParts`). It reads
 *   only; it never writes. The account (S15, owner ruling 2026-11-18) never refuses it: in `mandatory` mode
 *   the account block is `page`'s error instead, while it shows on that page (`AccountMarkup::ERROR_KEY`).
 *   With `account` (the form's page, as the script knows it), a logged-in request's answer carries the
 *   account block re-rendered for it and the contact email's value (`{html, filled}`), after a login in the
 *   block's window; a guest's carries no account part, since only a login makes one.
 * - `POST kalaka-portal/v1/forms/{id}/submit` `{version, answers, token}` → `SubmitResult::toArray()`,
 *   through `SubmitGuard` and what the host's submit does (`FormHost::submit()`; in an event form
 *   `Registrations\Api\RegistrationSubmit`).
 * - `POST kalaka-portal/v1/forms/{id}/draft` `{version, answers, page, claims}` → `{saved: true}` (S74, the
 *   autosave): the host keeps the answers as the logged-in person's draft, or a guest's guest draft, whose
 *   cookie the first save sets (`FormHost::saveDraft()`; a guest's save that creates a draft is rate-limited
 *   like a handoff, `RateLimited` 429). The
 *   body is at most `DRAFT_LIMIT` bytes; only the form's own keys are kept, as sent. `claims` is the
 *   draft's claim count the page opened with: after a claim changed the draft, the save is a
 *   `kalaka_version_conflict` (409).
 * - `POST kalaka-portal/v1/forms/{id}/draft/claim` `{choice}` → `{resolved: true}` (S74): the person's
 *   choice between a link's answers and their own draft (`FormHost::resolveDraftClaim()`). The same choice
 *   again answers the same; a claim that cannot be applied is the host's own refusal (in an event form
 *   `kalaka_draft_claim_refused`, 409).
 *
 * The body is read by hand, not by a REST argument schema, so a malformed one is `InvalidInput` (a
 * `kalaka_` code the page shows), not WordPress's `rest_invalid_param`.
 * Every answer of all four routes, refusals included, carries `Cache-Control: no-store` (`noStore()`). The
 * submit is rate-limited (`SubmitGuard`, S14): too many attempts are `RateLimited` (429, `Retry-After`).
 * The current user is the one WordPress authenticated for the request; with cookie authentication that
 * needs the `wp_rest` nonce, else the request is a guest's. A nonce WordPress no longer accepts (the
 * session ended: say the password was set on another device, which logs out every session) is refused
 * before any route runs, as `rest_cookie_invalid_nonce`; for these routes that answer becomes
 * `kalaka_session_ended` (`sessionEnded()`), which the page shows with a link to log in again.
 */
final class FormRoutes
{
    public const NAMESPACE = 'kalaka-portal/v1';

    /** The refusal of a request whose session ended: logging in again is the way on. */
    public const SESSION_ENDED = 'kalaka_session_ended';

    // #191 T8 (the owner's ruling 2026-11-18, shortened).
    public const SESSION_ENDED_TEXT
        = 'Ebben a böngészőben már nem vagy bejelentkezve. Jelentkezz be újra.';

    /** The largest autosave body, in bytes (S74). */
    public const DRAFT_LIMIT = 65536;

    /** The routes' path prefix, as `WP_REST_Request::get_route()` gives it. */
    private const PREFIX = '/' . self::NAMESPACE . '/forms/';

    private readonly FormEngine $forms;

    private readonly ChoiceParts $parts;

    public function __construct(
        private readonly FormHost $host,
        ?FormEngine $engine = null,
        private readonly SubmitGuard $guard = new SubmitGuard(),
    ) {
        $this->forms = $engine ?? new FormEngine($host);
        $this->parts = new ChoiceParts($this->forms);
    }

    /** On `rest_api_init`. */
    public function register(): void
    {
        Boundary::route(self::NAMESPACE, '/forms/(?P<id>\d+)/availability', [
            'methods' => 'POST',
            'callback' => [$this, 'availability'],
            'permission_callback' => '__return_true',
        ]);
        Boundary::route(self::NAMESPACE, '/forms/(?P<id>\d+)/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'submit'],
            'permission_callback' => '__return_true',
        ]);
        Boundary::route(self::NAMESPACE, '/forms/(?P<id>\d+)/draft', [
            'methods' => 'POST',
            'callback' => [$this, 'saveDraft'],
            'permission_callback' => '__return_true',
        ]);
        Boundary::route(self::NAMESPACE, '/forms/(?P<id>\d+)/draft/claim', [
            'methods' => 'POST',
            'callback' => [$this, 'resolveDraftClaim'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * On `rest_request_after_callbacks`: every answer of these routes, refusals included, as a response
     * with `Cache-Control: no-store`. A `WP_Error` (what the boundary makes of a refusal, or a body that is
     * not JSON) becomes its response here, as the server would make it; a rate limit's (`RateLimited`) gets
     * its `Retry-After`. This filter runs for every request
     * that reached a route, `rest_do_request()` included, unlike `rest_post_dispatch`.
     *
     * @internal
     */
    public static function noStore(mixed $response, mixed $handler, mixed $request): mixed
    {
        unset($handler);
        if (!$request instanceof \WP_REST_Request || !str_starts_with($request->get_route(), self::PREFIX)) {
            return $response;
        }
        $retryAfter = null;
        if ($response instanceof \WP_Error) {
            /** @var mixed $data */
            $data = $response->get_error_data();
            $retryAfter = is_array($data) && is_int($data['retry_after'] ?? null) ? $data['retry_after'] : null;
        }
        $response = $response instanceof \WP_Error
            ? rest_convert_error_to_response($response)
            : rest_ensure_response($response);
        $response->header('Cache-Control', FormCaching::CACHE_CONTROL);
        if ($retryAfter !== null) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * On `rest_post_dispatch`: for these routes, WordPress's refusal of a stale `wp_rest` nonce
     * (`rest_cookie_invalid_nonce`, made before any route runs, so neither the boundary nor `noStore()` sees
     * it) becomes `kalaka_session_ended` (401), with `Cache-Control: no-store`. A retry never helps: only
     * logging in again does. Anything else passes unchanged.
     *
     * @internal
     */
    public static function sessionEnded(mixed $response, mixed $server, mixed $request): mixed
    {
        unset($server);
        if (
            !$response instanceof \WP_REST_Response
            || !$request instanceof \WP_REST_Request
            || !str_starts_with($request->get_route(), self::PREFIX)
            || $response->as_error()?->get_error_code() !== 'rest_cookie_invalid_nonce'
        ) {
            return $response;
        }
        $ended = rest_convert_error_to_response(
            new \WP_Error(self::SESSION_ENDED, self::SESSION_ENDED_TEXT, ['status' => 401])
        );
        $ended->header('Cache-Control', FormCaching::CACHE_CONTROL);

        return $ended;
    }

    /**
     * The re-fetch: the same checks as the form's page, then what shows for the answers. A form that would
     * not show is `FormRefused` with its refusal codes; a version that is not the latest is
     * `FormOutdated`.
     *
     * @return array{availability: array<string, mixed>, parts: array<string, string>|\stdClass}
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function availability(\WP_REST_Request $request): array
    {
        LoadState::current()->requireOpen();
        $body = $this->body($request);
        $page = $body['page'] ?? null;
        if ($page !== null && !is_int($page)) {
            $error = new InvalidInput();
            throw $error;
        }

        [$form, $values, $all] = $this->lookup($request);
        $refusals = FormsPage::withoutAccount($all);
        if ($refusals !== []) {
            $error = new FormRefused($refusals);
            throw $error;
        }

        $definition = $this->forms->latestDefinition($form, $this->version($body));
        $availability = $this->forms->availability($definition, $this->answers($body), $values, $page);
        $guest = in_array(AccessRefusal::AccountRequired, $all, true);
        if ($page !== null && $guest && self::accountShows($definition, $availability, $page)) {
            // In `mandatory` mode the account block validates like a required field on its page.
            $availability = $availability->withError(AccountMarkup::ERROR_KEY, AccountMarkup::ERROR);
        }
        $parts = $this->parts->of($form, $definition->version, $availability, $values);
        $answer = ['availability' => $availability->toArray(), 'parts' => $parts === [] ? new \stdClass() : $parts];
        // The account part is only meaningful after a login: it is resolved for a logged-in request only, so a
        // guest's re-fetch never runs the form's page scan (`SignUp::page()`, a site-wide content read) on this
        // open route. With no account step it answers nothing either.
        $step = $this->host->accountStep($form);
        $here = $body['account'] ?? null;
        if ($step !== null && is_string($here) && is_user_logged_in()) {
            $answer['account'] = $this->account($step, $form, $definition, $here);
        }

        return $answer;
    }

    /**
     * Whether the form's account block shows on the page: it sits on that page, the page shows and is not
     * terminal, and every branch around it is the held one of its chain (validation follows what is shown).
     */
    private static function accountShows(FormDefinition $definition, FormAvailability $availability, int $page): bool
    {
        $place = $definition->account;
        if ($place === null || $place->page !== $page || ($availability->pages[$page] ?? false) !== true) {
            return false;
        }
        if ($definition->pages[$page]->terminal) {
            return false;
        }
        foreach ($place->branches as $branch) {
            if ($availability->heldBranch($branch->chain) !== $branch->index) {
                return false;
            }
        }

        return true;
    }

    /**
     * The account block re-rendered for the current visitor, its logout returning to the form's page found
     * on the server (`SignUp::page()`; `$requested` only picks among the pages that place the form), and,
     * for someone logged in, the values the host's prefill gives (the contact email's), which the script fills in
     * only while the field is empty.
     *
     * @return array{html: string, filled: array<string, string>|\stdClass}
     */
    private function account(AccountStep $step, Form $form, FormDefinition $definition, string $requested): array
    {
        $page = SignUp::page($form->id, $requested) ?? home_url('/');
        // The host's values the form opens with (the account's address in the contact email), as the page's.
        $stored = $this->host->prefill($form);
        $filled = array_intersect_key($stored, array_flip(array_map(
            static fn (FormField $field): string => $field->key,
            $definition->fields
        )));

        return ['html' => AccountMarkup::html($step, $page), 'filled' => $filled === [] ? new \stdClass() : $filled];
    }

    /**
     * The submit: `SubmitGuard`, then the host's submit as the current user or a guest. A repeat (the same
     * token and answers) answers `{repeated: true, after_submit}`.
     *
     * @return array<string, mixed>
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function submit(\WP_REST_Request $request): array
    {
        LoadState::current()->requireOpen();
        $body = $this->body($request);
        $token = $body['token'] ?? null;
        if (!is_string($token)) {
            $error = new InvalidInput();
            throw $error;
        }
        $formId = $this->formId($request);
        $version = $this->version($body);
        $answers = $this->answers($body);
        $actor = $this->actor();
        $this->guard->check(new SubmitAttempt($formId, $version, $answers, $token, $actor));

        $post = get_post($formId);

        return $this->host->submit(
            $formId,
            $version,
            $answers,
            $token,
            FormsPage::currentRoles(),
            $post instanceof \WP_Post && !post_password_required($post),
            $actor
        );
    }

    /**
     * The autosave (S74): the form's own keys of the answers, as sent, kept as the logged-in person's draft
     * while the form can be used; a guest's go into the guest draft under their cookie, which the first save
     * gives (owner ruling 2026-11-26). The account never refuses it, as it never refuses the page.
     *
     * @return array{saved: true}
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function saveDraft(\WP_REST_Request $request): array
    {
        LoadState::current()->requireOpen();
        if (strlen((string) $request->get_body()) > self::DRAFT_LIMIT) {
            $error = new InvalidInput();
            throw $error;
        }
        $body = $this->body($request);
        $page = $body['page'] ?? 0;
        $claims = $body['claims'] ?? null;
        if (!is_int($page) || $page < 0 || !is_int($claims) || $claims < 0) {
            $error = new InvalidInput();
            throw $error;
        }
        $form = $this->usableForm($request);
        $definition = $this->forms->latestDefinition($form, $this->version($body));
        $answers = [];
        foreach ($this->answers($body) as $key => $answer) {
            if ($definition->field((string) $key) !== null) {
                $answers[(string) $key] = $answer;
            }
        }
        $answerSet = new AnswerSet($answers);
        $this->host->saveDraft($form, $definition->version, $answerSet, $page, $claims, $this->actor());

        return ['saved' => true];
    }

    /**
     * The person's choice between a link's answers and their own draft (S74).
     *
     * @return array{resolved: true}
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function resolveDraftClaim(\WP_REST_Request $request): array
    {
        LoadState::current()->requireOpen();
        $choice = DraftClaimChoice::tryFrom((string) ($this->body($request)['choice'] ?? ''));
        if ($choice === null) {
            $error = new InvalidInput();
            throw $error;
        }
        $this->host->resolveDraftClaim($this->usableForm($request), $choice, $this->actor());

        return ['resolved' => true];
    }

    /**
     * The form of the route, when it can be used now: the same checks as its page.
     *
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    private function usableForm(\WP_REST_Request $request): Form
    {
        [$form, , $all] = $this->lookup($request);
        $refusals = FormsPage::withoutAccount($all);
        if ($refusals !== []) {
            $error = new FormRefused($refusals);
            throw $error;
        }

        return $form;
    }

    /**
     * The form of the route with its facts and every refusal that keeps it from being used now
     * (`FormPage::refusalsOf()`).
     *
     * @return array{Form, ValueContext, list<AccessRefusal>}
     * @throws NotFound when the form, what it belongs to or its post is gone
     */
    private function lookup(\WP_REST_Request $request): array
    {
        $form = $this->forms->form($this->formId($request)) ?? throw new NotFound();
        $values = $this->host->facts($form) ?? throw new NotFound();
        $post = get_post($form->id);
        if (!$post instanceof \WP_Post) {
            $error = new NotFound();
            throw $error;
        }

        return [$form, $values, FormsPage::refusalsOf($this->host, $form, $post, Runner::current()->clock()->now())];
    }

    /**
     * The JSON body as an object.
     *
     * @return array<array-key, mixed>
     * @throws InvalidInput
     */
    private function body(\WP_REST_Request $request): array
    {
        // A JSON scalar decodes too, whatever the declared type says.
        /** @var mixed $body */
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $error = new InvalidInput();
            throw $error;
        }

        return $body;
    }

    private function formId(\WP_REST_Request $request): int
    {
        // The route's pattern admits digits only.
        return (int) $request->get_url_params()['id'];
    }

    /**
     * @param array<array-key, mixed> $body
     * @throws InvalidInput
     */
    private function version(array $body): int
    {
        $version = $body['version'] ?? null;
        if (!is_int($version)) {
            $error = new InvalidInput();
            throw $error;
        }

        return $version;
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     * @throws InvalidInput
     */
    private function answers(array $body): array
    {
        $answers = $body['answers'] ?? null;
        if (!is_array($answers)) {
            $error = new InvalidInput();
            throw $error;
        }

        return $answers;
    }

    /** The logged-in user as an account, anyone else as a guest. */
    private function actor(): Actor
    {
        $user = wp_get_current_user();

        return $user->ID > 0 ? new AccountActor($user->ID) : new GuestActor();
    }
}
