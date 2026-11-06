<?php

/**
 * Server render of the choice field: events-and-forms.md, "Forms implementation".
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$kalakaKey = is_string($attributes['key'] ?? null) ? $attributes['key'] : '';
$kalakaLabel = is_string($attributes['label'] ?? null) ? $attributes['label'] : '';
$kalakaRequired = ($attributes['required'] ?? false) === true;
$kalakaMultiple = ($attributes['multiple'] ?? false) === true;
$kalakaOptions = is_array($attributes['options'] ?? null) ? $attributes['options'] : [];
?>
<div class="kalaka-field" data-kalaka-field="<?php echo esc_attr($kalakaKey); ?>">
    <fieldset>
        <legend><?php echo wp_kses_post($kalakaLabel); ?></legend>
<?php foreach ($kalakaOptions as $kalakaOption) : ?>
    <?php
    $kalakaValue = is_array($kalakaOption) && is_string($kalakaOption['value'] ?? null) ? $kalakaOption['value'] : '';
    $kalakaOptionLabel = is_array($kalakaOption) && is_string($kalakaOption['label'] ?? null)
        ? $kalakaOption['label']
        : '';
    ?>
        <label>
    <?php if ($kalakaMultiple) : ?>
            <input type="checkbox" name="<?php echo esc_attr($kalakaKey); ?>[]"
                value="<?php echo esc_attr($kalakaValue); ?>">
    <?php else : ?>
            <input type="radio" name="<?php echo esc_attr($kalakaKey); ?>"
                value="<?php echo esc_attr($kalakaValue); ?>"<?php echo $kalakaRequired ? ' required' : ''; ?>>
    <?php endif; ?>
            <?php echo wp_kses_post($kalakaOptionLabel); ?>
        </label>
<?php endforeach; ?>
    </fieldset>
</div>
