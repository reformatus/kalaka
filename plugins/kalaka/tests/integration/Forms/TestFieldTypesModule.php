<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

final class TestFieldTypesModule extends AbstractModule
{
    public function id(): string
    {
        return 'forms_test';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function contribute(Contributions $to): void
    {
        $to->fieldType(new RatingFieldType());
        $to->fieldType(new SurchargeFieldType());
    }

    public function boot(): void
    {
    }
}
