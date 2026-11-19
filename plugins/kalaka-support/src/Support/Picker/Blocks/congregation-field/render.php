<?php

/**
 * Server render of the congregation field (money.md, "Sponsor and church support"; events-and-forms.md,
 * "Forms implementation"). The owner's design (2026-11-17): one text field, an ARIA
 * 1.2 combobox, with a dropdown listbox below it that `view.js` fills from the public directory route (its URL
 * is `data-kalaka-nodes`). Above the field, the breadcrumbs: the path being browsed, or the picked leaf's
 * parents. Inside it, on an optional field, the × that removes the pick (H5). The answer is the hidden input
 * named by the field's key, holding the picked leaf's id; the text field has no name, so the form never sends
 * what is typed. The hidden input has `autocomplete="off"`, so a browser restoring the page never brings back
 * an answer the picker does not show.
 *
 * The answer the form opens with (`FieldMarkup::prefilled()`, the leaf's id as `CongregationFieldType::prefill()`
 * gives it), when there is one, is shown from here: its name in the field, its parents in the breadcrumbs
 * (drawn by `view.js` when it starts) and in the field's description (`-path`, read by a screen reader with
 * the field), and, when it is no longer an answer (deactivated since), the line under the field (H7).
 *
 * The title, the breadcrumbs and the field sit in a `p` of their own, as a text field's do, so a theme that
 * styles a text control by its `p` (Divi 4) styles the field too; only phrasing content goes in it. The
 * dropdown is absolutely placed under the anchor, which holds the field and its lines (H7's, the error), so it
 * never covers them. Its message line (no match, loading, a failure with its retry, the cap) is at its top, in
 * sight before any option. The text field comes before the hidden input: the form focuses a field's first
 * control.
 *
 * The markup is core's field markup (`Kalaka\Forms\Api\FieldMarkup`), with the theme adapter's classes.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldMarkup;
use Kalaka\Support\Picker\PickedNodes;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaLabel = FieldMarkup::text($attributes, 'label');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
$kalakaId = FieldMarkup::controlId($kalakaKey);

// The answer the form opens with (a reopened draft, an additional form, a form link), if any.
$kalakaValue = FieldMarkup::prefilled($kalakaKey);
$kalakaCurrent = is_string($kalakaValue) && ctype_digit($kalakaValue)
    ? (new PickedNodes())->shown((int) $kalakaValue)
    : null;
$kalakaStale = $kalakaCurrent !== null && !$kalakaCurrent['live'];
$kalakaPath = $kalakaCurrent === null
    ? ''
    : implode(', ', array_column($kalakaCurrent['ancestors'], 'name'));

// the texts, after the lektor; view.js holds the rest.
$kalakaPlaceholder = 'Keress, vagy válassz a listából';
$kalakaCrumbsName = 'Útvonal – ' . $kalakaLabel;
$kalakaRemoveName = 'Választás törlése – ' . $kalakaLabel;
$kalakaStaleText = 'A választott elem már nem választható. Válassz a listából.';

$kalakaDescribed = array_merge(
    $kalakaPath === '' ? [] : [$kalakaId . '-path'],
    $kalakaStale ? [$kalakaId . '-stale'] : []
);
$kalakaDescribedBy = $kalakaDescribed === []
    ? ''
    : ' aria-describedby="' . esc_attr(implode(' ', $kalakaDescribed)) . '"';
$kalakaInput = '<input type="text" id="' . esc_attr($kalakaId) . '" role="combobox" autocomplete="off"'
    . ' aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr($kalakaId . '-options') . '"'
    . ' placeholder="' . esc_attr($kalakaPlaceholder) . '"'
    . ' value="' . esc_attr($kalakaCurrent === null ? '' : $kalakaCurrent['name']) . '"'
    . FieldMarkup::classAttribute(ElementRole::TextControl)
    . ($kalakaRequired ? ' aria-required="true"' : '') . $kalakaDescribedBy . ' data-kalaka-search>';
$kalakaTitle = FieldMarkup::title($kalakaLabel) . FieldMarkup::requiredMarker($kalakaRequired);
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup and the code above escape it
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?> data-kalaka-congregation
    data-kalaka-nodes="<?php echo esc_url(rest_url('kalaka-support/v1/nodes')); ?>"<?php
    if ($kalakaCurrent !== null) {
        echo ' data-kalaka-current="' . esc_attr((string) wp_json_encode($kalakaCurrent)) . '"';
    }
    ?>>
    <div data-kalaka-anchor>
        <p>
            <label for="<?php echo esc_attr($kalakaId); ?>"<?php
                echo FieldMarkup::classAttribute(ElementRole::Label);
            ?>><?php echo $kalakaTitle; ?></label>
            <span role="group" aria-label="<?php echo esc_attr($kalakaCrumbsName); ?>" data-kalaka-crumbs
                hidden></span>
            <span data-kalaka-box>
                <?php echo $kalakaInput; ?>
                <?php if (!$kalakaRequired) : ?>
                    <button type="button" aria-label="<?php echo esc_attr($kalakaRemoveName); ?>" data-kalaka-remove
                        <?php echo $kalakaCurrent === null ? 'hidden' : ''; ?>></button>
                <?php endif; ?>
            </span>
        </p>
        <span id="<?php echo esc_attr($kalakaId . '-stale'); ?>" data-kalaka-stale
            <?php echo $kalakaStale ? '' : 'hidden'; ?>><?php echo esc_html($kalakaStaleText); ?></span>
        <?php echo FieldMarkup::errorElement($kalakaKey); ?>
        <div data-kalaka-popup hidden>
            <span data-kalaka-message hidden></span>
            <div role="listbox" id="<?php echo esc_attr($kalakaId . '-options'); ?>" data-kalaka-list></div>
        </div>
    </div>
    <input type="hidden" name="<?php echo esc_attr($kalakaKey); ?>" autocomplete="off"
        value="<?php echo esc_attr($kalakaCurrent === null ? '' : (string) $kalakaCurrent['id']); ?>">
    <span id="<?php echo esc_attr($kalakaId . '-path'); ?>" class="kalaka-visually-hidden" data-kalaka-described
        ><?php echo esc_html($kalakaPath); ?></span>
    <span class="screen-reader-text kalaka-visually-hidden" aria-live="polite" data-kalaka-announce></span>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
