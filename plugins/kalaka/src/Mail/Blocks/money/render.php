<?php

/**
 * Server render of the money table of a mail: the registration whose mail is being rendered, through the same
 * read as the status page. Outside a mail's render it renders nothing.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped where it is built
echo \Kalaka\Mail\Body\MailBlocks::money();
