<?php

declare(strict_types=1);

// `wp-login.php` defines these two; the tests do not load that file. Stand-ins that mark the frame.

if (!function_exists('login_header')) {
    function login_header(string $title = ''): void
    {
        echo '<head>';
        do_action('login_head');
        echo '</head><div id="login-frame">';
    }
}

if (!function_exists('login_footer')) {
    function login_footer(string $inputId = ''): void
    {
        // As core's `login_footer()`: the filtered "Go to site" link, the privacy link, the language form.
        $site = '<p id="backtoblog"><a href="/">&larr; Go to site</a></p>';
        $privacy = '<a class="privacy-policy-link" href="/privacy">Privacy</a>';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- a test stand-in
        echo apply_filters('login_site_html_link', $site);
        echo apply_filters('the_privacy_policy_link', $privacy, '/privacy');
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        if (apply_filters('login_display_language_dropdown', true)) {
            echo '<form id="language-switcher"></form>';
        }
        echo '</div>';
    }
}
