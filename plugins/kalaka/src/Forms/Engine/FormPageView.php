<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

/**
 * What the embed block shows for a form, the result of `FormPage::open()`: either one refusal, or the
 * form's rendered content with the context the script starts from. The texts are the block's
 * (`Blocks/form/render.php`).
 */
final readonly class FormPageView
{
    /** The site is closed: SystemFailure's text. A definition problem goes to the boundary instead. */
    public const SYSTEM_FAILURE = 'system_failure';

    /** An unknown or unpublished form, or an event that is not open. */
    public const UNAVAILABLE = 'unavailable';

    /** The registration slot's window has not begun. */
    public const NOT_STARTED = 'not_started';

    /** The registration slot's window has ended. */
    public const CLOSED = 'closed';

    /** The form has a password and WordPress has no password cookie for it. */
    public const PASSWORD_REQUIRED = 'password_required';

    /** The form requires a role the current user lacks. */
    public const ROLE_REQUIRED = 'role_required';

    /**
     * @param string|null $refusal one of the constants; null when the form shows
     * @param string $content the rendered content of the form, empty for a refusal
     * @param array<string, mixed> $context the form element's Interactivity context, empty for a refusal
     * @param \WP_Post|null $post the form post of a password refusal
     * @param string $token the page's `SubmitToken`, for its hidden field; empty for a refusal
     * @param array{skip: bool, loginUrl: string, email: array{key: string, label: string},
     *     name: array{key: string, label: string}}|null $account the account step before the form's first
     *     page (`FormHost::accountStep()`), for someone not logged in: whether it offers skip, WordPress's login
     *     page returning to this page, and the form's contact email and display name, which signing up
     *     fills in; null when no step shows
     */
    public function __construct(
        public ?string $refusal,
        public string $content = '',
        public array $context = [],
        public ?\WP_Post $post = null,
        public string $token = '',
        public ?array $account = null,
    ) {
    }

    public static function refused(string $refusal, ?\WP_Post $post = null): self
    {
        return new self($refusal, '', [], $post);
    }
}
