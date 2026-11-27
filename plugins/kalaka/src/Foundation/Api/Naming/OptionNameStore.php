<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Naming;

/**
 * The plugin's names in one WordPress option, `kalaka_<module id>_names`, an array by declared key. The settings
 * screen writes it (WordPress-owned data, outside the runner: foundation.md, "Errors"), and the plugin's
 * `uninstall.php` deletes it.
 */
final class OptionNameStore implements NameStore
{
    public function __construct(public readonly string $option)
    {
        if (preg_match('/\Akalaka_[a-z][a-z0-9_]*_names\z/', $option) !== 1) {
            throw new \InvalidArgumentException('A names option is `kalaka_<module id>_names`.');
        }
    }

    public function read(): mixed
    {
        return get_option($this->option, null);
    }
}
