<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Renders a mail for one registration at send time, from current data (mail.md, "Content" and "Sending"):
 * the mail's body, inside the event's layout when the mail uses it and the event has one, rendered once by
 * `Values::render()` with the mail's context, so conditions in either part are evaluated by the one
 * evaluator. It reads WordPress posts, so it runs outside any use case. Its failures name a problem code
 * and a key, never a value.
 * // [UNAPPROVED N17] the renderer: one render of the body spliced into the layout; the subject is the
 * // body's title as plain text; HTML only; the version `b<revision>` or `b<revision>-l<revision>`.
 */
final class MailRenderer
{
    public function __construct(
        private readonly ActionMailSettings $settings = new ActionMailSettings(),
        private readonly MailPosts $posts = new MailPosts(),
        private readonly MailValues $values = new MailValues(),
        private readonly LayoutSlot $slot = new LayoutSlot(),
        private readonly Values $renderer = new Values(),
    ) {
    }

    /**
     * @param string $mail the mail's key: an action mail's key
     * @throws SystemFailure an unknown mail (`unknown_mail`), no body (`no_body`), a body or layout that is
     *         not a published document of the event, a layout without exactly one slot, or what
     *         `Values::render()` and the context throw
     */
    public function render(int $eventId, string $mail, RegistrationKey $registration): RenderedMail
    {
        $action = ActionMail::tryFrom($mail);
        if ($action === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_mail ' . $mail);
        }
        $settings = $this->settings->ofEvent($eventId);
        $setting = $settings->mail($action);
        if ($setting->bodyId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('no_body ' . $mail);
        }

        $body = $this->posts->published($setting->bodyId, $eventId);
        $document = $body->content;
        $version = 'b' . $body->revision;
        if ($setting->layout && $settings->layoutId !== null) {
            $layout = $this->posts->published($settings->layoutId, $eventId);
            $document = $this->slot->wrap($layout->content, $body->content);
            $version .= '-l' . $layout->revision;
        }

        $html = $this->renderer->render($document, $this->values->context($registration));

        return new RenderedMail(self::subject($body->title), $html, $version);
    }

    /** The title as WordPress stores it, as plain text. */
    private static function subject(string $title): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
