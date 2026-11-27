<?php

/**
 * Server render of the number field: events-and-forms.md, "Forms implementation". The wrapper, the label,
 * the required marker and the error element are `Kalaka\Forms\Engine\FieldMarkup`'s, with the theme
 * adapter's classes; the input is the browser's own number control, with the optional prefix and suffix
 * as muted, non-interactive text at its ends (the owner's look, 2026-11-21). The look is proposed:
 *.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Engine\FieldMarkup;
use Kalaka\Forms\Engine\FieldType\NumberFieldType;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
$kalakaSettings = NumberFieldType::readSettings($attributes) ?? [
    'min' => null,
    'step' => null,
    'max' => null,
    'decimals' => 0,
    'prefix' => '',
    'suffix' => '',
];
$kalakaPrefilled = FieldMarkup::prefilled($kalakaKey);
$kalakaPrefilled = is_string($kalakaPrefilled) ? $kalakaPrefilled : null;
$kalakaId = esc_attr(FieldMarkup::controlId($kalakaKey));
$kalakaTag = FieldMarkup::wrapperTag(true);
$kalakaClasses = 'kalaka-number'
    . ($kalakaSettings['prefix'] === '' ? '' : ' kalaka-number--prefix')
    . ($kalakaSettings['suffix'] === '' ? '' : ' kalaka-number--suffix');
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
?>
<<?php echo $kalakaTag; ?> <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?>>
    <label for="<?php echo $kalakaId; ?>"<?php echo FieldMarkup::classAttribute(ElementRole::Label); ?>><?php
        echo FieldMarkup::title(FieldMarkup::text($attributes, 'label')), FieldMarkup::requiredMarker($kalakaRequired);
    ?></label>
    <span class="<?php echo esc_attr($kalakaClasses); ?>">
        <?php if ($kalakaSettings['prefix'] !== '') : ?>
            <span class="kalaka-number__affix kalaka-number__prefix" aria-hidden="true"><?php
                echo esc_html($kalakaSettings['prefix']);
            ?></span>
        <?php endif; ?>
        <input type="number" id="<?php echo $kalakaId; ?>" name="<?php echo esc_attr($kalakaKey); ?>"<?php
        echo FieldMarkup::classAttribute(ElementRole::TextControl), $kalakaRequired ? ' required' : '',
            ' inputmode="' . esc_attr($kalakaSettings['decimals'] === 0 ? 'numeric' : 'decimal') . '"';
        if ($kalakaSettings['min'] !== null) {
            echo ' min="' . esc_attr($kalakaSettings['min']) . '"';
        }
        if ($kalakaSettings['step'] !== null) {
            echo ' step="' . esc_attr($kalakaSettings['step']) . '"';
        } elseif ($kalakaSettings['decimals'] > 0) {
            echo ' step="any"';
        }
        if ($kalakaSettings['max'] !== null) {
            echo ' max="' . esc_attr($kalakaSettings['max']) . '"';
        }
        if ($kalakaPrefilled !== null) {
            echo ' value="' . esc_attr($kalakaPrefilled) . '"';
        }
        ?>>
        <?php if ($kalakaSettings['suffix'] !== '') : ?>
            <span class="kalaka-number__affix kalaka-number__suffix" aria-hidden="true"><?php
                echo esc_html($kalakaSettings['suffix']);
            ?></span>
        <?php endif; ?>
    </span>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</<?php echo $kalakaTag; ?>>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
