<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Naming;

/** One text a plugin declares by key, with its default. */
final class DeclaredName
{
    /** The key is a lowercase identifier: it is the key of the plugin's names option. */
    public function __construct(
        public readonly string $key,
        public readonly string $default,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $key) !== 1) {
            throw new \InvalidArgumentException('A declared name key is a lowercase identifier.');
        }
        if (trim($default) === '' || mb_strlen($default) > PluginNames::MAX_LENGTH) {
            throw new \InvalidArgumentException('A declared default is not empty and not longer than the limit.');
        }
    }
}
