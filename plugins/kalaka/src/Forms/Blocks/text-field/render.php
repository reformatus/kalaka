<?php

/**
 * Server render of the text field: events-and-forms.md, "Forms implementation".
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$kalakaKey = is_string($attributes['key'] ?? null) ? $attributes['key'] : '';
$kalakaLabel = is_string($attributes['label'] ?? null) ? $attributes['label'] : '';
$kalakaRequired = ($attributes['required'] ?? false) === true;
$kalakaMultiline = ($attributes['multiline'] ?? false) === true;
?>
<div class="kalaka-field" data-kalaka-field="<?php echo esc_attr($kalakaKey); ?>">
    <label for="kalaka-field-<?php echo esc_attr($kalakaKey); ?>"><?php echo wp_kses_post($kalakaLabel); ?></label>
<?php if ($kalakaMultiline) : ?>
    <textarea id="kalaka-field-<?php echo esc_attr($kalakaKey); ?>"
        name="<?php echo esc_attr($kalakaKey); ?>"<?php echo $kalakaRequired ? ' required' : ''; ?>></textarea>
<?php else : ?>
    <input type="text" id="kalaka-field-<?php echo esc_attr($kalakaKey); ?>"
        name="<?php echo esc_attr($kalakaKey); ?>"<?php echo $kalakaRequired ? ' required' : ''; ?>>
<?php endif; ?>
</div>
