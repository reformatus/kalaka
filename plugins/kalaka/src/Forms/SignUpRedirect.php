<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Engine\FormEngine;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The sign-up redirect (S15; owner ruling 2026-11-14): on `template_redirect`, someone not logged in who
 * opens a singular page placing a registration form whose event's account mode is `mandatory` or
 * `optional` is sent to WordPress's own login page (`SignUp::url()`, OP5; it links to the registration page),
 * returning to the page. In `optional` mode the page's skip marker (`SignUp::skipUrl()`, the skip link of the
 * login and registration pages) keeps them on the page. A form refused for a reason logging in cannot change
 * (closed, not open, the page's password) is not redirected: the page shows that refusal. The form is found
 * in the page's content as the block `kalaka/form` or the `[kalaka_form]` shortcode; a form placed where this
 * cannot see it shows the link to sign up instead (`FormPage`).
 *
 * A quiz's POSTed prefill is S74's: its `init` handler stores it and answers with a 303 to the form's page,
 * before this runs, so the redirect only ever sees a GET with nothing to keep.
 */
final class SignUpRedirect
{
    public function __construct(private readonly EventFormHost $host = new EventFormHost())
    {
    }

    /**
     * Where to send the visitor: WordPress's login page (OP5) for the first form on the page that needs
     * an account; null for none (logged in, no such form, skipped, or refused for another reason).
     *
     * @param array<string, mixed> $query the request's query parameters
     */
    public function target(\WP_Post $post, bool $loggedIn, array $query, string $return): ?string
    {
        if ($loggedIn || !FormCaching::placesForm($post->post_content)) {
            return null;
        }
        $engine = new FormEngine($this->host);
        foreach (FormCaching::formIds($post->post_content) as $formId) {
            $form = $engine->form($formId);
            $step = $form === null ? null : $this->host->accountStep($form);
            if ($step === null || ($step === AccountStep::Offered && SignUp::skipped($formId, $query))) {
                continue;
            }
            if ($this->refusedOtherwise($form, $post)) {
                // A form that is closed, password-protected or role-gated says so first (#191 review C1-1).
                continue;
            }

            return SignUp::url($formId, $return);
        }

        return null;
    }

    /**
     * Whether the form refuses this visitor for a reason logging in cannot change (the event not open, outside
     * the registration window, the page's password): then the page shows that refusal, and signing up first
     * would be a detour. A required role is not one: logging in may bring it.
     */
    private function refusedOtherwise(Form $form, \WP_Post $post): bool
    {
        $refusals = $this->host->refusals(
            $form,
            Runner::current()->clock()->now(),
            [],
            !post_password_required($post)
        );

        $unchanged = static fn (AccessRefusal $refusal): bool
            => $refusal !== AccessRefusal::AccountRequired && $refusal !== AccessRefusal::RoleRequired;

        return array_filter($refusals, $unchanged) !== [];
    }

    /** @internal the `template_redirect` callback */
    public function redirect(): void
    {
        $post = is_singular() ? get_queried_object() : null;
        if (!$post instanceof \WP_Post) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link back from sign-up
        $target = $this->target($post, is_user_logged_in(), wp_unslash($_GET), FormPage::here());
        if ($target !== null) {
            FormCaching::preventCaching();
            wp_safe_redirect($target, 302);
            exit;
        }
    }
}
