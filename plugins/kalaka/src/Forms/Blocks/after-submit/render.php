<?php

/**
 * The after-submit section (`Api\AfterSubmit`) renders nothing where it sits: it never shows in the
 * form. The submit renders its inner blocks through `Api\Forms::afterSubmit()`.
 *
 * @var string $content the rendered inner blocks
 * @var \WP_Block $block
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
