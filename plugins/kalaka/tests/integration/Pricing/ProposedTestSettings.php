<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** A proposed component setting for the test: the given component and save, under the given ID. */
final readonly class ProposedTestSettings implements ProposedComponentSettings
{
    public function __construct(
        private string $id,
        private FeeComponent $component,
        private SettingsSave $save,
    ) {
    }

    public function componentId(): string
    {
        return $this->id;
    }

    public function component(): FeeComponent
    {
        return $this->component;
    }

    public function save(): SettingsSave
    {
        return $this->save;
    }
}
