<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The way from a registration form that needs an account to WordPress's own registration page and back
 * (owner ruling 2026-11-14; registrations-and-identity.md, "Accounts on the registration form"):
 *
 * - `url()`: `wp_registration_url()` with `redirect_to` (the form's page) and the marker `kalaka_form`
 *   (the form's ID), which the registration page reads to know a Kaláka form sent the visitor;
 * - `skipUrl()`: the form's page with `kalaka_skip` (the form's ID), the skip link of `optional` mode. The
 *   form page then shows the form to someone not logged in instead of sending them to sign up. It only
 *   avoids the redirect: it fills nothing in.
 * // [UNAPPROVED N3] the two query parameters.
 */
final class SignUp
{
    /** The marker on the registration page: the ID of the form that sent the visitor. */
    public const MARKER = 'kalaka_form';

    /** The skip marker on the form's page: the ID of the form shown without an account. */
    public const SKIP = 'kalaka_skip';

    /** WordPress's registration page for the form, returning to `$return` (the form's page). */
    public static function url(int $formId, string $return): string
    {
        return add_query_arg(
            [self::MARKER => $formId, 'redirect_to' => rawurlencode($return)],
            wp_registration_url()
        );
    }

    /** The form's page with the skip marker for the form. */
    public static function skipUrl(int $formId, string $return): string
    {
        return add_query_arg(self::SKIP, $formId, $return);
    }

    /**
     * Whether the request skips the sign-up for the form: its query carries the form's skip marker.
     *
     * @param array<string, mixed> $query the request's query parameters
     */
    public static function skipped(int $formId, array $query): bool
    {
        $skip = $query[self::SKIP] ?? null;

        return is_string($skip) && $skip === (string) $formId;
    }

    /**
     * The form ID a marker value names, or null for anything that is not a positive integer.
     */
    public static function formIdOf(mixed $marker): ?int
    {
        return is_string($marker) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $marker) === 1 ? (int) $marker : null;
    }
}
