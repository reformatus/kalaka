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

    /** Topic 6a: Kód, Név, Állapot, Szám, then the rest. */
    public function testTheFirstColumnsAreTheCodeTheNameTheStatusAndTheSerial(): void
    {
        $fields = ['a' => new FieldDefinition('a', 'kalaka/name-field', 'a', [], false, 'string')];
        $model = (new \ReflectionClass(GridModel::class))->newInstanceWithoutConstructor();
        $ordered = (new \ReflectionMethod(GridModel::class, 'ordered'))
            ->invoke($model, ['serial', 'status', 'field:a', 'decision:x', 'code'], $fields);

        self::assertSame(['code', 'field:a', 'status', 'serial', 'decision:x'], $ordered);
    }

    /** The display name field's column says so (the app shows the name beside the code); no other column does. */
    public function testOnlyTheNameFieldsColumnIsMarkedAsTheName(): void
    {
        $fields = [
            'a' => new FieldDefinition('a', 'kalaka/name-field', 'Név', [], false, 'string'),
            'b' => new FieldDefinition('b', 'kalaka/text-field', 'Megjegyzés', [], false, 'string'),
        ];
        $model = (new \ReflectionClass(GridModel::class))->newInstanceWithoutConstructor();
        $meta = new \ReflectionMethod(GridModel::class, 'meta');

        /** @var array<string, mixed> $name */
        $name = $meta->invoke($model, 'field:a', $fields, 1);
        /** @var array<string, mixed> $other */
        $other = $meta->invoke($model, 'field:b', $fields, 1);
        /** @var array<string, mixed> $code */
        $code = $meta->invoke($model, 'code', $fields, 1);

        self::assertTrue($name['name']);
        self::assertArrayNotHasKey('name', $other);
        self::assertArrayNotHasKey('name', $code);
    }
}
