<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ranking;

use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Ranking\RankingFieldType;
use WP_UnitTestCase;

/** The ranking field type on its own: its answer, the stored shape, its value and the prefill clean-up. */
final class RankingFieldTypeTest extends WP_UnitTestCase
{
    private RankingFieldType $type;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        $this->type = new RankingFieldType();
    }

    /**
     * The block's attributes: four made-up modules, min 1 and max 3 unless given.
     *
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private static function attributes(array $more = []): array
    {
        return $more + [
            'key' => 'modulok',
            'label' => 'Modulok',
            'options' => [
                ['value' => 'modul_a', 'label' => 'Modul A'],
                ['value' => 'modul_b', 'label' => '<em>Modul B</em>'],
                ['value' => 'modul_c', 'label' => 'Modul C'],
                ['value' => 'modul_d', 'label' => 'Modul D'],
            ],
            'min' => 1,
            'max' => 3,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function answer(mixed $value, array $attributes = []): \Kalaka\Forms\Api\FieldAnswer
    {
        return $this->type->answer(new FieldTypeInput($value, self::attributes($attributes)));
    }

    public function testAnOrderedListOfOfferedKeysIsStoredAsJsonInRankOrder(): void
    {
        $answer = $this->answer(['modul_c', 'modul_a', 'modul_d']);

        self::assertNull($answer->error);
        self::assertSame('["modul_c","modul_a","modul_d"]', $answer->value);
    }

    public function testAKeyTheBlockDoesNotOfferOrARepeatIsAnError(): void
    {
        self::assertSame(RankingFieldType::NOT_OFFERED, $this->answer(['modul_a', 'modul_x'])->error);
        self::assertSame(RankingFieldType::NOT_OFFERED, $this->answer(['modul_a', 'modul_a'])->error);
    }

    public function testTooFewOrTooManyIsAnErrorThatNamesTheBound(): void
    {
        self::assertSame(
            'Legalább 2 lehetőséget válassz.',
            $this->answer(['modul_a'], ['min' => 2])->error
        );
        self::assertSame(
            'Legfeljebb 3 lehetőséget válassz.',
            $this->answer(['modul_a', 'modul_b', 'modul_c', 'modul_d'])->error
        );
        self::assertNull($this->answer(['modul_a', 'modul_b'], ['min' => 2, 'max' => 2])->error);
    }

    public function testAnythingButAListOfStringsIsNotAnAnswerOfTheType(): void
    {
        foreach (['modul_a', ['a' => 'modul_a'], [1, 2], 3, true] as $sent) {
            try {
                $this->answer($sent);
                self::fail('Accepted ' . wp_json_encode($sent));
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheBoundsFallBackToOneAndThreeAndMaxIsNeverBelowMin(): void
    {
        $plain = ['options' => []];

        self::assertSame(1, RankingFieldType::min($plain));
        self::assertSame(3, RankingFieldType::max($plain));
        self::assertSame(1, RankingFieldType::min(['min' => 0]));
        self::assertSame(4, RankingFieldType::max(['min' => 4, 'max' => 2]));
        self::assertSame(1, RankingFieldType::min(['min' => '2']));
    }

    public function testOptionsWithoutAValidKeyAndRepeatedKeysAreLeftOut(): void
    {
        $attributes = ['options' => [
            ['value' => 'modul_a', 'label' => 'Modul A'],
            ['value' => 'Modul B', 'label' => 'Modul B'],
            ['label' => 'Modul C'],
            ['value' => 'modul_a', 'label' => 'Megint A'],
            ['value' => '2026', 'label' => 'Kétezer-huszonhat'],
        ]];

        self::assertSame(['modul_a', '2026'], RankingFieldType::optionKeys($attributes));
    }

    public function testItsValueIsAChoiceOfTheKeysWithTheLabelsNumbered(): void
    {
        $value = $this->type->value(new FieldTypeInput('["modul_c","modul_b","modul_x"]', self::attributes()));

        self::assertSame(ValueType::Choice, $this->type->valueType());
        self::assertSame(ValueType::Choice, $value->type);
        self::assertSame(['modul_c', 'modul_b', 'modul_x'], $value->data);
        self::assertSame('1. Modul C, 2. Modul B, 3. modul_x', $value->text);
    }

    public function testAStoredValueThatIsNotAListIsEmpty(): void
    {
        $value = $this->type->value(new FieldTypeInput('nem lista', self::attributes()));

        self::assertTrue($value->isEmpty());
        self::assertSame('', $value->text);
    }

    /**
     * The stored ranking as the type opens it (`FieldType::prefill()`).
     *
     * @param array<string, mixed> $attributes
     */
    private function prefill(mixed $stored, array $attributes = []): mixed
    {
        return $this->type->prefill(new FieldTypeInput($stored, self::attributes($attributes)));
    }

    public function testPrefillDropsOnlyTheUnknownKeysAndRepeatsAndKeepsTheRestInOrder(): void
    {
        self::assertSame(['modul_c', 'modul_a'], $this->prefill('["modul_c","modul_x","modul_c","modul_a"]'));
        self::assertSame(['modul_c', 'modul_a'], $this->prefill('["modul_x","modul_c","modul_c",7,"modul_a"]'));
    }

    public function testPrefillKeepsTheFirstMaxOfALongerList(): void
    {
        self::assertSame(
            ['modul_d', 'modul_c', 'modul_b'],
            $this->prefill('["modul_d","modul_c","modul_b","modul_a"]')
        );
    }

    public function testPrefillKeepsTooFewAsTheyAreAndTheSubmitAsksForMore(): void
    {
        $prefilled = $this->prefill('["modul_b"]', ['min' => 3]);

        self::assertSame(['modul_b'], $prefilled);
        self::assertSame('Legalább 3 lehetőséget válassz.', $this->answer($prefilled, ['min' => 3])->error);
    }

    public function testPrefillOpensEmptyForAnythingButAStoredListWithAnOfferedKey(): void
    {
        self::assertNull($this->prefill('modul_a'));
        self::assertNull($this->prefill('{"a":"modul_a"}'));
        self::assertNull($this->prefill('["modul_x"]'));
        self::assertNull($this->prefill('[]'));
        self::assertNull($this->prefill(null));
    }
}
