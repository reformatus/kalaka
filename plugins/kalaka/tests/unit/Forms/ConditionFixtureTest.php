<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\ContextScope;
use PHPUnit\Framework\TestCase;

/**
 * AC4: the condition cases of `tests/fixtures/conditions.json` give the fixture's expected result through
 * the server's evaluator, the only one (no condition logic in JavaScript).
 */
final class ConditionFixtureTest extends TestCase
{
    /** @return iterable<string, array{array<string, array{type: string, data: string|list<string>|null}>, mixed, bool}> */
    public static function cases(): iterable
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's own fixture
        $file = file_get_contents(__DIR__ . '/../../fixtures/conditions.json');
        self::assertIsString($file);
        $fixture = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$case['values'], $case['condition'], $case['expected']];
        }
    }

    /**
     * @dataProvider cases
     * @param array<string, array{type: string, data: string|list<string>|null}> $values
     */
    public function testTheServerGivesTheFixturesResult(array $values, mixed $condition, bool $expected): void
    {
        $declared = [];
        foreach ($values as $key => $value) {
            $type = ValueType::from($value['type']);
            $data = $value['data'];
            $declared[$key] = match (true) {
                $data === null => Value::none($type),
                $type === ValueType::Number => Value::number((string) $data, (string) $data),
                $type === ValueType::Date => Value::date((string) $data, (string) $data),
                $type === ValueType::Choice => Value::choice((array) $data, ''),
                default => Value::text((string) $data, (string) $data),
            };
        }
        $context = new ValueContext($declared);

        $parsed = (new ConditionParser())->parse($condition, new ContextScope($context));

        self::assertSame($expected, (new ConditionEvaluator())->holds($parsed, $context));
    }
}
