<?php

declare(strict_types=1);

// `wp-login.php` defines these two; the tests do not load that file. Stand-ins that mark the frame.

if (!function_exists('login_header')) {
    function login_header(string $title = ''): void
    {
        echo '<div id="login-frame">';
    }
}

if (!function_exists('login_footer')) {
    function login_footer(string $inputId = ''): void
    {
        echo '</div>';
    }
}
