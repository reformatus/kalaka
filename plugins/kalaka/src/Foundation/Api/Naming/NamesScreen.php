<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Naming;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The plugin's names screen: a WordPress settings page, rendered in PHP, with one text field per declared name,
 * under WordPress's Settings menu. The field is labelled with the declared default and shows it as the
 * placeholder; an empty field is the default. WordPress's own Settings API saves the one option. Full access only.
 * The plugin calls `register()` from its module's `boot()`.
 */
final class NamesScreen
{
    public function __construct(
        private readonly OptionNameStore $store,
        private readonly PluginNames $names,
        /** The page's title and menu entry: the plugin's name. */
        private readonly string $title,
    ) {
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_menu', [$this, 'addPage']);
        add_filter('option_page_capability_' . $this->store->option, static fn(): string => AdminAccess::FULL_ACCESS);
    }

    /** `admin_init` */
    public function registerSetting(): void
    {
        register_setting($this->store->option, $this->store->option, [
            'type' => 'array',
            'default' => [],
            'show_in_rest' => false,
            'sanitize_callback' => [$this, 'sanitize'],
        ]);
    }

    /** `admin_menu` */
    public function addPage(): void
    {
        add_options_page($this->title, $this->title, AdminAccess::FULL_ACCESS, $this->store->option, [$this, 'render']);
    }

    /**
     * The setting's sanitize callback: a refusal keeps the stored names and shows the failure's own text.
     *
     * @return array<string, string>
     */
    public function sanitize(mixed $input): array
    {
        try {
            return $this->names->checked($input);
        } catch (InvalidInput $refusal) {
            add_settings_error($this->store->option, $refusal->errorCode(), $refusal->getMessage());

            return $this->names->overrides();
        }
    }

    /** The page. */
    public function render(): void
    {
        $option = $this->store->option;
        $overrides = $this->names->overrides();
        $max = PluginNames::MAX_LENGTH;
        echo '<div class="wrap"><h1>' . esc_html($this->title) . '</h1>';
        settings_errors($option);
        echo '<form method="post" action="options.php">';
        settings_fields($option);
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($this->names->declared() as $name) {
            $id = $option . '_' . $name->key;
            $field = $option . '[' . $name->key . ']';
            $value = $overrides[$name->key] ?? '';
            echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($name->default)
                . '</label></th><td><input type="text" class="regular-text" id="' . esc_attr($id)
                . '" name="' . esc_attr($field) . '" value="' . esc_attr($value)
                . '" placeholder="' . esc_attr($name->default) . '" maxlength="' . absint($max) . '"></td></tr>';
        }
        echo '</tbody></table>';
        submit_button();
        echo '</form></div>';
    }
}
