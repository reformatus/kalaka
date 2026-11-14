<?php

/**
 * Server render of the embed block: events-and-forms.md, "Forms implementation". It places the form it
 * names on any page. In place of the form it renders one refusal (a paragraph, or WordPress's own password
 * form); otherwise the JavaScript warning, the form, its buttons (Vissza and Tovább only on a multi-page
 * form, shown by the script) and the loading spinner, which the script shows after 2 seconds. Without
 * JavaScript the form stays as the server rendered it for no answers and the buttons stay disabled; the
 * script enables them when it starts.
 * All of it renders inside the form's one error boundary (`FormPage::html()`).
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\FieldMarkup;
use Kalaka\Forms\FormPage;
use Kalaka\Forms\ThemeAdapters;
use Kalaka\Forms\FormPageView;
use Kalaka\Foundation\Api\Error\SystemFailure;

defined('ABSPATH') || exit;

$kalakaFormId = is_int($attributes['formId'] ?? null) ? $attributes['formId'] : 0;

// A page that places a form is never cached (N6): again here, for a form the `template_redirect` check cannot see.
\Kalaka\Forms\Api\FormCaching::preventCaching();

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- the boundary's HTML, WordPress's own form, escaped JSON, blocks WordPress has rendered
echo (new FormPage())->html($kalakaFormId, static function (FormPageView $kalakaView): void {
    if ($kalakaView->refusal === FormPageView::PASSWORD_REQUIRED && $kalakaView->post !== null) {
        echo get_the_password_form($kalakaView->post);
    } elseif ($kalakaView->refusal !== null) {
        $kalakaTexts = [
            FormPageView::SYSTEM_FAILURE => (new SystemFailure())->getMessage(),
            FormPageView::UNAVAILABLE => 'Ez az űrlap jelenleg nem érhető el.',
            FormPageView::NOT_STARTED => 'A jelentkezés még nem kezdődött el.',
            FormPageView::CLOSED => 'A jelentkezés lezárult.',
            FormPageView::ROLE_REQUIRED => 'Az űrlap kitöltéséhez jelentkezz be egy megfelelő jogosultságú fiókkal.',
        ];
        echo '<p>' . esc_html($kalakaTexts[$kalakaView->refusal] ?? $kalakaTexts[FormPageView::UNAVAILABLE]) . '</p>';
    } else {
        // The theme adapter's error colour, for the stylesheet: `--kalaka-error-color` on the form.
        $kalakaColour = ThemeAdapters::current()->errorColour;
        $kalakaStyle = $kalakaColour === null
            ? ''
            : 'style="' . esc_attr('--kalaka-error-color: ' . $kalakaColour) . '"';
        $kalakaButtonClass = FieldMarkup::classAttribute(ElementRole::SubmitButton);
        $kalakaFormClass = FieldMarkup::classAttribute(ElementRole::Form);
        ?>
<noscript><p><?php echo esc_html('Az űrlap kitöltéséhez kapcsold be a JavaScriptet a böngésződben.'); ?></p></noscript>
<form novalidate<?php echo $kalakaFormClass; ?> data-wp-interactive="kalaka/form"
        <?php echo wp_interactivity_data_wp_context($kalakaView->context); ?>
        <?php echo $kalakaStyle; ?> data-wp-init="callbacks.start" data-wp-on--change="actions.update"
    data-wp-on--submit="actions.submit">
    <input type="hidden" data-kalaka-token value="<?php echo esc_attr($kalakaView->token); ?>">
        <?php echo $kalakaView->content; ?>
    <div role="alert" data-kalaka-alert></div>
        <?php if ($kalakaView->context['pages'] !== []) : ?>
    <button type="button"<?php echo $kalakaButtonClass; ?>
        data-kalaka-back data-wp-on--click="actions.back" hidden><?php echo esc_html('Vissza'); ?></button>
    <button type="button"<?php echo $kalakaButtonClass; ?>
        data-kalaka-next data-wp-on--click="actions.next" hidden disabled><?php echo esc_html('Tovább'); ?></button>
        <?php endif; ?>
    <button type="submit"<?php echo $kalakaButtonClass; ?>
        data-kalaka-send disabled><?php echo esc_html('Küldés'); ?></button>
    <div data-kalaka-spinner hidden>
        <svg class="kalaka-spinner" viewBox="0 0 100 100" width="16" height="16" role="presentation"
            focusable="false" aria-hidden="true">
            <circle class="kalaka-spinner__track" cx="50" cy="50" r="50" vector-effect="non-scaling-stroke"/>
            <path class="kalaka-spinner__indicator" d="m 50 0 a 50 50 0 0 1 50 50" vector-effect="non-scaling-stroke"/>
        </svg>
    </div>
    <div role="status" class="kalaka-visually-hidden" data-kalaka-status
        data-kalaka-loading="<?php echo esc_attr('Betöltés…'); ?>"></div>
</form>
        <?php
    }
});
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
