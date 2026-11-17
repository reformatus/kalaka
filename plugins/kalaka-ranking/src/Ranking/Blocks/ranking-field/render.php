<?php

/**
 * Server render of the ranking field (modules-and-extensions.md, "Assignment sets"; events-and-forms.md,
 * "Forms implementation"). The markup is core's field markup (`Kalaka\Forms\Api\FieldMarkup`), with the
 * theme adapter's classes: a choice group whose options are the browser's own checkboxes, named
 * `<key>[]`, so the form sends the ticked ones in document order. The ticked options sit first, in rank
 * order, in an ordered list; the others follow in authored order.
 *
 * Each option is a row (`data-kalaka-row`): the drag handle, the option's text, the up and down buttons,
 * and its checkbox, drawn by `style.css` as „+” while unticked and „×” while ticked (owner, r2: the +/×
 * buttons replace the checkbox). The text is no `label`: a tap on it does nothing (owner, r2), so a long
 * press that starts a drag can never untick. The checkbox's accessible name says what it does, and names
 * the option („… hozzáadása a sorrendhez” / „… eltávolítása a sorrendből”); `view.js` swaps it with the state.
 * The counter and the minimum's line (the first empty row; the empty rows are a third named list)
 * describe it (`aria-describedby`), so a „+” that does nothing at the maximum says why.
 *
 * `view.js` moves an option between the two lists when it is ticked or unticked, and up or down the
 * ranked list by a drag (a mouse from anywhere on the row; a finger by the handle at once, or anywhere
 * on the row after a long press) or by its two icon buttons; the live region (core's
 * `kalaka-visually-hidden`) tells a screen reader an option's new place. The handle is pointer-only and hidden
 * from screen readers, whose two buttons do the same.
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
// The order the form opens with (a stored ranking, as `RankingFieldType::prefill()` cleaned it); only the keys
// this block offers, so a row is never rendered for an option it does not have.
$kalakaPrefilled = FieldMarkup::prefilled($kalakaKey);
$kalakaRanked = array_values(array_filter(
    is_array($kalakaPrefilled) ? $kalakaPrefilled : [],
    static fn (mixed $value): bool => is_string($value) && isset($kalakaLabels[$value])
));
// The counter and the minimum's line describe every toggle, so a focused „+” says why it does nothing.
$kalakaCounterId = FieldMarkup::controlId($kalakaKey) . '-counter';
$kalakaMinimumId = FieldMarkup::controlId($kalakaKey) . '-minimum';
$kalakaIcon = static fn (string $path): string
    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"'
        . ' focusable="false" fill="none" stroke="currentColor" stroke-width="1.5"><path d="' . esc_attr($path)
        . '"/></svg>';
$kalakaItem = static function (
    string $value,
    string $label,
    int $index,
    bool $ranked
) use (
    $kalakaKey,
    $kalakaIcon,
    $kalakaCounterId,
    $kalakaMinimumId
): string {
    $hidden = $ranked ? '' : ' hidden';

    return '<li data-kalaka-option="' . esc_attr((string) $index) . '"><div data-kalaka-row>'
        . '<span data-kalaka-handle aria-hidden="true"' . $hidden . '>'
        . esc_html(RankingFieldType::HANDLE) . '</span>'
        . '<span data-kalaka-label' . FieldMarkup::classAttribute(ElementRole::Option) . '>'
        . esc_html($label) . '</span>'
        // Each control's accessible name names its option, in the editor's pattern ("… feljebb mozgatása").
        // The controls are one group, so a narrow screen wraps them under the text together.
        . '<span data-kalaka-controls>'
        . '<button type="button" class="wp-element-button" data-kalaka-move="up" aria-label="'
        . esc_attr(sprintf(RankingFieldType::UP_NAME, $label)) . '"' . $hidden . '>'
        . $kalakaIcon(RankingFieldType::UP_ICON) . '</button>'
        . '<button type="button" class="wp-element-button" data-kalaka-move="down" aria-label="'
        . esc_attr(sprintf(RankingFieldType::DOWN_NAME, $label)) . '"' . $hidden . '>'
        . $kalakaIcon(RankingFieldType::DOWN_ICON) . '</button>'
        . '<input type="checkbox" name="' . esc_attr($kalakaKey) . '[]" value="' . esc_attr($value) . '"'
        . ' data-kalaka-toggle aria-label="'
        . esc_attr(sprintf($ranked ? RankingFieldType::REMOVE_NAME : RankingFieldType::ADD_NAME, $label)) . '"'
        . ' aria-describedby="' . esc_attr($kalakaCounterId . ' ' . $kalakaMinimumId) . '"'
        . ' autocomplete="off"' . FieldMarkup::classAttribute(ElementRole::ChoiceControl)
        . ($ranked ? ' checked' : '')
        . '>'
        . '</span></div></li>';
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
$kalakaCount = count($kalakaRanked);
$kalakaEmpty = RankingFieldType::emptyRows($kalakaCount, $attributes);
$kalakaEmptyRows = '';
for ($kalakaRow = 0; $kalakaRow < $kalakaEmpty; $kalakaRow++) {
    // The first empty row says how many are still missing; the others are only places.
    $kalakaEmptyRows .= $kalakaRow === 0
        ? '<li data-kalaka-empty-row id="' . esc_attr($kalakaMinimumId) . '">'
            . esc_html(RankingFieldType::minimumLine($kalakaCount, $attributes)) . '</li>'
        : '<li data-kalaka-empty-row aria-hidden="true"></li>';
}
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup and the item builder escape what they build
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?> data-kalaka-ranking
    data-kalaka-min="<?php echo esc_attr((string) RankingFieldType::min($attributes)); ?>"
    data-kalaka-max="<?php echo esc_attr((string) RankingFieldType::max($attributes)); ?>"<?php
    echo $kalakaRequired ? ' data-kalaka-required' : ''; ?>>
    <fieldset<?php echo FieldMarkup::classAttribute(ElementRole::ChoiceGroup); ?>>
        <legend<?php echo FieldMarkup::classAttribute(ElementRole::Label); ?>><?php
            echo FieldMarkup::title(FieldMarkup::text($attributes, 'label')),
                FieldMarkup::requiredMarker($kalakaRequired);
        ?></legend>
        <ol data-kalaka-ranked role="list" aria-label="<?php echo esc_attr(RankingFieldType::RANKED_NAME); ?>"><?php
            echo $kalakaRankedItems; ?></ol>
        <ol data-kalaka-empty role="list" aria-label="<?php echo esc_attr(RankingFieldType::EMPTY_NAME); ?>"
            start="<?php echo esc_attr((string) ($kalakaCount + 1)); ?>"<?php
            echo $kalakaEmpty === 0 ? ' hidden' : ''; ?>><?php echo $kalakaEmptyRows; ?></ol>
        <p data-kalaka-counter id="<?php echo esc_attr($kalakaCounterId); ?>"><?php
            echo esc_html(sprintf(RankingFieldType::COUNTER, $kalakaCount, RankingFieldType::max($attributes)));
        ?></p>
        <ul data-kalaka-unranked role="list" aria-label="<?php echo esc_attr(RankingFieldType::OTHERS_NAME); ?>"><?php
            echo $kalakaOtherItems; ?></ul>
    </fieldset>
    <span class="kalaka-visually-hidden" aria-live="polite" data-kalaka-announce></span>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
