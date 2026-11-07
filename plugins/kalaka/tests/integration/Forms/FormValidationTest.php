<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Foundation\Api\Error\InvalidInput;

require_once __DIR__ . '/FormsTestCase.php';

/** AC6, AC7 and AC8: server validation of answers against a definition. */
final class FormValidationTest extends FormsTestCase
{
    private const REQUIRED = 'Ezt a mezőt ki kell töltened.';
    private const CHOICE = 'Válassz a megadott lehetőségek közül.';

    private FormDefinition $definition;

    public function set_up(): void
    {
        parent::set_up();

        $sizes = [['value' => 's', 'label' => 'Kicsi'], ['value' => 'm', 'label' => 'Nagy']];
        $extras = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'], ['value' => 'c', 'label' => 'C']];
        $id = $this->slotForm(
            $this->field('phone', 'phone')
            . $this->field('date', 'born')
            . $this->field('text', 'note')
            . $this->field('text', 'story', ['multiline' => true])
            . $this->field('choice', 'size', ['options' => $sizes, 'required' => true])
            . $this->field('choice', 'extras', ['options' => $extras, 'multiple' => true])
            . $this->field('consent', 'gdpr', ['required' => true])
            . $this->field('consent', 'news')
        );
        $this->definition = $this->forms->definition($this->readForm($id));
    }

    /** @return array<string, mixed> answers that pass, for the required fields only */
    private function required(): array
    {
        return ['name' => 'Kovács Anna', 'email' => 'anna@example.org', 'size' => 's', 'gdpr' => true];
    }

    /** @param array<string, mixed> $answers */
    private function validate(array $answers): \Kalaka\Forms\Api\ValidatedAnswers
    {
        return $this->forms->validate($this->definition, $answers);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, string> the messages of the fields
     */
    private function errorsOf(array $answers): array
    {
        try {
            $this->validate($answers);
        } catch (InvalidAnswers $e) {
            return $e->fields;
        }
        self::fail('The answers were accepted.');
    }

    public function testEveryCoreTypeWithValidAnswersGivesTheSentAndTheStoredForms(): void
    {
        $answers = [
            'name' => 'Árvíztűrő  Tükörfúrógép',
            'email' => ' anna@example.org ',
            'phone' => '+36 30 123-4567',
            'born' => '1991-05-17',
            'note' => ' egy sor ',
            'story' => "első\nmásodik",
            'size' => 'm',
            'extras' => ['c', 'a'],
            'gdpr' => true,
            'news' => true,
        ];

        $validated = $this->validate($answers);

        self::assertSame($answers, $validated->submitted());
        self::assertSame(
            [
                'name' => 'Árvíztűrő Tükörfúrógép',
                'email' => 'anna@example.org',
                'phone' => '+36301234567',
                'born' => '1991-05-17',
                'note' => 'egy sor',
                'story' => "első\nmásodik",
                'size' => 'm',
                'extras' => '["a","c"]',
                'gdpr' => '1',
                'news' => '1',
            ],
            $validated->stored()
        );
    }

    public function testAnExactlyWrittenHungarianNameRoundTrips(): void
    {
        $validated = $this->validate(['name' => 'Árvíztűrő Tükörfúrógép'] + $this->required());

        self::assertSame('Árvíztűrő Tükörfúrógép', $validated->stored()['name']);
        self::assertSame('Árvíztűrő Tükörfúrógép', $validated->submitted()['name']);
    }

    public function testAnEmailIsKeptAsTyped(): void
    {
        $validated = $this->validate(['email' => 'Anna.Kovacs+tabor@Example.ORG'] + $this->required());

        self::assertSame('Anna.Kovacs+tabor@Example.ORG', $validated->stored()['email']);
    }

    public function testADateOfBirth35YearsBeforeTheEventIsAccepted(): void
    {
        $validated = $this->validate(['born' => '1992-07-05'] + $this->required());

        self::assertSame('1992-07-05', $validated->stored()['born']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function blanks(): iterable
    {
        yield 'an empty string' => [''];
        yield 'whitespace' => ["  \t\n "];
        yield 'a non-breaking space' => ["\u{A0}"];
        yield 'an empty list' => [[]];
        yield 'false' => [false];
    }

    /** @dataProvider blanks */
    public function testAnOptionalFieldLeftBlankIsAbsentFromBothMaps(mixed $blank): void
    {
        $answers = $this->required() + ['phone' => $blank, 'born' => $blank, 'note' => $blank, 'extras' => $blank, 'news' => $blank];

        $validated = $this->validate($answers);

        foreach (['phone', 'born', 'note', 'extras', 'news', 'story'] as $key) {
            self::assertArrayNotHasKey($key, $validated->stored(), $key);
            self::assertArrayNotHasKey($key, $validated->submitted(), $key);
        }
        self::assertSame(['name', 'email', 'size', 'gdpr'], array_keys($validated->stored()));
    }

    public function testAnOptionalFieldLeftOutIsAbsentToo(): void
    {
        $validated = $this->validate($this->required());

        self::assertSame(['name', 'email', 'size', 'gdpr'], array_keys($validated->stored()));
        self::assertSame($this->required(), $validated->submitted());
    }

    /** @dataProvider blanks */
    public function testARequiredFieldBlankOrMissingIsTheRequiredMessage(mixed $blank): void
    {
        foreach (['name', 'email', 'size'] as $key) {
            $expected = [$key => self::REQUIRED];

            self::assertSame($expected, $this->errorsOf([$key => $blank] + $this->required()), $key . ' blank');
            $without = $this->required();
            unset($without[$key]);
            self::assertSame($expected, $this->errorsOf($without), $key . ' missing');
        }
    }

    public function testTheTwoMarkedFieldsAreRequiredWhateverTheirAttribute(): void
    {
        self::assertTrue($this->definition->field('name')?->isRequired());
        self::assertTrue($this->definition->field('email')?->isRequired());
        self::assertSame(['name' => self::REQUIRED, 'email' => self::REQUIRED], $this->errorsOf(['size' => 's', 'gdpr' => true]));
    }

    public function testARequiredConsentNotGivenIsTheConsentMessage(): void
    {
        $consent = 'A továbblépéshez ezt el kell fogadnod.';

        self::assertSame(['gdpr' => $consent], $this->errorsOf(['gdpr' => false] + $this->required()));
        $without = $this->required();
        unset($without['gdpr']);
        self::assertSame(['gdpr' => $consent], $this->errorsOf($without));
    }

    public function testAnInvalidEmailIsTheEmailMessage(): void
    {
        foreach (['nem-email', 'anna@', '@example.org', 'anna example@example.org'] as $email) {
            self::assertSame(
                ['email' => 'Adj meg egy érvényes e-mail-címet.'],
                $this->errorsOf(['email' => $email] + $this->required()),
                $email
            );
        }
    }

    public function testAPhoneInNationalFormIsRefusedWithTheExample(): void
    {
        self::assertSame(
            ['phone' => 'Add meg a telefonszámot nemzetközi formátumban, például: +36301234567.'],
            $this->errorsOf(['phone' => '06301234567'] + $this->required())
        );
    }

    public function testAnImpossibleOrMisformattedDateIsTheDateMessage(): void
    {
        foreach (['2027-02-30', '2027.02.03'] as $date) {
            self::assertSame(
                ['born' => 'Adj meg egy érvényes dátumot.'],
                $this->errorsOf(['born' => $date] + $this->required()),
                $date
            );
        }
    }

    public function testATextOverItsLimitIsTheTooLongMessageWithTheLimit(): void
    {
        self::assertSame(
            ['note' => 'Legfeljebb 500 karaktert írhatsz ide.'],
            $this->errorsOf(['note' => str_repeat('á', 501)] + $this->required())
        );
        self::assertSame(
            ['story' => 'Legfeljebb 5000 karaktert írhatsz ide.'],
            $this->errorsOf(['story' => str_repeat('á', 5001)] + $this->required())
        );
        self::assertSame(
            ['name' => 'Legfeljebb 200 karaktert írhatsz ide.'],
            $this->errorsOf(['name' => str_repeat('á', 201)] + $this->required())
        );
        self::assertSame(
            str_repeat('á', 500),
            $this->validate(['note' => str_repeat('á', 500)] + $this->required())->stored()['note']
        );
    }

    public function testAChoiceValueNotAmongTheOptionsOrOfTheWrongShapeIsTheChoiceMessage(): void
    {
        self::assertSame(['size' => self::CHOICE], $this->errorsOf(['size' => 'xl'] + $this->required()));
        self::assertSame(['size' => self::CHOICE], $this->errorsOf(['size' => ['s']] + $this->required()));
        self::assertSame(['size' => self::CHOICE], $this->errorsOf(['size' => ['s', 'm']] + $this->required()));
        self::assertSame(['extras' => self::CHOICE], $this->errorsOf(['extras' => ['a', 'z']] + $this->required()));
        self::assertSame(['extras' => self::CHOICE], $this->errorsOf(['extras' => 'a'] + $this->required()));
    }

    public function testAMultipleChoiceIsStoredInTheOrderOfTheOptionsWithoutRepeats(): void
    {
        $validated = $this->validate(['extras' => ['c', 'b', 'c']] + $this->required());

        self::assertSame('["b","c"]', $validated->stored()['extras']);
    }

    public function testErrorsInSeveralFieldsComeInOneException(): void
    {
        $errors = $this->errorsOf(['name' => '', 'email' => 'nem-email', 'phone' => '0630', 'born' => 'x', 'size' => 'xl', 'gdpr' => false]);

        self::assertSame(['name', 'email', 'phone', 'born', 'size', 'gdpr'], array_keys($errors));
        self::assertSame(self::REQUIRED, $errors['name']);
        self::assertSame(self::CHOICE, $errors['size']);
    }

    public function testTheExceptionCarriesItsStatusCodeTextAndFieldsAndIsNotAudited(): void
    {
        try {
            $this->validate(['email' => 'nem-email'] + $this->required());
            self::fail('Accepted.');
        } catch (InvalidAnswers $e) {
            self::assertSame(400, $e->status());
            self::assertSame('kalaka_invalid_answers', $e->errorCode());
            self::assertFalse($e->isAudited());
            self::assertSame('Néhány válasz hiányzik vagy hibás. Javítsd a megjelölt mezőket.', $e->getMessage());
            $error = $e->toWpError();
            self::assertSame('kalaka_invalid_answers', $error->get_error_code());
            self::assertSame(
                ['status' => 400, 'fields' => ['email' => 'Adj meg egy érvényes e-mail-címet.']],
                $error->get_error_data()
            );
        }
    }

    public function testAKeyThatIsNotADeclaredFieldIsInvalidInputAndNothingIsAccepted(): void
    {
        foreach (['extra', 'Name', '', 'size ', 'page'] as $key) {
            try {
                $this->validate([$key => 'x'] + $this->required());
                self::fail('The key ' . $key . ' was accepted.');
            } catch (InvalidInput $e) {
                self::assertSame('kalaka_invalid_input', $e->errorCode());
            }
        }

        $this->expectException(InvalidInput::class);
        $this->validate($this->required() + [0 => 'x']);
    }

    public function testAnUndeclaredKeyWinsOverFieldErrors(): void
    {
        $this->expectException(InvalidInput::class);

        $this->validate(['extra' => 'x', 'email' => 'nem-email'] + $this->required());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function wrongTypes(): iterable
    {
        yield 'a list for a text' => ['note', ['a']];
        yield 'a list for a name' => ['name', ['Anna']];
        yield 'a number for a phone' => ['phone', 36301234567];
        yield 'a string for a consent' => ['gdpr', 'yes'];
        yield 'a number for a consent' => ['news', 1];
        yield 'a map for a choice' => ['extras', ['x' => 'a']];
        yield 'a number in a multiple choice' => ['extras', [1]];
        yield 'a number for a single choice' => ['size', 1];
        yield 'null' => ['note', null];
    }

    /** @dataProvider wrongTypes */
    public function testAValueOfTheWrongJsonTypeIsInvalidInput(string $key, mixed $value): void
    {
        $this->expectException(InvalidInput::class);

        $this->validate([$key => $value] + $this->required());
    }
}
