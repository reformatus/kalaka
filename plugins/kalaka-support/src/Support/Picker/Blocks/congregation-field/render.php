<?php

/**
 * Server render of the congregation field (money.md, "Sponsor and church support"; events-and-forms.md,
 * "Forms implementation"). [UNAPPROVED N6] (UI) A minimal working picker on the browser's own controls, until
 * UX's prototype is approved: the field's title labels a search box, and below it a list of buttons, one per
 * node, with the nodes' type above it and, below the root level, a button one level up with the path. The
 * answer is a hidden input named by the field's key, holding the picked node's id; the search box has no
 * name, so the form never sends what is typed. `view.js` fills the list from the public directory route
 * (its URL is `data-kalaka-nodes`): the live roots first, a node's children when it is opened, the matches
 * while something is searched. Nothing is picked when the form opens. TODO(S60/S73) an additional form
 * opens with the current value. The hidden input has `autocomplete="off"`, so a browser restoring the page
 * never brings back an answer the picker does not show.
 *
 * The title and the search box sit in a `p` of their own, as a text field's do, so a theme that styles a
 * text control by its `p` (Divi 4) styles the search box too. The picked node shows below the list, so a
 * pick never moves the list. `style.css` gives the picker a visible keyboard focus of its own (a theme may
 * remove the outline) and marks the picked node's button.
 *
 * The markup is core's field markup (`Kalaka\Forms\Api\FieldMarkup`), with the theme adapter's classes.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldMarkup;

defined('ABSPATH') || exit;

$kalakaKey = FieldMarkup::text($attributes, 'key');
$kalakaRequired = FieldMarkup::flag($attributes, 'required');
$kalakaId = FieldMarkup::controlId($kalakaKey);
$kalakaSearch = '<input type="search" id="' . esc_attr($kalakaId) . '" autocomplete="off"'
    . ' aria-controls="' . esc_attr($kalakaId . '-nodes') . '"'
    . FieldMarkup::classAttribute(ElementRole::TextControl)
    . ($kalakaRequired ? ' aria-required="true"' : '') . ' data-kalaka-search>';
$kalakaTitle = FieldMarkup::title(FieldMarkup::text($attributes, 'label'))
    . FieldMarkup::requiredMarker($kalakaRequired);
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup and the code above escape it
?>
<div <?php echo FieldMarkup::wrapperAttributes($kalakaKey); ?> data-kalaka-congregation
    data-kalaka-nodes="<?php echo esc_url(rest_url('kalaka-support/v1/nodes')); ?>">
    <p>
        <label for="<?php echo esc_attr($kalakaId); ?>"<?php
            echo FieldMarkup::classAttribute(ElementRole::Label);
        ?>><?php echo $kalakaTitle; ?></label>
        <?php echo $kalakaSearch; ?>
    </p>
    <input type="hidden" name="<?php echo esc_attr($kalakaKey); ?>" value="" autocomplete="off">
    <p data-kalaka-trail hidden>
        <?php // [UNAPPROVED N5] (Text) ?>
        <button type="button" data-kalaka-up><?php echo esc_html('Egy szinttel feljebb'); ?></button>
        <span data-kalaka-path></span>
    </p>
    <p id="<?php echo esc_attr($kalakaId . '-level'); ?>" data-kalaka-level></p>
    <ul id="<?php echo esc_attr($kalakaId . '-nodes'); ?>"
        aria-labelledby="<?php echo esc_attr($kalakaId . '-level'); ?>" data-kalaka-list></ul>
    <p data-kalaka-message tabindex="-1" hidden></p>
    <p data-kalaka-picked hidden></p>
    <span class="screen-reader-text" aria-live="polite" data-kalaka-announce></span>
    <?php echo FieldMarkup::errorElement($kalakaKey); ?>
</div>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
