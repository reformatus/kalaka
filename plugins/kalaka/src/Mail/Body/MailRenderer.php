<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;
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
 * // [UNAPPROVED N17] the renderer: the subject is the body's title as plain text; HTML only; the version
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
     * @throws SystemFailure an unknown mail (`unknown_mail`), no body (`no_body`), a body that is not a
     *         published document of the event, a stored layout without its one placeholder (`layout`), or
     *         what `Values::render()` and the context throw
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
        $html = $this->renderer->render($body->content, $this->values->context($registration));
        $version = 'b' . $body->revision;
        $layout = $setting->layout ? $this->layouts->of($eventId) : null;
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
