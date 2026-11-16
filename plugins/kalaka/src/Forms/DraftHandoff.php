<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\DraftClaim;
use Kalaka\Registrations\Api\DraftClaimOutcome;
use Kalaka\Registrations\Api\Drafts;

/**
 * A link's or the quiz's answers handed to a registration form, and their claim (S74, owner ruling
 * 2026-11-14), on WordPress's `wp` action: after the query, so the requested page is known, and before
 * `template_redirect`, where a page is cached or redirected (S15's sign-up step). At `init` the page is not
 * known yet.
 *
 * - **The handoff.** A request to a page that places exactly one registration form, carrying `kalaka[<key>]`
 *   (the quiz's POST body, or a static GET link; S73's contract), while the form can be used: each value is
 *   checked alone with its field's type, and an unknown key or a value the type does not accept is dropped,
 *   silently (`Forms::accepted()`). What is left is kept as a guest draft under a new claim token, set in
 *   the cookie, and the request is answered with a 303 to the page without `kalaka`, never cached. Nothing
 *   of the request is logged.
 * - **The claim.** A logged-in request to such a page whose cookie names a guest draft of its form claims
 *   it: it becomes the person's draft, and the cookie is cleared. With a draft of their own, it waits for
 *   their choice (the popup) and the cookie stays, but only when the link sets a value the draft does not
 *   hold; when it changes nothing, the guest draft is dropped (keep_draft) and the cookie is cleared. A
 *   guest draft that is gone clears the cookie.
 *
 * Any other page view (no `kalaka`, and no claim cookie of a logged-in visitor) returns before any read.
 * [UNAPPROVED N6] [UNAPPROVED N7]
 */
final class DraftHandoff
{
    /** The request parameter of a prefill: `kalaka[<field key>]`. */
    public const PARAMETER = 'kalaka';

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly Drafts $drafts = new Drafts(),
        private readonly EventFormHost $host = new EventFormHost(),
    ) {
    }

    /** @internal the `wp` callback */
    public function onWp(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (!is_singular() || !LoadState::current()->isOpen()) {
            return;
        }
        $post = get_queried_object();
        if (!$post instanceof \WP_Post) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- a cross-site handoff (quiz, link): no nonce
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- every value is checked by its field type
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $prefill = $method === 'POST' ? ($_POST[self::PARAMETER] ?? null) : ($_GET[self::PARAMETER] ?? null);
        $prefill = is_array($prefill) ? wp_unslash($prefill) : null;
        $url = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        // phpcs:enable
        $user = get_current_user_id();
        if (!self::hasWork($prefill, $user, DraftClaim::current())) {
            return;
        }
        $redirect = $this->handle($post, $prefill, $user > 0 ? new AccountActor($user) : new GuestActor());
        if ($redirect) {
            FormCaching::preventCaching();
            wp_safe_redirect(remove_query_arg(self::PARAMETER, $url), 303);
            exit;
        }
    }

    /**
     * Whether a page view has anything to do here: a prefill, or a logged-in visitor holding a claim cookie.
     * Every other page view of the site stops before any read.
     *
     * @param ?array<array-key, mixed> $prefill
     */
    public static function hasWork(?array $prefill, int $userId, ?string $claimToken): bool
    {
        return $prefill !== null || ($userId > 0 && $claimToken !== null);
    }

    /**
     * The handoff or the claim for the page; whether the request is answered with the 303. A failure is
     * logged without the request's values, and the page then shows as without them.
     *
     * @param ?array<array-key, mixed> $prefill the `kalaka` parameter, unslashed
     */
    public function handle(\WP_Post $post, ?array $prefill, Actor $actor): bool
    {
        $redirect = false;
        Boundary::background('draft handoff', function () use ($post, $prefill, $actor, &$redirect): void {
            $form = $this->registrationForm($post);
            if ($form === null) {
                return;
            }
            if ($prefill !== null) {
                $this->handOver($form, $prefill, $actor);
                $redirect = true;

                return;
            }
            $this->claim($form, $actor);
        });

        return $redirect;
    }

    /** @param array<array-key, mixed> $prefill */
    private function handOver(Form $form, array $prefill, Actor $actor): void
    {
        $definition = $this->forms->definition($form);
        $answers = [];
        foreach ($prefill as $key => $answer) {
            $answers[(string) $key] = $answer;
        }
        $accepted = $this->forms->accepted($definition, new AnswerSet($answers));
        if ($accepted->isEmpty()) {
            return;
        }
        $token = $this->drafts->handOver(
            $form->eventId,
            $form->id,
            $definition->version,
            $accepted,
            DraftClaim::current(),
            $actor
        );
        $expires = Runner::current()->clock()->now()->modify('+' . DraftClaim::LIFETIME_DAYS . ' days');
        DraftClaim::setCookie($token, $expires);
        // A logged-in person claims it at once, on the page the 303 leads to.
    }

    private function claim(Form $form, Actor $actor): void
    {
        $token = DraftClaim::current();
        if ($token === null || !$actor instanceof AccountActor) {
            return;
        }
        $outcome = $this->drafts->claim($form->id, $token, $actor);
        $done = [DraftClaimOutcome::Claimed, DraftClaimOutcome::Settled, DraftClaimOutcome::Gone];
        if (in_array($outcome, $done, true)) {
            DraftClaim::setCookie(null);
        }
    }

    /** The one registration form the page places, when it can be used now; null otherwise. */
    private function registrationForm(\WP_Post $post): ?Form
    {
        if (!FormCaching::placesForm($post->post_content)) {
            return null;
        }
        $found = null;
        foreach (PlacedForms::in($post->post_content) as $id) {
            $form = $this->forms->form($id);
            if ($form === null || $form->placement !== FormPlacement::Registration) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $form;
        }
        if ($found === null) {
            return null;
        }
        $refusals = $this->host->refusals(
            $found,
            Runner::current()->clock()->now(),
            array_values(wp_get_current_user()->roles),
            !post_password_required($post)
        );

        return $refusals === [] ? $found : null;
    }
}
