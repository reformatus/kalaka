<?php

/**
 * Server render of the status block "Jelentkezésem állapota" (the `[kalaka_status]` shortcode renders the
 * same): the visitor's active registration, inside one error boundary.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped where it is built
echo \Kalaka\Portal\ParticipantBlocks::status();
