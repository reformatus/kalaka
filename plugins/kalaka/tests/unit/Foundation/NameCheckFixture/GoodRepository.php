<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\NameCheckFixture;

use Kalaka\Foundation\Api\Repository\Repository;

/**
 * @extends Repository<int, \stdClass>
 */
abstract class GoodRepository extends Repository
{
}
