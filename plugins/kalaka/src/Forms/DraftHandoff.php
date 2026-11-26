<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\RateLimited;
use Kalaka\Forms\Engine\FormPage as FormsPage;
use Kalaka\Forms\Engine\SubmitGuard;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\DraftClaim;
use Kalaka\Registrations\Api\DraftClaimOutcome;

/**
 * A link's or the quiz's answers handed to a registration form, and their claim (S74, owner ruling
 * 2026-11-14), on WordPress's `wp` action: after the query, so the requested page is known, and before
 * `template_redirect`, where a page is cached or redirected (S15's sign-up step). At `init` the page is not
 * known yet.
 *
 * - **The handoff.** A request to a page that places exactly one registration form, carrying `kalaka[<key>]`
 *   (the quiz's POST body, or a static GET link; S73's contract), while the form can be used: each value is
 *   first cleaned by its field type's prefill rules, the rules a stored value opens with, so the parts the
 *   type makes nothing of are dropped and the rest kept (a multi-choice keeps its offered options); then it
 *   is checked alone with the type. An unknown key, a value that cleans to nothing or a value the type does
 *   not accept is dropped, silently (`Forms::linkAnswers()`; Master ruling 2026-11-17). What is left is kept
 *   as a guest draft under a new claim token, set in the cookie until the event's registration closes, and
 *   the request is answered with a 303 to
 *   the page without `kalaka`, never cached. Nothing of the request is logged.
 * - **The limit.** Every handoff request counts per client, like a submit and with its budget
 *   (`SubmitGuard::checkHandoff()`); one over the budget keeps nothing and is answered the same, with the 303.
 *   Every draft read and write goes through the event form host, in every account mode (owner ruling 2026-11-26).
 * - **The claim.** A logged-in request to such a page whose cookie names a guest draft of its form claims
 *   it: it becomes the person's draft, and the cookie is cleared. With a draft of their own, it waits for
 *   their choice (the popup) and the cookie stays, but only when the link sets a value the draft does not
 *   hold; when it changes nothing, the guest draft is dropped (keep_draft) and the cookie is cleared. A
 *   guest draft that is gone clears the cookie.
 *
 * - **The notice.** A claim the person's choice settled (the claim route keeps the cookie) is found on the
 *   load after it: the cookie is cleared, and the page carries the choice once (`settledNow()`).
 *
 * Any other page view (no `kalaka`, and no claim cookie of a logged-in visitor) returns before any read.
 */
final class DraftHandoff
{
    /** The request parameter of a prefill: `kalaka[<field key>]`. */
    public const PARAMETER = 'kalaka';

    /**
     * The choice that settled the cookie's claim, found on this request (the load after the choice): the
     * page shows it once (`EventFormHost::draft()`), and the cookie is cleared, so the next load does not.
     */
    private static ?DraftClaimChoice $settled = null;

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly EventFormHost $host = new EventFormHost(),
        private readonly SubmitGuard $guard = new SubmitGuard(),
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
                // Every request that may write a row counts, like a submit; over the budget it keeps nothing
                // and is answered as any other handoff (the parameter stripped), unlogged: a flood's noise.
                $redirect = true;
                try {
                    $this->guard->checkHandoff($actor);
                } catch (RateLimited) {
                    return;
                }
                $this->handOver($form, $prefill, $actor);

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
        $accepted = $this->forms->linkAnswers($definition, new AnswerSet($answers));
        if ($accepted->isEmpty()) {
            return;
        }
        $kept = $this->host->handOver($form, $definition->version, $accepted, DraftClaim::current(), $actor);
        // An event with no closing date keeps nothing on handoff (`EventFormHost::handOver`). That branch is
        // unreachable: publishing requires a closing date (`PublishEvent`, `registrationClosesAt`).
        if ($kept !== null) {
            DraftClaim::setCookie($kept[0], $kept[1]);
        }
        // A logged-in person claims it at once, on the page the 303 leads to.
    }

    private function claim(Form $form, Actor $actor): void
    {
        $token = DraftClaim::current();
        if ($token === null || !$actor instanceof AccountActor) {
            return;
        }
        $outcome = $this->host->claimDraft($form, $token, $actor);
        if ($outcome === DraftClaimOutcome::Gone) {
            self::$settled = $this->host->settledChoice($form, $actor->userId, $token);
        }
        $done = [DraftClaimOutcome::Claimed, DraftClaimOutcome::Settled, DraftClaimOutcome::Gone];
        if (in_array($outcome, $done, true)) {
            DraftClaim::setCookie(null);
        }
    }

    /** The choice that settled the cookie's claim, when this request found it (the load after the choice). */
    public static function settledNow(): ?DraftClaimChoice
    {
        return self::$settled;
    }

    /** @internal for tests: a new request */
    public static function forget(): void
    {
        self::$settled = null;
    }

    /** The one registration form the page places, when it can be used now; null otherwise. */
    private function registrationForm(\WP_Post $post): ?Form
    {
        if (!FormCaching::placesForm($post->post_content)) {
            return null;
        }
        $found = null;
        foreach (FormCaching::formIds($post->post_content) as $id) {
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
        $refusals = FormsPage::refusalsOf($this->host, $found, $post, Runner::current()->clock()->now());

        return $refusals === [] ? $found : null;
    }
}
