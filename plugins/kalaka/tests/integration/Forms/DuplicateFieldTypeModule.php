<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Engine\FieldType\NameFieldType;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

/** A module that contributes a second field type for a block name the core already has. */
final class DuplicateFieldTypeModule extends AbstractModule
{
    public function id(): string
    {
        return 'forms_duplicate';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function contribute(Contributions $to): void
    {
        $to->fieldType(new NameFieldType());
    }

    public function boot(): void
    {
    }
}
