<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Evaluation\RuleFailed;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Registrations\Api\RegistrationStatus;

/** A component that owes per item: one `charge` line per item, converging, reversed together by a withdrawal. */
final class PerItemMaterializerTest extends PricingTestCase
{
    public function testEachItemIsItsOwnLineAndAChangedItemWritesOnlyItsDifference(): void
    {
        $things = new ItemsComponent(['a1' => '1000', 'b_2' => '2000']);
        $this->useComponents($things);
        $anna = $this->register('00A0');

        self::assertSame([
            'things:a1 participant 1000 charge:things:a1:participant:1',
            'things:b_2 participant 2000 charge:things:b_2:participant:1',
        ], $this->charges($anna));

        $things->items = ['a1' => '1500', 'b_2' => '2000'];
        $this->setStatus($anna, RegistrationStatus::Registered);
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($anna));

        self::assertSame(
            ['things:a1 participant 500 charge:things:a1:participant:2'],
            array_slice($this->charges($anna), 2)
        );
        self::assertSame('3500', $this->balance($anna));
    }

    public function testAWithdrawalReversesEveryItemAndAnItemThatLeftIsReversedToo(): void
    {
        $things = new ItemsComponent(['a1' => '1000', 'b_2' => '2000']);
        $this->useComponents($things);
        $anna = $this->register('00A0');

        $things->items = ['a1' => '1000'];
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($anna));
        self::assertSame('1000', $this->balance($anna));

        $this->setStatus($anna, RegistrationStatus::Withdrawn);
        self::assertSame('0', $this->balance($anna));
        self::assertContains('things:a1 participant -1000 charge:things:a1:participant:2', $this->charges($anna));
    }

    /** @return array<string, array{string}> */
    public static function badItemKeys(): array
    {
        return [
            'capitals' => ['Abc'],
            'a colon' => ['a:b'],
            'empty' => [''],
            'longer than the source key allows' => [str_repeat('a', 59)],
        ];
    }

    /** @dataProvider badItemKeys */
    public function testABadItemKeyFailsTheComponent(string $item): void
    {
        $this->useComponents(new ItemsComponent([$item => '1000']));

        try {
            $this->register('00A0');
            self::fail('The registration was written.');
        } catch (SystemFailure $failure) {
            $rule = $failure->getPrevious();
            self::assertInstanceOf(RuleFailed::class, $rule);
            self::assertSame('money/things', $rule->getMessage());
            self::assertInstanceOf(FeeComponentFailed::class, $rule->getPrevious());
        }
    }
}
