<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\Conditional;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/**
 * S76: the one availability base for what other modules own. Price-ladder tiers, offers and the
 * adjudicated aid are not built yet (S25, S30, S32); test doubles stand in for them, with the
 * conditions they will store and the facts their callers will pass.
 */
final class AvailabilityTest extends TestCase
{
    private Availability $availability;

    protected function setUp(): void
    {
        $this->availability = new Availability();
    }

    /**
     * A stand-in for a tier, an offer, the aid or a set option: a key and a stored condition.
     *
     * @param array<array-key, mixed>|null $condition
     */
    private static function thing(string $key, ?array $condition): Conditional
    {
        return new class ($key, $condition) implements Conditional {
            /** @param array<array-key, mixed>|null $condition */
            public function __construct(private readonly string $key, private readonly ?array $condition)
            {
            }

            public function availabilityKey(): string
            {
                return $this->key;
            }

            public function availabilityCondition(): ?array
            {
                return $this->condition;
            }
        };
    }

    /** @param list<string>|null $values a single choice of one option, or null for none */
    private static function choice(?array $values): Value
    {
        return $values === null ? Value::none(ValueType::Choice) : Value::choice($values, implode(', ', $values));
    }

    /** @return array<string, mixed> */
    private static function equals(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]];
    }

    /** @return list<Conditional> the price-ladder tiers of the support-level plan (S25), labels left out */
    private static function tiers(): array
    {
        return [
            self::thing('alap', self::equals('support_level', 'none')),
            self::thing('teljes', self::equals('support_level', 'none')),
            self::thing('megosztott', self::equals('support_level', 'shared')),
            self::thing('gyulekezeti', self::equals('support_level', 'full')),
            self::thing('alap_gyulekezet', self::equals('support_level', 'full')),
        ];
    }

    private function facts(?string $level, ?string $tier = null): ValueContext
    {
        return new ValueContext([
            'support_level' => self::choice($level === null ? null : [$level]),
            'tier' => self::choice($tier === null ? null : [$tier]),
            'congregation.ancestors' => self::choice(['root', 'district_teszt', 'gyulekezet_teszt']),
            'seats.diak' => Value::number('0', '0'),
            'seats.ifjusag' => Value::number('3', '3'),
        ]);
    }

    public function testTiersFollowTheSupportLevel(): void
    {
        $keys = fn (?string $level): array => array_keys(
            $this->availability->available(self::tiers(), $this->facts($level))
        );

        self::assertSame(['alap', 'teljes'], $keys('none'));
        self::assertSame(['megosztott'], $keys('shared'));
        self::assertSame(['gyulekezeti', 'alap_gyulekezet'], $keys('full'));
        self::assertSame([], $keys(null));
    }

    public function testTheSameObjectsComeBackInTheGivenOrder(): void
    {
        $tiers = self::tiers();
        $available = $this->availability->available($tiers, $this->facts('none'));

        self::assertSame($tiers[0], $available['alap']);
        self::assertSame($tiers[1], $available['teljes']);
    }

    public function testTheAidIsLimitedPerTierByItsCondition(): void
    {
        $aid = self::thing('aid', [
            'match' => 'any',
            'items' => [
                ['key' => 'tier', 'operator' => 'equals', 'value' => 'alap'],
                ['key' => 'tier', 'operator' => 'equals', 'value' => 'megosztott'],
            ],
        ]);

        $holds = fn (?string $level, ?string $tier): bool => $this->availability->holds(
            $aid->availabilityCondition(),
            $this->facts($level, $tier)
        );

        self::assertTrue($holds('none', 'alap'));
        self::assertTrue($holds('shared', 'megosztott'));
        self::assertFalse($holds('full', 'gyulekezeti'));
        self::assertFalse($holds(null, null));
    }

    public function testOffersReadDirectoryAncestorsAndSeatsFromTheFacts(): void
    {
        $offers = [
            self::thing('mindenkinek', null),
            self::thing('diak', [
                'match' => 'all',
                'items' => [['key' => 'seats.diak', 'operator' => 'greaterThan', 'value' => '0']],
            ]),
            self::thing('ifjusag', [
                'match' => 'all',
                'items' => [
                    ['key' => 'congregation.ancestors', 'operator' => 'contains', 'value' => 'district_teszt'],
                    ['key' => 'seats.ifjusag', 'operator' => 'greaterThan', 'value' => '0'],
                ],
            ]),
            self::thing('mas_kerulet', [
                'match' => 'all',
                'items' => [['key' => 'congregation.ancestors', 'operator' => 'contains', 'value' => 'district_mas']],
            ]),
        ];

        $available = $this->availability->available($offers, $this->facts('none'));

        self::assertSame(['mindenkinek', 'ifjusag'], array_keys($available));
    }

    public function testNoConditionAlwaysHolds(): void
    {
        self::assertTrue($this->availability->holds(null, new ValueContext()));
    }

    public function testAKeyTheFactsDoNotDeclareIsAFailureNeverUnavailable(): void
    {
        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('unknown_value_key');

        $this->availability->available([self::thing('x', self::equals('nope', 'a'))], $this->facts('none'));
    }

    public function testAKeyGivenTwiceIsAFailure(): void
    {
        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('duplicate_availability_key');

        $this->availability->available(
            [self::thing('a', null), self::thing('a', null)],
            $this->facts('none')
        );
    }

    public function testACheckOnSaveReadsTheDeclaredKeys(): void
    {
        $declared = ['support_level' => ValueType::Choice, 'event.start' => ValueType::Date];
        $this->availability->check(self::equals('support_level', 'full'), $declared);

        $this->expectException(SystemFailure::class);
        $this->availability->check(self::equals('tier', 'alap'), $declared);
    }
}
