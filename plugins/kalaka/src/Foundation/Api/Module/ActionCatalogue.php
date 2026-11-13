<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

/**
 * Every per-event action the booted modules offer through `Module::capabilities()`, each once. Grants
 * carry these names (foundation.md, "Views and grants"; modules-and-extensions.md, "The Module interface").
 */
final class ActionCatalogue
{
    private static ?self $current = null;

    /** @param list<string> $names */
    public function __construct(private readonly array $names = [])
    {
    }

    /** This request's catalogue; empty before the boot has finished. */
    public static function current(): self
    {
        return self::$current ?? new self();
    }

    /**
     * Called by the boot once every module has booted, and by tests.
     *
     * @internal
     */
    public static function record(self $catalogue): void
    {
        self::$current = $catalogue;
    }

    /** @return list<string> in module boot order */
    public function names(): array
    {
        return $this->names;
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names, true);
    }
}
