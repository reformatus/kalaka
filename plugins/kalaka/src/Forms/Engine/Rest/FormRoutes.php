<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Rest;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Engine\ChoiceParts;
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
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;

/**
 * The public routes of a form's page (S12), in the portal namespace, registered through the error boundary
 * (foundation.md, "Errors"). Both are open to anyone: the form's own access checks (`Forms::refusals()`)
 * decide, with the current user's roles and whether the form's password was given.
 *
 * - `POST kalaka-portal/v1/forms/{id}/availability` `{version, answers, page?}` → `{availability, parts}`:
 *   what the form shows for the answers (`FormAvailability::toArray()`, with `page`'s errors) and the
 *   choice fields with option conditions re-rendered (`ChoiceParts`). It reads only; it never writes.
 * - `POST kalaka-portal/v1/forms/{id}/submit` `{version, answers, token}` → `SubmitResult::toArray()`,
 *   through `SubmitGuard` and the submit seam (`RegistrationSubmit`).
 *
 * The body is read by hand, not by a REST argument schema, so a malformed one is `InvalidInput` (a
 * `kalaka_` code the page shows), not WordPress's `rest_invalid_param`.
 * Every answer of both routes, refusals included, carries `Cache-Control: no-store` (`noStore()`).
 * The current user is the one WordPress authenticated for the request; with cookie authentication that
 * needs the `wp_rest` nonce, else the request is a guest's.
 */
final class FormRoutes
{
    public const NAMESPACE = 'kalaka-portal/v1';

    /** The routes' path prefix, as `WP_REST_Request::get_route()` gives it. */
    private const PREFIX = '/' . self::NAMESPACE . '/forms/';

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
        private readonly ChoiceParts $parts = new ChoiceParts(),
        private readonly SubmitGuard $guard = new SubmitGuard(),
        private readonly ?RegistrationSubmit $submit = null,
    ) {
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
    }

    /**
     * On `rest_request_after_callbacks`: every answer of these routes, refusals included, as a response
     * with `Cache-Control: no-store`. A `WP_Error` (what the boundary makes of a refusal, or a body that is
     * not JSON) becomes its response here, as the server would make it. This filter runs for every request
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
        $response = $response instanceof \WP_Error
            ? rest_convert_error_to_response($response)
            : rest_ensure_response($response);
        $response->header('Cache-Control', FormCaching::CACHE_CONTROL);

        return $response;
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

        $form = $this->forms->form($this->formId($request)) ?? throw new NotFound();
        $event = $this->events->find($form->eventId) ?? throw new NotFound();
        $post = get_post($form->id);
        if (!$post instanceof \WP_Post) {
            $error = new NotFound();
            throw $error;
        }
        $refusals = $this->forms->refusals(
            $form,
            $event,
            Runner::current()->clock()->now(),
            FormsPage::currentRoles(),
            !post_password_required($post)
        );
        if ($refusals !== []) {
            $error = new FormRefused($refusals);
            throw $error;
        }

        $definition = $this->forms->latestDefinition($form, $this->version($body));
        $values = new ValueContext(EventValues::of($event));
        $availability = $this->forms->availability($definition, $this->answers($body), $values, $page);
        $parts = $this->parts->of($form, $definition->version, $availability, $values);

        return ['availability' => $availability->toArray(), 'parts' => $parts === [] ? new \stdClass() : $parts];
    }

    /**
     * The submit: `SubmitGuard`, then the submit seam as the current user or a guest. A repeat (the same
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
        $submitRequest = new SubmitRequest(
            $formId,
            $version,
            $answers,
            $token,
            FormsPage::currentRoles(),
            $post instanceof \WP_Post && !post_password_required($post),
        );

        return ($this->submit ?? new RegistrationSubmit())->submit($submitRequest, $actor)->toArray();
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
