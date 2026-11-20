<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin\Grid;

use Kalaka\Admin\Grid\FieldDefinition;
use Kalaka\Admin\Grid\GridModel;
use PHPUnit\Framework\TestCase;

/**
 * The grid's column order (review run 397, C2-2): Kaláka's own columns first, then the rest, where the other
 * columns keep their listed slots and the field columns fill the field slots in the fields' declared order.
 */
final class GridModelOrderTest extends TestCase
{
    /**
     * @param list<string> $listed
     * @return list<string>
     */
    private static function ordered(array $listed): array
    {
        $fields = [];
        foreach (['a', 'b', 'c'] as $key) {
            $fields[$key] = new FieldDefinition($key, 'kalaka/text-field', $key, [], false, 'string');
        }
        $model = (new \ReflectionClass(GridModel::class))->newInstanceWithoutConstructor();
        /** @var list<string> */
        return (new \ReflectionMethod(GridModel::class, 'ordered'))->invoke($model, $listed, $fields);
    }

    public function testAColumnThatIsNoFieldKeepsItsSlotAndTheFieldsFollowTheirDeclaredOrder(): void
    {
        // Compared pairwise, this listing made a cycle: a < b (declared), b < x and x < a (listed).
        self::assertSame(
            ['field:a', 'decision:confirmation', 'field:b'],
            self::ordered(['field:b', 'decision:confirmation', 'field:a'])
        );
    }

    public function testTheOrderDoesNotDependOnTheListedOrderOfTheFields(): void
    {
        $expected = ['code', 'status', 'field:a', 'decision:confirmation', 'field:b', 'field:c', 'field:gone'];
        foreach (
            [
                ['field:c', 'decision:confirmation', 'field:gone', 'field:b', 'status', 'field:a', 'code'],
                ['field:gone', 'decision:confirmation', 'field:a', 'field:c', 'field:b', 'code', 'status'],
            ] as $listed
        ) {
            self::assertSame($expected, self::ordered($listed), implode(',', $listed));
        }
    }
}
