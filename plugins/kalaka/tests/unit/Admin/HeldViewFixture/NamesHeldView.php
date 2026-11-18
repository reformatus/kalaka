<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin\HeldViewFixture;

use Kalaka\Admin\Api\HeldView;

// A fixture of HeldViewSeamTest: a class that takes a held view but makes none.
final class NamesHeldView
{
    public function eventOf(HeldView $view): int
    {
        return $view->eventId;
    }
}
