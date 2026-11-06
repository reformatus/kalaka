<?php

/**
 * Server render of the consent field: events-and-forms.md, "Forms implementation".
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
    <label>
        <input type="checkbox" name="<?php echo esc_attr($kalakaKey); ?>"
            value="1"<?php echo $kalakaRequired ? ' required' : ''; ?>>
        <?php echo wp_kses_post($kalakaLabel); ?>
    </label>
</div>
