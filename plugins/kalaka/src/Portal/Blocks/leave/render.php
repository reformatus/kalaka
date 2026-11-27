<?php

/**
 * Server render of the leave block (the `[kalaka_leave]` shortcode renders the same): the one button that
 * leaves the active registration and the account, and nothing for a visitor with neither. The block's
 * title is the button's stand-in label.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped where it is built
echo \Kalaka\Portal\ParticipantBlocks::leave();
