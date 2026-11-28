<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Admin\Grid;

use Kalaka\Admin\Grid\FieldDefinition;
use Kalaka\Admin\Grid\FieldDefinitions;
use Kalaka\Admin\Grid\GridModel;
use PHPUnit\Framework\TestCase;

/**
 * „Nincs válasz” in a list column's own filter (topic 6a, fix pass 1, B4): every list-type field column offers it,
 * whether or not the field is required, because a required field can still be empty (behind a condition, in an
 * additional form not yet sent, on a registration made before the field existed). Állapot, Kód and Szám never
 * carry it, and neither do text, number and date columns.
 */
final class GridModelEmptyTest extends TestCase
{
    /** @return array<string, mixed> the column's metadata */
    private static function meta(string $column, array $fields): array
    {
        $model = (new \ReflectionClass(GridModel::class))->newInstanceWithoutConstructor();
        /** @var array<string, mixed> */
        return (new \ReflectionMethod(GridModel::class, 'meta'))->invoke($model, $column, $fields, 7);
    }

    /** @return array<string, FieldDefinition> */
    private static function fields(bool $required): array
    {
        return [
            'lunch' => new FieldDefinition(
                'lunch',
                'kalaka/radio-field',
                'Ebéd',
                ['veg' => 'Vegetáriánus'],
                $required,
                'choice'
            ),
            'photo' => new FieldDefinition('photo', FieldDefinitions::CONSENT_TYPE, 'Fénykép', [], $required, null),
            'note' => new FieldDefinition('note', 'kalaka/text-field', 'Megjegyzés', [], $required, 'text'),
            'nights' => new FieldDefinition('nights', 'kalaka/number-field', 'Éjszakák', [], $required, 'number'),
            'born' => new FieldDefinition('born', 'kalaka/date-field', 'Született', [], $required, 'date'),
        ];
    }

    public function testAChoiceAndAConsentOfferNoAnswerWhetherOrNotTheyAreRequired(): void
    {
        foreach ([true, false] as $required) {
            $fields = self::fields($required);
            $flag = var_export($required, true);
            self::assertTrue(self::meta('field:lunch', $fields)['empty'] ?? false, 'choice, required ' . $flag);
            self::assertTrue(self::meta('field:photo', $fields)['empty'] ?? false, 'consent, required ' . $flag);
        }
    }

    public function testTextNumberAndDateColumnsNeverOfferIt(): void
    {
        foreach ([true, false] as $required) {
            $fields = self::fields($required);
            foreach (['field:note', 'field:nights', 'field:born'] as $column) {
                self::assertArrayNotHasKey('empty', self::meta($column, $fields), $column);
            }
        }
    }

    public function testTheColumnsThatAlwaysHaveAValueNeverOfferIt(): void
    {
        foreach (['status', 'code', 'serial'] as $column) {
            self::assertArrayNotHasKey('empty', self::meta($column, []), $column);
        }
    }
}
