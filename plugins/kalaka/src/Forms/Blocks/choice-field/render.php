<?php

/**
 * Server render of the choice field: events-and-forms.md, "Forms implementation". The markup is
 * `Kalaka\Forms\Engine\FieldMarkup`'s, with the theme adapter's classes and wrapper.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Engine\FieldMarkup;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
$kalakaMultiple = FieldMarkup::flag($attributes, 'multiple');
$kalakaOptions = is_array($attributes['options'] ?? null) ? $attributes['options'] : [];
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?>>
    <fieldset<?php echo FieldMarkup::classAttribute(ElementRole::ChoiceGroup); ?>>
        <legend<?php echo FieldMarkup::classAttribute(ElementRole::Label); ?>><?php
            echo FieldMarkup::title(FieldMarkup::text($attributes, 'label')),
                FieldMarkup::requiredMarker($kalakaRequired);
        ?></legend>
<?php foreach ($kalakaOptions as $kalakaOption) : ?>
    <?php
    $kalakaOption = is_array($kalakaOption) ? $kalakaOption : [];
    /** @var array<string, mixed> $kalakaOption */
    $kalakaValue = FieldMarkup::text($kalakaOption, 'value');
    ?>
        <label<?php echo FieldMarkup::classAttribute(ElementRole::Option); ?>>
    <?php if ($kalakaMultiple) : ?>
            <input type="checkbox" name="<?php echo esc_attr($kalakaKey); ?>[]"
                value="<?php echo esc_attr($kalakaValue); ?>"<?php
                echo FieldMarkup::classAttribute(ElementRole::ChoiceControl); ?>>
    <?php else : ?>
            <input type="radio" name="<?php echo esc_attr($kalakaKey); ?>"
                value="<?php echo esc_attr($kalakaValue); ?>"<?php
                echo FieldMarkup::classAttribute(ElementRole::ChoiceControl), $kalakaRequired ? ' required' : ''; ?>>
    <?php endif; ?>
            <?php echo FieldMarkup::title(FieldMarkup::text($kalakaOption, 'label')); ?>
        </label>
<?php endforeach; ?>
    </fieldset>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
