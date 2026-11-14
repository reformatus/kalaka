<?php

/**
 * Server render of the transfer block "Átutalási adatok" (the `[kalaka_transfer]` shortcode renders the
 * same): nothing unless the active registration has something to pay by transfer (SB-4).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped where it is built
echo \Kalaka\Portal\ParticipantBlocks::transfer();
