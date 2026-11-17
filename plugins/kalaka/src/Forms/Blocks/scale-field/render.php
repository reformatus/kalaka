<?php

/**
 * Server render of the scale field: events-and-forms.md, "Forms implementation". The markup is
 * `Kalaka\Forms\Engine\FieldMarkup`'s, with the theme adapter's classes and wrapper: the title as the
 * `legend`, then one radio per point, labelled with the point's label or else its number. A stopgap, with no
 * look of its own; UX prototypes the look. [UNAPPROVED N5]
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Engine\FieldMarkup;
use Kalaka\Forms\Engine\FieldType\ScaleFieldType;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
$kalakaRange = ScaleFieldType::range($attributes);
$kalakaPoints = $kalakaRange === null ? [] : range($kalakaRange[0], $kalakaRange[1]);
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?>>
    <fieldset<?php echo FieldMarkup::classAttribute(ElementRole::ChoiceGroup); ?>>
        <legend<?php echo FieldMarkup::classAttribute(ElementRole::Label); ?>><?php
            echo FieldMarkup::title(FieldMarkup::text($attributes, 'label')),
                FieldMarkup::requiredMarker($kalakaRequired);
        ?></legend>
<?php foreach ($kalakaPoints as $kalakaPoint) : ?>
    <?php
    $kalakaValue = (string) $kalakaPoint;
    $kalakaLabel = ScaleFieldType::pointLabel($attributes, $kalakaPoint);
    ?>
        <label<?php echo FieldMarkup::classAttribute(ElementRole::Option); ?>>
            <input type="radio" name="<?php echo esc_attr($kalakaKey); ?>"
                value="<?php echo esc_attr($kalakaValue); ?>"<?php
                echo FieldMarkup::classAttribute(ElementRole::ChoiceControl), $kalakaRequired ? ' required' : '',
                    FieldMarkup::prefilledHas($kalakaKey, $kalakaValue) ? ' checked' : ''; ?>>
            <?php echo esc_html($kalakaLabel === '' ? $kalakaValue : $kalakaLabel); ?>
        </label>
<?php endforeach; ?>
    </fieldset>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
