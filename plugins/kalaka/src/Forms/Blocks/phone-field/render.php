<?php

/**
 * Server render of the phone field: events-and-forms.md, "Forms implementation".
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$kalakaKey = is_string($attributes['key'] ?? null) ? $attributes['key'] : '';
$kalakaLabel = is_string($attributes['label'] ?? null) ? $attributes['label'] : '';
$kalakaRequired = ($attributes['required'] ?? false) === true;
?>
<div class="kalaka-field" data-kalaka-field="<?php echo esc_attr($kalakaKey); ?>">
    <label for="kalaka-field-<?php echo esc_attr($kalakaKey); ?>"><?php echo wp_kses_post($kalakaLabel); ?></label>
    <input type="tel" id="kalaka-field-<?php echo esc_attr($kalakaKey); ?>"
        name="<?php echo esc_attr($kalakaKey); ?>"<?php echo $kalakaRequired ? ' required' : ''; ?>>
</div>
