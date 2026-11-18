<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin\HeldViewFixture;

use Kalaka\Admin\Api\HeldView;

// A fixture of HeldViewSeamTest: a class outside Access that makes a held view.
final class MakesHeldView
{
    public function make(): HeldView
    {
        return new HeldView(1, null, null, null, null, null);
    }
}
