<?php

declare(strict_types=1);

namespace Kalaka\Divi4;

use Kalaka\Forms\Api\ContentFrame;
use Kalaka\Forms\Api\FieldWrapper;
use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Api\ThemeAdapter;
use Kalaka\Forms\Api\ThemeAdapterInput;

/**
 * Divi 4's contact-form look on Kaláka's form markup (modules-and-extensions.md, "Extensions in view"):
 * Divi's contact-form classes, `p` wrappers, which Divi's input rules (`.et_pb_contact p input`) need, and
 * Divi's form red, and, for the card return page core renders itself, the classic page frame with Divi's full-width
 * body class. Labels stay visible (Divi's own label class hides them) and radio buttons and
 * checkboxes stay the browser's own: a choice and a consent sit in a `div`, where Divi's rules that hide
 * them for its drawn ones do not reach.
 *
 * Divi 5 also names its template `Divi` but styles forms differently; it gets an adapter of its own
 * later, so this one applies only below version 5.
 */
final class DiviThemeAdapter implements ThemeAdapter
{
    /** The template (parent theme directory) of Divi, also under a child theme. */
    public const TEMPLATE = 'Divi';

    /** The red of Divi's contact-form errors (`red` in its stylesheet). */
    public const ERROR_COLOUR = '#ff0000';

    /** @var \Closure(string): string the installed version of a theme, by its directory */
    private readonly \Closure $version;

    /** @param (\Closure(string): string)|null $version the theme's version; WordPress's theme header by default */
    public function __construct(?\Closure $version = null)
    {
        $this->version = $version ?? static fn (string $template): string
            => (string) wp_get_theme($template)->get('Version');
    }

    public function adapt(ThemeAdapterInput $input): ?ThemeAdaptation
    {
        if (!$this->applies($input->template)) {
            return null;
        }

        return new ThemeAdaptation(
            [
                // Divi's field rules sit under `.et_pb_contact`, and `.et_pb_contact_form` sets the gutter that
                // the fields' padding fills. Not `et_pb_contact_form_container`: Divi's script sends forms
                // inside it itself.
                'form' => ['et_pb_contact', 'et_pb_contact_form'],
                'field' => ['et_pb_contact_field'],
                // `et_pb_contact_message` gives a textarea Divi's height; it matches only a textarea.
                'text_control' => ['input', 'et_pb_contact_message'],
                'submit_button' => ['et_pb_contact_submit', 'et_pb_button'],
                // The card return page (core's own markup) is a full-width Divi page: `page.php`'s frame under
                // `et_full_width_page`, not the sidebar layout. [UNAPPROVED N820]
                'page_body' => ['et_full_width_page'],
            ],
            FieldWrapper::P,
            self::ERROR_COLOUR,
            ContentFrame::Page,
            ['et_left_sidebar', 'et_right_sidebar']
        );
    }

    /** Whether the template is Divi at a version below 5; an unknown version does not apply. */
    public function applies(string $template): bool
    {
        if ($template !== self::TEMPLATE) {
            return false;
        }
        $version = ($this->version)($template);

        return $version !== '' && version_compare($version, '5', '<');
    }
}
