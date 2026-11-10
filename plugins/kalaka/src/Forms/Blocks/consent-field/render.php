<?php

/**
 * Server render of the consent field: events-and-forms.md, "Forms implementation". The markup is
 * `Kalaka\Forms\FieldMarkup`'s, with the theme adapter's classes and wrapper.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\FieldMarkup;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?>>
    <label<?php echo FieldMarkup::classAttribute(ElementRole::Label); ?>>
        <input type="checkbox" name="<?php echo esc_attr($kalakaKey); ?>" value="1"<?php
            echo FieldMarkup::classAttribute(ElementRole::ChoiceControl), $kalakaRequired ? ' required' : ''; ?>>
        <?php
        echo FieldMarkup::title(FieldMarkup::text($attributes, 'label')), FieldMarkup::requiredMarker($kalakaRequired);
        ?>
    </label>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
