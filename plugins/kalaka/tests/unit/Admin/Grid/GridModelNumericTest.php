<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin\Grid;

use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Grid\FieldDefinition;
use Kalaka\Admin\Grid\GridModel;
use Kalaka\Admin\View\ViewShape;
use PHPUnit\Framework\TestCase;

/** C1-1: the columns to order by number are the metadata's `numeric` ones, the number-valued fields. */
final class GridModelNumericTest extends TestCase
{
    /** @param list<string> $columns */
    private static function held(array $columns): HeldView
    {
        $listed = array_map(static fn (string $column): array => ['column' => $column, 'write' => false], $columns);

        return new HeldView(7, 1, 1, 'Nézet', ViewShape::of([], $listed), null);
    }

    /** @return list<string> */
    private static function numeric(HeldView $view, array $fields): array
    {
        $model = (new \ReflectionClass(GridModel::class))->newInstanceWithoutConstructor();
        /** @var list<string> */
        return (new \ReflectionMethod(GridModel::class, 'numericColumns'))->invoke($model, $view, $fields);
    }

    public function testTheNumberValuedFieldsAreTheNumericOnes(): void
    {
        $fields = [
            'nights' => new FieldDefinition('nights', 'kalaka/number-field', 'Éjszakák', [], false, 'number'),
            'scale' => new FieldDefinition('scale', 'kalaka/scale-field', 'Értékelés', [], false, 'number'),
            'name' => new FieldDefinition('name', 'kalaka/text-field', 'Név', [], false, 'text'),
            'lunch' => new FieldDefinition('lunch', 'kalaka/radio-field', 'Ebéd', ['veg' => 'Veg'], false, 'choice'),
        ];
        $view = self::held(['code', 'field:name', 'field:lunch', 'field:nights', 'field:scale']);

        self::assertSame(['field:nights', 'field:scale'], self::numeric($view, $fields));
    }

    public function testAFieldNoLongerDeclaredIsNotNumeric(): void
    {
        $view = self::held(['code', 'field:gone']);

        self::assertSame([], self::numeric($view, []));
    }
}
