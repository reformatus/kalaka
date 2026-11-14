<?php

/**
 * Server render of the ranking field (modules-and-extensions.md, "Assignment sets"; events-and-forms.md,
 * "Forms implementation"). The markup is core's field markup (`Kalaka\Forms\Api\FieldMarkup`), with the
 * theme adapter's classes: a choice group whose options are the browser's own checkboxes, named
 * `<key>[]`, so the form sends the ticked ones in document order. The ticked options sit first, in rank
 * order, in an ordered list; the others follow in authored order. `view.js` moves an option between the
 * two lists when it is ticked or unticked, and up or down the ranked list by a drag or its two buttons;
 * the live region (WordPress's `screen-reader-text`) tells a screen reader an option's new place.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldMarkup;
use Kalaka\Ranking\RankingFieldType;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
$kalakaLabels = RankingFieldType::labels($attributes);
// Nothing prefills a form yet: the ranking opens empty. TODO(S60) an additional form opens with the current
// value, cleaned by `RankingFieldType::prefilled()`.
/** @var list<string> $kalakaRanked */
$kalakaRanked = [];
$kalakaItem = static function (string $value, string $label, int $index, bool $ranked) use ($kalakaKey): string {
    return '<li data-kalaka-option="' . esc_attr((string) $index) . '">'
        . '<label' . FieldMarkup::classAttribute(ElementRole::Option) . '>'
        . '<input type="checkbox" name="' . esc_attr($kalakaKey) . '[]" value="' . esc_attr($value) . '"'
        . ' autocomplete="off"' . FieldMarkup::classAttribute(ElementRole::ChoiceControl) . ($ranked ? ' checked' : '')
        . '> ' . esc_html($label) . '</label>'
        . ' <button type="button" data-kalaka-move="up"' . ($ranked ? '' : ' hidden') . '>'
        . esc_html('Feljebb') . '</button>'
        . ' <button type="button" data-kalaka-move="down"' . ($ranked ? '' : ' hidden') . '>'
        . esc_html('Lejjebb') . '</button>'
        . '</li>';
};
$kalakaPositions = array_flip(array_map(strval(...), array_keys($kalakaLabels)));
$kalakaRankedItems = '';
foreach ($kalakaRanked as $kalakaValue) {
    $kalakaRankedItems .= $kalakaItem($kalakaValue, $kalakaLabels[$kalakaValue], $kalakaPositions[$kalakaValue], true);
}
$kalakaOtherItems = '';
foreach ($kalakaLabels as $kalakaValue => $kalakaLabel) {
    $kalakaValue = (string) $kalakaValue;
    if (!in_array($kalakaValue, $kalakaRanked, true)) {
        $kalakaOtherItems .= $kalakaItem($kalakaValue, $kalakaLabel, $kalakaPositions[$kalakaValue], false);
    }
}
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup and the item builder escape what they build
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?> data-kalaka-ranking>
    <fieldset<?php echo FieldMarkup::classAttribute(ElementRole::ChoiceGroup); ?>>
        <legend<?php echo FieldMarkup::classAttribute(ElementRole::Label); ?>><?php
            echo FieldMarkup::title(FieldMarkup::text($attributes, 'label')),
                FieldMarkup::requiredMarker($kalakaRequired);
        ?></legend>
        <ol data-kalaka-ranked><?php echo $kalakaRankedItems; ?></ol>
        <ul data-kalaka-unranked><?php echo $kalakaOtherItems; ?></ul>
    </fieldset>
    <span class="screen-reader-text" aria-live="polite" data-kalaka-announce></span>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
