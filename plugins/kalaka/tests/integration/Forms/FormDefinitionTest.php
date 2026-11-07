<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Foundation\Api\Error\SystemFailure;

require_once __DIR__ . '/FormsTestCase.php';

/** AC3 and AC4: reading a form's definition from its block markup, and the slot's rules. */
final class FormDefinitionTest extends FormsTestCase
{
    private function definitionOf(int $formId): FormDefinition
    {
        return $this->forms->definition($this->readForm($formId));
    }

    /** @return list<string> the keys of a definition's fields, in order */
    private function keys(FormDefinition $definition): array
    {
        return array_map(static fn ($field): string => $field->key, $definition->fields);
    }

    /** Asserts that reading the form fails with this problem code and names this key, never anything authored. */
    private function assertProblem(int $formId, string $code, string $key, string ...$neverNamed): void
    {
        try {
            $this->definitionOf($formId);
            self::fail('Problem ' . $code . ' was not found.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString($code, $e->getMessage());
            self::assertStringContainsString($key, $e->getMessage());
            foreach ($neverNamed as $text) {
                self::assertStringNotContainsString($text, $e->getMessage());
            }
        }
    }

    private function wrapped(string $inner): string
    {
        return '<!-- wp:heading --><h2 class="wp-block-heading">Cím</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Szöveg</p><!-- /wp:paragraph -->'
            . '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
    }

    public function testTheDefinitionListsExactlyTheFieldBlocksInDocumentOrderWhateverTheyAreNestedIn(): void
    {
        $options = [['value' => 'kicsi', 'label' => 'Kicsi'], ['value' => 'nagy', 'label' => 'Nagy']];
        $content = $this->field('name', 'name', ['displayName' => true, 'required' => true])
            . $this->wrapped($this->field('email', 'email', ['contactEmail' => true]))
            . $this->field('phone', 'phone', ['label' => 'Telefon'])
            . $this->field('date', 'born')
            . $this->field('text', 'story', ['multiline' => true])
            . $this->field('choice', 'size', ['options' => $options, 'multiple' => true])
            . $this->field('consent', 'gdpr', ['required' => true]);

        $definition = $this->definitionOf($this->insertForm($content));

        self::assertSame(['name', 'email', 'phone', 'born', 'story', 'size', 'gdpr'], $this->keys($definition));
        self::assertSame(
            [
                'kalaka/name-field',
                'kalaka/email-field',
                'kalaka/phone-field',
                'kalaka/date-field',
                'kalaka/text-field',
                'kalaka/choice-field',
                'kalaka/consent-field',
            ],
            array_map(static fn ($field): string => $field->blockName, $definition->fields)
        );
        $size = $definition->field('size');
        self::assertNotNull($size);
        self::assertSame($options, $size->attributes['options']);
        self::assertTrue($size->attributes['multiple']);
        self::assertSame('Telefon', $definition->field('phone')?->label());
        self::assertTrue($definition->field('story')?->attributes['multiline']);
        self::assertTrue($definition->field('name')?->isDisplayName());
        self::assertTrue($definition->field('email')?->isContactEmail());
        self::assertNull($definition->field('Cím'));
    }

    public function testAFormWithoutFieldsHasAnEmptyDefinition(): void
    {
        $id = $this->insertForm('<!-- wp:paragraph --><p>Semmi</p><!-- /wp:paragraph -->', ['kalaka_placement' => 'additional']);

        self::assertSame([], $this->definitionOf($id)->fields);
    }

    public function testAFieldWithoutAKeyIsAProblem(): void
    {
        $this->assertProblem(
            $this->insertForm($this->slotFields() . $this->block('kalaka/text-field', ['label' => 'Titkos címke'])),
            'missing_key',
            'kalaka/text-field',
            'Titkos címke'
        );
        $this->assertProblem(
            $this->insertForm($this->slotFields() . $this->block('kalaka/text-field', ['key' => ''])),
            'missing_key',
            'kalaka/text-field'
        );
        $this->assertProblem(
            $this->insertForm($this->slotFields() . $this->block('kalaka/text-field', ['key' => 12])),
            'missing_key',
            'kalaka/text-field'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'upper case' => ['Note'];
        yield 'starts with a digit' => ['1note'];
        yield 'starts with an underscore' => ['_note'];
        yield 'hyphen' => ['my-note'];
        yield 'accent' => ['jegyzet_á'];
        yield 'space' => ['my note'];
        yield '65 characters' => [str_repeat('a', 65)];
    }

    /** @dataProvider invalidKeys */
    public function testAKeyOutsideThePatternIsAProblem(string $key): void
    {
        $id = $this->insertForm($this->slotFields() . $this->field('text', $key, ['label' => 'Titkos címke']));

        $this->assertProblem($id, 'invalid_key', $key, 'Titkos címke');
    }

    public function testKeysOf1And64CharactersAreFine(): void
    {
        $long = 'a' . str_repeat('9', 63);
        $id = $this->insertForm($this->slotFields() . $this->field('text', 'a') . $this->field('text', $long));

        self::assertSame(['name', 'email', 'a', $long], $this->keys($this->definitionOf($id)));
    }

    public function testARepeatedKeyIsAProblemAcrossTypesAndNesting(): void
    {
        $id = $this->insertForm(
            $this->slotFields() . $this->field('text', 'note') . $this->wrapped($this->field('date', 'note', ['label' => 'Titkos címke']))
        );

        $this->assertProblem($id, 'duplicate_key', 'note', 'Titkos címke');
    }

    public function testAChoiceNeedsValidAndDistinctOptions(): void
    {
        $bad = [
            'no options' => [],
            'an option without a value' => [['label' => 'Titkos opció']],
            'a value not a string' => [['value' => 3, 'label' => 'Titkos opció']],
            'a value with a capital' => [['value' => 'Kicsi', 'label' => 'Titkos opció']],
            'a value starting with a hyphen' => [['value' => '-a', 'label' => 'Titkos opció']],
            'a value of 65 characters' => [['value' => str_repeat('a', 65), 'label' => 'Titkos opció']],
            'a repeated value' => [['value' => 'a', 'label' => 'Titkos opció'], ['value' => 'a', 'label' => 'Más']],
        ];

        foreach ($bad as $why => $options) {
            $id = $this->insertForm($this->slotFields() . $this->field('choice', 'pick', ['options' => $options]));
            $this->assertProblem($id, 'invalid_options', 'pick', 'Titkos opció');
            self::assertNotEmpty($why);
        }

        $noAttribute = $this->insertForm($this->slotFields() . $this->block('kalaka/choice-field', ['key' => 'pick']));
        $this->assertProblem($noAttribute, 'invalid_options', 'pick');
    }

    public function testAnUnknownKalakaBlockIsAProblemButARegisteredBlockIsNotAField(): void
    {
        $this->assertProblem(
            $this->insertForm($this->slotFields() . $this->block('kalaka/rating-field', ['key' => 'stars'])),
            'unknown_field_block',
            'kalaka/rating-field'
        );

        register_block_type('kalaka/test-note', ['api_version' => 3]);
        try {
            $id = $this->insertForm($this->slotFields() . $this->block('kalaka/test-note', ['key' => 'notafield']));

            self::assertSame(['name', 'email'], $this->keys($this->definitionOf($id)));
        } finally {
            unregister_block_type('kalaka/test-note');
        }
    }

    public function testBlocksOfOtherNamespacesAreIgnored(): void
    {
        $id = $this->insertForm($this->slotFields() . $this->block('acme/anything', ['key' => 'x']));

        self::assertSame(['name', 'email'], $this->keys($this->definitionOf($id)));
    }

    public function testASlotFormNeedsExactlyOneOfEachMarking(): void
    {
        $name = $this->field('name', 'name', ['displayName' => true]);
        $email = $this->field('email', 'email', ['contactEmail' => true]);

        self::assertSame(['name', 'email'], $this->keys($this->definitionOf($this->insertForm($name . $email))));

        $this->assertProblem($this->insertForm($name . $this->field('email', 'email')), 'contact_email_count', 'form');
        $this->assertProblem($this->insertForm($name), 'contact_email_count', 'form');
        $this->assertProblem($this->insertForm($name . $email . $this->field('email', 'other', ['contactEmail' => true])), 'contact_email_count', 'form');
        $this->assertProblem($this->insertForm($email . $this->field('name', 'name')), 'display_name_count', 'form');
        $this->assertProblem($this->insertForm($email), 'display_name_count', 'form');
        $this->assertProblem($this->insertForm($email . $name . $this->field('name', 'other', ['displayName' => true])), 'display_name_count', 'form');
    }

    public function testAnAdditionalFormHasNeitherMarking(): void
    {
        $meta = ['kalaka_placement' => 'additional'];
        $plain = $this->field('name', 'name') . $this->field('email', 'email');

        self::assertSame(['name', 'email'], $this->keys($this->definitionOf($this->insertForm($plain, $meta))));

        $this->assertProblem(
            $this->insertForm($this->field('email', 'email', ['contactEmail' => true]), $meta),
            'marking_on_additional_form',
            'form'
        );
        $this->assertProblem(
            $this->insertForm($this->field('name', 'name', ['displayName' => true]), $meta),
            'marking_on_additional_form',
            'form'
        );
    }
}
