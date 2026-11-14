<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormBranch;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Engine\Condition\Comparison;
use Kalaka\Forms\Engine\Condition\Operator;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** AC5: the branches a form's fields sit in, and the definition problems of conditions (D6, D8, D9, D10). */
final class FormBranchTest extends FormsTestCase
{
    private const SIZES = [['value' => 's', 'label' => 'Kicsi'], ['value' => 'm', 'label' => 'Nagy']];

    /** @param array<string, mixed> $condition */
    private function compare(string $key, string $operator, ?string $value = null, array $extra = []): array
    {
        $item = ['key' => $key, 'operator' => $operator] + ($value === null ? [] : ['value' => $value]) + $extra;

        return ['match' => 'all', 'items' => [$item]];
    }

    /** @param array<string, mixed>|null $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        return '<!-- wp:kalaka/' . $name . ($condition === null ? '' : ' '
            . wp_json_encode(['condition' => $condition]))
            . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    private function definitionOf(string $content): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->slotForm($content)));
    }

    /** Asserts that reading the form fails with this problem code and names this key. */
    private function assertProblem(string $content, string $code, string $key = ''): void
    {
        try {
            $this->definitionOf($content);
            self::fail('Problem ' . $code . ' was not found.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString($code . ($key === '' ? '' : ' ' . $key), $e->getMessage());
        }
    }

    private function condition(?FormBranch $branch): ?Comparison
    {
        $item = $branch?->own?->items[0] ?? null;

        return $item instanceof Comparison ? $item : null;
    }

    public function testTheBranchBlocksHaveTheirTitlesInTheKalakaCategoryWithTheFormEditor(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        foreach (['if' => 'Ha', 'else-if' => 'Különben ha', 'else' => 'Különben'] as $name => $title) {
            $block = $registry->get_registered('kalaka/' . $name);
            self::assertInstanceOf(\WP_Block_Type::class, $block, $name);
            self::assertSame($title, $block->title, $name);
            self::assertSame('kalaka', $block->category, $name);
            self::assertNull($block->ancestor, $name);
            self::assertSame(['kalaka-form-editor-script'], $block->editor_script_handles, $name);
        }
    }

    public function testEachFieldCarriesTheBranchesItSitsInOutermostFirstAndFieldsOutsideCarryNone(): void
    {
        $content = $this->field('choice', 'fee', ['options' => self::SIZES])
            . $this->branch(
                'if',
                $this->compare('fee', 'equals', 's'),
                $this->field('text', 'why')
                . $this->branch(
                    'if',
                    $this->compare('why', 'contains', 'x'),
                    $this->field('text', 'deep'),
                )
                . $this->branch('else', null, $this->field('text', 'shallow'))
            )
            . $this->branch('else-if', $this->compare('fee', 'equals', 'm'), $this->field('text', 'big'))
            . $this->branch('else', null, $this->field('text', 'other'))
            . $this->field('text', 'tail');

        $definition = $this->definitionOf($content);

        foreach (['name', 'email', 'fee', 'tail'] as $key) {
            self::assertSame([], $definition->field($key)?->branches, $key);
        }

        $why = $definition->field('why')?->branches ?? [];
        self::assertCount(1, $why);
        self::assertSame([], $why[0]->earlier);
        self::assertSame(Operator::Equals, $this->condition($why[0])?->operator);
        self::assertSame('s', $this->condition($why[0])?->value);

        $deep = $definition->field('deep')?->branches ?? [];
        self::assertCount(2, $deep);
        self::assertEquals($why[0], $deep[0]);
        self::assertSame('why', $this->condition($deep[1])?->key);
        self::assertSame([], $deep[1]->earlier);

        $shallow = $definition->field('shallow')?->branches ?? [];
        self::assertCount(2, $shallow);
        self::assertEquals($why[0], $shallow[0]);
        self::assertNull($shallow[1]->own);
        self::assertEquals([$deep[1]->own], $shallow[1]->earlier);

        $big = $definition->field('big')?->branches ?? [];
        self::assertCount(1, $big);
        self::assertEquals([$why[0]->own], $big[0]->earlier);
        self::assertSame('m', $this->condition($big[0])?->value);

        $other = $definition->field('other')?->branches ?? [];
        self::assertCount(1, $other);
        self::assertEquals([$why[0]->own, $big[0]->own], $other[0]->earlier);
        self::assertNull($other[0]->own);
    }

    public function testAConditionMayReadAFieldOfAnEnclosingBranchThatComesBeforeItsChain(): void
    {
        $content = $this->field('text', 'a')
            . $this->branch(
                'if',
                $this->compare('a', 'empty'),
                $this->field('text', 'b')
                . $this->branch('if', $this->compare('b', 'empty', null, ['not' => true]), $this->field('text', 'c'))
            );

        self::assertCount(2, $this->definitionOf($content)->field('c')?->branches ?? []);
    }

    public function testAConditionNamingALaterFieldIsAProblem(): void
    {
        $this->assertProblem(
            $this->branch(
                'if',
                $this->compare('later', 'empty'),
                $this->field('text', 'x')
            ) . $this->field('text', 'later'),
            'condition_key_not_earlier',
            'later'
        );
    }

    public function testAConditionNamingAFieldOfItsOwnBranchIsAProblem(): void
    {
        $this->assertProblem(
            $this->branch('if', $this->compare('inside', 'empty'), $this->field('text', 'inside')),
            'condition_key_not_earlier',
            'inside'
        );
    }

    public function testAnElseIfCannotReadAFieldOfTheBranchBeforeIt(): void
    {
        $this->assertProblem(
            $this->field('text', 'a')
            . $this->branch('if', $this->compare('a', 'empty'), $this->field('text', 'first'))
            . $this->branch('else-if', $this->compare('first', 'empty'), $this->field('text', 'second')),
            'condition_key_not_earlier',
            'first'
        );
    }

    public function testAnUnknownKeyAndAKeyOfAnotherDocumentKindAreProblems(): void
    {
        $this->assertProblem(
            $this->branch('if', $this->compare('nope', 'empty'), $this->field('text', 'x')),
            'condition_key_not_earlier',
            'nope'
        );
        // The event's keys are a form's facts since S76; a registration's are not.
        $this->assertProblem(
            $this->branch('if', $this->compare('registration.status', 'empty'), $this->field('text', 'x')),
            'condition_key_not_earlier',
            'registration.status'
        );
    }

    public function testAChoiceValueThatIsNotAnOptionOfTheFieldIsAProblem(): void
    {
        $this->assertProblem(
            $this->field('choice', 'size', ['options' => self::SIZES])
            . $this->branch('if', $this->compare('size', 'equals', 'xl'), $this->field('text', 'x')),
            'invalid_condition',
            'size'
        );
    }

    public function testAnOperatorTheFieldsValueTypeDoesNotAllowIsAProblem(): void
    {
        $this->assertProblem(
            $this->field('date', 'born') . $this->branch(
                'if',
                $this->compare('born', 'contains', '2010'),
                $this->field('text', 'x')
            ),
            'invalid_condition',
            'born'
        );
        $this->assertProblem(
            $this->field('text', 'note') . $this->branch(
                'if',
                $this->compare('note', 'greaterThan', 'a'),
                $this->field('text', 'x')
            ),
            'invalid_condition',
            'note'
        );
        $this->assertProblem(
            $this->field('choice', 'size', ['options' => self::SIZES])
            . $this->branch('if', $this->compare('size', 'lessThan', 's'), $this->field('text', 'x')),
            'invalid_condition',
            'size'
        );
    }

    public function testADateValueMustBeADate(): void
    {
        $this->assertProblem(
            $this->field('date', 'born') . $this->branch(
                'if',
                $this->compare('born', 'lessOrEqual', 'tegnap'),
                $this->field('text', 'x')
            ),
            'invalid_condition',
            'born'
        );
    }

    public function testAMissingConditionIsAProblem(): void
    {
        $this->assertProblem($this->branch('if', null, $this->field('text', 'x')), 'invalid_condition');
    }

    public function testAMisplacedBranchIsAProblem(): void
    {
        $this->assertProblem($this->branch('else', null, $this->field('text', 'x')), 'orphan_branch', 'kalaka/else');
        $this->assertProblem(
            $this->field('text', 'a') . $this->branch(
                'else-if',
                $this->compare('a', 'empty'),
                $this->field('text', 'x')
            ),
            'orphan_branch',
            'kalaka/else-if'
        );
        $this->assertProblem(
            $this->field('text', 'a')
            . $this->branch('if', $this->compare('a', 'empty'), $this->field('text', 'x'))
            . $this->branch('else', null, $this->field('text', 'y'))
            . $this->branch('else', null, $this->field('text', 'z')),
            'orphan_branch',
            'kalaka/else'
        );
    }

    public function testAProblemNamesNeitherALabelNorAValue(): void
    {
        try {
            $this->definitionOf(
                $this->field('text', 'note', ['label' => 'Titkos címke'])
                . $this->branch('if', $this->compare('note', 'lessThan', 'Titkos érték'), $this->field('text', 'x'))
            );
            self::fail('The condition was accepted.');
        } catch (SystemFailure $e) {
            self::assertStringNotContainsString('Titkos', $e->getMessage());
        }
    }
}
