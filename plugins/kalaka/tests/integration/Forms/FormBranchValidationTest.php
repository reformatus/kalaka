<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Error\InvalidInput;

require_once __DIR__ . '/FormsTestCase.php';

/** AC6: the submit re-check keeps only the answers whose conditions hold (D10, D11). */
final class FormBranchValidationTest extends FormsTestCase
{
    private const REQUIRED = 'Ezt a mezőt ki kell töltened.';

    /** @param array<string, mixed> $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        return '<!-- wp:kalaka/' . $name . ($condition === null ? '' : ' ' . wp_json_encode(['condition' => $condition]))
            . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    /** @return array<string, mixed> */
    private function when(string $key, string $operator, ?string $value = null): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => $operator] + ($value === null ? [] : ['value' => $value])]];
    }

    private function definition(string $content): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->slotForm($content)));
    }

    /** The fee form: the fee choice routes to the support and help sections. */
    private function feeForm(): FormDefinition
    {
        $fees = [['value' => 'basic', 'label' => 'Alap'], ['value' => 'extra_support', 'label' => 'Támogatott']];
        $reasons = [['value' => 'community_pays', 'label' => 'A közösség fizeti'], ['value' => 'self_pays', 'label' => 'Saját']];

        return $this->definition(
            $this->field('choice', 'fee', ['options' => $fees, 'required' => true])
            . $this->field('choice', 'fee_reason', ['options' => $reasons, 'required' => true])
            . $this->branch(
                'if',
                $this->when('fee', 'equals', 'extra_support'),
                $this->field('text', 'support_why', ['required' => true])
                . $this->field('text', 'support_who', ['required' => true])
                . $this->field('date', 'support_since', ['required' => true])
            )
            . $this->branch(
                'else-if',
                $this->when('fee_reason', 'equals', 'self_pays'),
                $this->field('text', 'help_note', ['required' => true]) . $this->field('date', 'help_born')
            )
        );
    }

    /** @return array<string, mixed> */
    private function base(): array
    {
        return ['name' => 'Kovács Anna', 'email' => 'anna@example.org', 'fee' => 'basic', 'fee_reason' => 'community_pays'];
    }

    /** @return array<string, mixed> */
    private function slot(): array
    {
        return ['name' => 'Kovács Anna', 'email' => 'anna@example.org'];
    }

    /** @param array<string, mixed> $answers */
    private function validate(FormDefinition $definition, array $answers): ValidatedAnswers
    {
        return $this->forms->validate($definition, $answers);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, string>
     */
    private function errorsOf(FormDefinition $definition, array $answers): array
    {
        try {
            $this->validate($definition, $answers);
        } catch (InvalidAnswers $e) {
            return $e->fields;
        }
        self::fail('The answers were accepted.');
    }

    public function testAnswersForBranchesThatDoNotHoldAreDroppedWithoutAnErrorWhateverTheyAre(): void
    {
        $answers = $this->base() + [
            'support_why' => '',
            'support_who' => 'Kovács Béla',
            'support_since' => 'nem dátum',
            'help_note' => null,
            'help_born' => null,
        ];

        $validated = $this->validate($this->feeForm(), $answers);

        self::assertSame($this->base(), $validated->submitted());
        self::assertSame($this->base(), $validated->stored());
    }

    public function testAnIllFormedOrWrongTypedAnswerInAHiddenBranchIsDroppedToo(): void
    {
        $validated = $this->validate($this->feeForm(), $this->base() + [
            'support_why' => ['a', 'list'],
            'support_since' => 12,
            'help_note' => false,
            'help_born' => '2010-02-30',
        ]);

        self::assertSame(array_keys($this->base()), array_keys($validated->stored()));
    }

    public function testTheRequiredFieldsOfTheBranchThatHoldsAreRequired(): void
    {
        self::assertSame(
            ['support_why' => self::REQUIRED, 'support_who' => self::REQUIRED, 'support_since' => self::REQUIRED],
            $this->errorsOf($this->feeForm(), ['fee' => 'extra_support'] + $this->base())
        );
    }

    public function testTheBranchThatHoldsKeepsItsAnswersAndTheOthersFollowTheChainOrder(): void
    {
        $support = ['support_why' => 'Nehéz idők', 'support_who' => 'Kovács Béla', 'support_since' => ' 2026-01-05 '];

        $validated = $this->validate(
            $this->feeForm(),
            ['fee' => 'extra_support', 'fee_reason' => 'self_pays', 'help_note' => 'x', 'help_born' => '2000-01-01'] + $this->base() + $support
        );

        // The else-if branch is hidden while the if branch before it holds, although its own condition holds.
        self::assertSame(
            ['name', 'email', 'fee', 'fee_reason', 'support_why', 'support_who', 'support_since'],
            array_keys($validated->stored())
        );
        self::assertSame('2026-01-05', $validated->stored()['support_since']);
        self::assertSame(' 2026-01-05 ', $validated->submitted()['support_since']);
    }

    public function testAnElseIfBranchHoldsWhenTheBranchesBeforeItDoNotAndItsOwnConditionDoes(): void
    {
        $answers = ['fee_reason' => 'self_pays'] + $this->base() + ['help_note' => 'Szünet', 'support_why' => 'elmarad'];

        self::assertSame(['help_note' => self::REQUIRED], $this->errorsOf($this->feeForm(), ['fee_reason' => 'self_pays'] + $this->base()));
        $validated = $this->validate($this->feeForm(), $answers);
        self::assertSame('Szünet', $validated->stored()['help_note']);
        self::assertArrayNotHasKey('support_why', $validated->stored());
    }

    public function testAnElseBranchHoldsWhenNoBranchBeforeItDoes(): void
    {
        $definition = $this->definition(
            $this->field('choice', 'plan', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]])
            . $this->branch('if', $this->when('plan', 'equals', 'a'), $this->field('text', 'only_a'))
            . $this->branch('else', null, $this->field('text', 'otherwise', ['required' => true]))
        );

        self::assertSame(['otherwise' => self::REQUIRED], $this->errorsOf($definition, ['plan' => 'b'] + $this->slot()));
        self::assertSame(['otherwise' => self::REQUIRED], $this->errorsOf($definition, $this->slot()));
        $validated = $this->validate($definition, ['plan' => 'a', 'only_a' => 'igen', 'otherwise' => 'nem'] + $this->slot());
        self::assertSame('igen', $validated->stored()['only_a']);
        self::assertArrayNotHasKey('otherwise', $validated->stored());
    }

    /** A field that was dropped, not answered or in error is empty to a condition that reads it. */
    private function readsAnEarlierField(): FormDefinition
    {
        $fees = [['value' => 'basic', 'label' => 'Alap'], ['value' => 'extra_support', 'label' => 'Támogatott']];

        return $this->definition(
            $this->field('choice', 'fee', ['options' => $fees])
            . $this->branch('if', $this->when('fee', 'equals', 'extra_support'), $this->field('text', 'why'))
            . $this->field('date', 'born')
            . $this->branch('if', $this->when('why', 'empty'), $this->field('text', 'unexplained', ['required' => true]))
            . $this->branch('if', $this->when('born', 'empty'), $this->field('text', 'no_birth', ['required' => true]))
        );
    }

    public function testAConditionOnAFieldThatWasDroppedReadsItAsEmpty(): void
    {
        $definition = $this->readsAnEarlierField();
        $birth = ['born' => '2010-11-30', 'no_birth' => 'dropped'];

        self::assertSame(['unexplained' => self::REQUIRED], $this->errorsOf($definition, ['fee' => 'basic', 'why' => 'dropped'] + $birth + $this->slot()));
        $validated = $this->validate($definition, ['fee' => 'basic', 'why' => 'dropped', 'unexplained' => 'x'] + $birth + $this->slot());
        self::assertArrayNotHasKey('why', $validated->stored());
        self::assertSame('x', $validated->stored()['unexplained']);
        self::assertArrayNotHasKey('no_birth', $validated->stored());
    }

    public function testAConditionOnAFieldThatWasNotAnsweredOrIsAnsweredReadsItAccordingly(): void
    {
        $definition = $this->readsAnEarlierField();
        $birth = ['born' => '2010-11-30'];

        self::assertSame(['unexplained' => self::REQUIRED], $this->errorsOf($definition, ['fee' => 'extra_support'] + $birth + $this->slot()));
        $validated = $this->validate($definition, ['fee' => 'extra_support', 'why' => 'Mert', 'unexplained' => 'dropped'] + $birth + $this->slot());
        self::assertArrayNotHasKey('unexplained', $validated->stored());
        self::assertSame('Mert', $validated->stored()['why']);
    }

    public function testAConditionOnAFieldInErrorReadsItAsEmpty(): void
    {
        $errors = $this->errorsOf($this->readsAnEarlierField(), ['fee' => 'basic', 'born' => 'nem dátum'] + $this->slot());

        self::assertSame(['born', 'unexplained', 'no_birth'], array_keys($errors));
        self::assertSame('Adj meg egy érvényes dátumot.', $errors['born']);
    }

    public function testAnUndeclaredKeyIsRefusedWhateverElseIsSent(): void
    {
        $this->expectException(InvalidInput::class);
        $this->validate($this->feeForm(), $this->base() + ['nope' => 'x']);
    }

    public function testNullForAFieldThatIsCheckedIsStillRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->validate($this->feeForm(), ['fee' => null] + $this->base());
    }

    public function testNullForAVisibleFieldInABranchThatHoldsIsRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->validate($this->feeForm(), ['fee' => 'extra_support', 'support_why' => null] + $this->base());
    }

    public function testAnUndeclaredKeyIsRefusedBeforeAnythingInABranchIsLookedAt(): void
    {
        $this->expectException(InvalidInput::class);
        $this->validate($this->feeForm(), $this->base() + ['support_why' => null, 'nope' => 'x']);
    }

    public function testAFormWithoutBranchesValidatesAsBefore(): void
    {
        $definition = $this->definition($this->field('text', 'note', ['required' => true]));

        self::assertSame(['note' => self::REQUIRED], $this->errorsOf($definition, ['name' => 'Kovács Anna', 'email' => 'anna@example.org']));
        $validated = $this->validate($definition, ['name' => 'Kovács Anna', 'email' => 'anna@example.org', 'note' => 'x']);
        self::assertSame(['name', 'email', 'note'], array_keys($validated->stored()));
    }
}
