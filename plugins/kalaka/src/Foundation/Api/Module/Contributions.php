<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

use Kalaka\Forms\Api\FieldType;
use Kalaka\Foundation\Api\Seed\Seeder;

/** Typed extension-point methods are added together with the first module that needs each. */
final class Contributions
{
    private static ?self $current = null;

    /** @var list<Seeder> */
    private array $seeders = [];

    /** @var array<string, FieldType> */
    private array $fieldTypes = [];

    /** The contributions of this request's boot; empty before the boot has finished. */
    public static function current(): self
    {
        return self::$current ?? new self();
    }

    /**
     * Called by the boot only, once every module has contributed.
     *
     * @internal
     */
    public static function record(self $contributions): void
    {
        self::$current = $contributions;
    }

    public function seeder(Seeder $seeder): void
    {
        $this->seeders[] = $seeder;
    }

    /**
     * In contribution order: core modules in boot order, then extensions.
     *
     * @return list<Seeder>
     */
    public function seeders(): array
    {
        return $this->seeders;
    }

    /**
     * A field type is an outside point (modules-and-extensions.md, "The extension surface"). One
     * type per block name; a second contribution for the same block fails the contributing module.
     */
    public function fieldType(FieldType $type): void
    {
        $name = $type->blockName();
        if (isset($this->fieldTypes[$name])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a block name, never output
            throw new \InvalidArgumentException('Field type already contributed for block ' . $name . '.');
        }
        $this->fieldTypes[$name] = $type;
    }

    /**
     * In contribution order.
     *
     * @return array<string, FieldType> by block name
     */
    public function fieldTypes(): array
    {
        return $this->fieldTypes;
    }
}
