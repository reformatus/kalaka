<?php

/**
 * The account block (S15, owner ruling 2026-11-18): on a form page, `Engine\AccountMarkup` for the current
 * visitor (`FormPage::accountBlock()`); nothing anywhere else, and nothing for a form without an account step
 * (an additional form, or an event whose account mode is `off`).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccountMarkup escapes everything it prints
echo \Kalaka\Forms\Engine\FormPage::accountBlock();
