<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\BodyUnusable;
use Kalaka\Mail\Catalogue\MailLayout;
use Kalaka\Mail\Catalogue\MailLayoutRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Renders a mail for one registration at send time, from current data (mail.md, "Content" and "Sending"):
 * the mail's body rendered by `Values::render()` with the mail's context (conditions by the one evaluator),
 * then put in the event's layout in place of `{{content}}` when the mail uses it (a new event's layout is
 * exactly `{{content}}`). The
 * layout is raw HTML and is not rendered or escaped; the body's values are escaped by the render. It reads
 * WordPress posts, so it runs outside any use case. Its failures name a problem code and a key, never a value.
 * // `b<revision>`, or `b<revision>-l<layout row version>` when wrapped by a stored layout.
 */
final class MailRenderer
{
    public function __construct(
        private readonly ActionMailSettings $settings = new ActionMailSettings(),
        private readonly MailPosts $posts = new MailPosts(),
        private readonly MailValues $values = new MailValues(),
        private readonly MailLayoutRepository $layouts = new MailLayoutRepository(),
        private readonly Values $renderer = new Values(),
    ) {
    }

    /**
     * @param string $mail the mail's key: an action mail's key
     * @param ?int $lineId the ledger line that caused a "payment recorded" mail (its `payment.*` keys read it)
     * @throws SystemFailure an unknown mail (`unknown_mail`), a body without a revision, a stored layout
     *         without its one placeholder (`layout`), or
     *         what `Values::render()` and the context throw
     * @throws BodyUnusable the body's own problem: none assigned (`no_body`), gone, unpublished or of another
     *         event (`MailPosts::published()`), or, checked against the mail's keys (`Values::check()`), an
     *         undeclared key, an invalid condition, a misplaced branch
     */
    public function render(
        int $eventId,
        string $mail,
        RegistrationKey $registration,
        ?int $lineId = null,
    ): RenderedMail {
        $action = ActionMail::tryFrom($mail);
        if ($action === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_mail ' . $mail);
        }
        $settings = $this->settings->ofEvent($eventId);
        $setting = $settings->mail($action);
        if ($setting->bodyId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new BodyUnusable('no_body ' . $mail);
        }

        // Only the "payment recorded" mail reads a line: the `payment.*` keys are none in every other mail.
        return $this->renderBody(
            $eventId,
            $setting->bodyId,
            $setting->layout,
            $registration,
            $action === ActionMail::PaymentRecorded ? $lineId : null
        );
    }

    /**
     * Renders one published body of the event for a registration, whether or not an action mail uses it: what
     * `render()` does once it has found the body, and what a manual mail (`Manual\MailBatch`) renders.
     *
     * @param bool $wrapped whether the event's layout wraps the body
     * @param ?int $lineId the ledger line behind a "payment recorded" mail (its `payment.*` keys read it)
     * @throws SystemFailure a body without a revision, a stored layout without its one placeholder (`layout`),
     *         or what `Values::render()` and the context throw
     * @throws BodyUnusable the body's own problem, as `render()`
     */
    public function renderBody(
        int $eventId,
        int $bodyId,
        bool $wrapped,
        RegistrationKey $registration,
        ?int $lineId = null,
    ): RenderedMail {
        $body = $this->posts->published($bodyId, $eventId);
        // The typed `{{money_table}}` and `{{transfer_details}}` stand for their blocks (`MailBlocks::expand()`).
        $content = MailBlocks::expand($body->content);
        try {
            $types = $this->values->types($eventId, $body->content);
            $this->renderer->check($content, $types, $this->values->paths($eventId));
        } catch (SystemFailure $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw BodyUnusable::of($e);
        }
        $context = $this->values->context(
            $registration,
            MailValues::readsPasswordLink($body->content),
            MailValues::pageLinks($body->content),
            $lineId
        );
        MailInProgress::push($registration);
        try {
            $html = $this->renderer->render($content, $context);
        } finally {
            MailInProgress::pop();
        }
        $version = 'b' . $body->revision;
        $layout = $wrapped ? $this->layouts->of($eventId) : null;
        if ($layout !== null) {
            if (!MailLayout::isValid($layout->html)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('layout ' . $eventId);
            }
            $html = $layout->wrap($html);
            // The default layout (no stored row) is exactly `{{content}}`, so it adds nothing to the version.
            $version .= $layout->version > 0 ? '-l' . $layout->version : '';
        }

        return new RenderedMail(self::subject($body->title), $html, $version);
    }

    /** The title as WordPress stores it, as plain text. */
    private static function subject(string $title): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
