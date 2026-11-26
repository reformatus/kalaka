<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Registrations\Api\AwaitingRule;
use Kalaka\Registrations\Api\FieldTest;
use Kalaka\Registrations\Api\FieldTestKind;
use PHPUnit\Framework\TestCase;

/** The awaiting rule in PHP: any-of groups of all-of field tests. [UNAPPROVED N669] */
final class AwaitingRuleTest extends TestCase
{
    public function testTheFourKindsOfTest(): void
    {
        $filled = new FieldTest('k', FieldTestKind::Filled);
        $empty = new FieldTest('k', FieldTestKind::Empty);
        $oneOf = new FieldTest('k', FieldTestKind::OneOf, ['a', 'b']);
        $noneOf = new FieldTest('k', FieldTestKind::NoneOf, ['a', 'b']);

        self::assertSame([false, true, false, true], [
            $filled->matches([]), $empty->matches([]), $oneOf->matches([]), $noneOf->matches([]),
        ]);
        self::assertSame([false, true, false, true], [
            $filled->matches(['k' => '']), $empty->matches(['k' => '']), $oneOf->matches(['k' => '']),
            $noneOf->matches(['k' => '']),
        ]);
        self::assertSame([true, false, true, false], [
            $filled->matches(['k' => 'a']), $empty->matches(['k' => 'a']), $oneOf->matches(['k' => 'a']),
            $noneOf->matches(['k' => 'a']),
        ]);
        self::assertSame([true, false, false, true], [
            $filled->matches(['k' => '0']), $empty->matches(['k' => '0']), $oneOf->matches(['k' => '0']),
            $noneOf->matches(['k' => '0']),
        ]);
    }

    public function testAGroupIsAllOfAndTheRuleIsAnyOf(): void
    {
        $rule = new AwaitingRule([
            [new FieldTest('x', FieldTestKind::Filled), new FieldTest('y', FieldTestKind::Empty)],
            [new FieldTest('z', FieldTestKind::OneOf, ['1'])],
        ]);

        self::assertTrue($rule->matches(['x' => '5']));
        self::assertFalse($rule->matches(['x' => '5', 'y' => '0']));
        self::assertTrue($rule->matches(['x' => '5', 'y' => '0', 'z' => '1']));
        self::assertFalse($rule->matches([]));
    }

    public function testAMalformedRuleOrTestIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        new FieldTest('k', FieldTestKind::OneOf);
    }

    public function testAnEmptyStringAmongTheValuesIsRefused(): void
    {
        foreach ([FieldTestKind::OneOf, FieldTestKind::NoneOf] as $kind) {
            try {
                new FieldTest('k', $kind, ['a', '']);
                self::fail('An empty value must be refused.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAnEmptyRuleIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        new AwaitingRule([[]]);
    }
}
