<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Extras;

use Kalaka\Extras\Charges\ChargeField;
use Kalaka\Extras\Module;
use PHPUnit\Framework\TestCase;

/** The declarer Extras stores on its participant fields is its module's ID. */
final class DeclarerTest extends TestCase
{
    public function testTheDeclarerIsTheModulesId(): void
    {
        self::assertSame((new Module())->id(), ChargeField::DECLARER);
    }
}
