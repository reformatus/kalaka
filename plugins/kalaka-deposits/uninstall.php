<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// The plugin's one option: the per-event deposit amounts.
delete_option('kalaka_deposits');
