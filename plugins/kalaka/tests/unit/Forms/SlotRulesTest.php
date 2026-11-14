<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\FormBranch;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Engine\SlotRules;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** AC4 and D8: the registration slot has exactly one contact email and one display name, an additional form neither. */
final class SlotRulesTest extends TestCase
{
    private function email(string $key, bool $marked): FormField
    {
        return new FormField($key, 'kalaka/email-field', ['key' => $key, 'contactEmail' => $marked]);
    }

    private function name(string $key, bool $marked): FormField
    {
        return new FormField($key, 'kalaka/name-field', ['key' => $key, 'displayName' => $marked]);
    }

    private function problemOf(FormPlacement $placement, array $fields): ?string
    {
        try {
            (new SlotRules())->check(7, $placement, $fields);
        } catch (SystemFailure $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function testASlotFormWithOneOfEachMarkingIsUsable(): void
    {
        $fields = [$this->email('email', true), $this->name('name', true), $this->email('other', false)];

        self::assertNull($this->problemOf(FormPlacement::Registration, $fields));
    }

    public function testASlotFormWithNoOrTwoContactEmailsIsUnusable(): void
    {
        $none = [$this->email('email', false), $this->name('name', true)];
        $two = [$this->email('a', true), $this->email('b', true), $this->name('name', true)];

        self::assertStringContainsString(
            'contact_email_count',
            (string) $this->problemOf(FormPlacement::Registration, $none)
        );
        self::assertStringContainsString(
            'contact_email_count',
            (string) $this->problemOf(FormPlacement::Registration, $two)
        );
    }

    public function testASlotFormWithNoOrTwoDisplayNamesIsUnusable(): void
    {
        $none = [$this->email('email', true), $this->name('name', false)];
        $two = [$this->email('email', true), $this->name('a', true), $this->name('b', true)];

        self::assertStringContainsString(
            'display_name_count',
            (string) $this->problemOf(FormPlacement::Registration, $none)
        );
        self::assertStringContainsString(
            'display_name_count',
            (string) $this->problemOf(FormPlacement::Registration, $two)
        );
    }

    private function inBranch(FormField $field): FormField
    {
        return new FormField($field->key, $field->blockName, $field->attributes, [new FormBranch([], null)]);
    }

    public function testAMarkedFieldInsideABranchIsUnusableAndNamesItsKey(): void
    {
        $email = $this->email('email', true);
        $name = $this->name('name', true);

        $problem = (string) $this->problemOf(FormPlacement::Registration, [$this->inBranch($email), $name]);
        self::assertStringContainsString('marking_in_branch email', $problem);
        $problem = (string) $this->problemOf(FormPlacement::Registration, [$email, $this->inBranch($name)]);
        self::assertStringContainsString('marking_in_branch name', $problem);
    }

    public function testTheBranchCheckComesBeforeTheCounts(): void
    {
        // No contact email outside the branch would also be a count problem; the branch is named first.
        $problem = (string) $this->problemOf(
            FormPlacement::Registration,
            [$this->inBranch($this->email('email', true)), $this->inBranch($this->name('name', true))]
        );

        self::assertStringContainsString('marking_in_branch email', $problem);
    }

    public function testAnUnmarkedFieldInsideABranchStaysUsable(): void
    {
        $fields = [
            $this->email('email', true),
            $this->name('name', true),
            $this->inBranch($this->email('other', false)),
        ];

        self::assertNull($this->problemOf(FormPlacement::Registration, $fields));
    }

    public function testAnAdditionalFormKeepsItsOwnProblemForAMarkingInABranch(): void
    {
        self::assertStringContainsString(
            'marking_on_additional_form',
            (string) $this->problemOf(FormPlacement::Additional, [$this->inBranch($this->email('email', true))])
        );
    }

    public function testAnAdditionalFormWithNeitherMarkingIsUsableAndWithEitherIsNot(): void
    {
        self::assertNull(
            $this->problemOf(FormPlacement::Additional, [$this->email('email', false), $this->name('name', false)])
        );
        self::assertStringContainsString(
            'marking_on_additional_form',
            (string) $this->problemOf(FormPlacement::Additional, [$this->email('email', true)])
        );
        self::assertStringContainsString(
            'marking_on_additional_form',
            (string) $this->problemOf(FormPlacement::Additional, [$this->name('name', true)])
        );
    }

    public function testAMarkingOnTheWrongTypeOfBlockIsNoMarking(): void
    {
        $text = new FormField(
            'note',
            'kalaka/text-field',
            ['key' => 'note', 'contactEmail' => true, 'displayName' => true]
        );

        self::assertFalse($text->isContactEmail());
        self::assertFalse($text->isDisplayName());
    }

    public function testTheTwoMarkedFieldsCountAsRequiredWhateverTheirAttribute(): void
    {
        self::assertTrue($this->email('email', true)->isRequired());
        self::assertTrue($this->name('name', true)->isRequired());
        self::assertFalse($this->email('email', false)->isRequired());
        self::assertTrue((new FormField('x', 'kalaka/text-field', ['required' => true]))->isRequired());
    }
}
