<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Boot;

require_once __DIR__ . '/FormsTestCase.php';

/** A rating from 1 to 5, sent as a string. */
final class RatingFieldType extends AbstractFieldType
{
    public function blockName(): string
    {
        return 'kalaka/test-rating-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }

        return preg_match('/\A[1-5]\z/', $input->value) === 1
            ? FieldAnswer::stored($input->value)
            : FieldAnswer::error('Egy és öt között.');
    }
}

/** A test type that says its value affects money, in a namespace of its own. */
final class SurchargeFieldType extends AbstractFieldType implements MoneyRelevantFieldType
{
    public function blockName(): string
    {
        return 'acme/test-surcharge-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        return FieldAnswer::stored('x');
    }
}

final class TestFieldTypesModule extends AbstractModule
{
    public function id(): string
    {
        return 'forms_test';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function contribute(Contributions $to): void
    {
        $to->fieldType(new RatingFieldType());
        $to->fieldType(new SurchargeFieldType());
    }

    public function boot(): void
    {
    }
}

/** AC10: the field-type registry. */
final class FieldTypeRegistryTest extends FormsTestCase
{
    private Contributions $before;

    public function set_up(): void
    {
        parent::set_up();

        $this->before = Contributions::current();
    }

    public function tear_down(): void
    {
        Contributions::record($this->before);
        LoadState::record([]);

        parent::tear_down();
    }

    /** Boots Forms and a test module that contributes two types, as `kalaka_register_modules` allows. */
    private function bootWithTestTypes(): void
    {
        $register = static function (\Kalaka\Foundation\Api\Module\Modules $modules): void {
            $modules->add(new TestFieldTypesModule());
        };
        add_action('kalaka_register_modules', $register);
        try {
            $boot = new Boot([new \Kalaka\Forms\Module()]);
            $boot->run();
            self::assertSame([], $boot->failures());
        } finally {
            remove_action('kalaka_register_modules', $register);
        }
    }

    public function testTheCoreTypesAreInTheRegistryAndNoneIsMoneyRelevant(): void
    {
        $types = Contributions::current()->fieldTypes();

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
            array_keys($types)
        );
        foreach ($types as $name => $type) {
            self::assertSame($name, $type->blockName());
            self::assertNotInstanceOf(MoneyRelevantFieldType::class, $type, $name);
        }
    }

    public function testTheRegistryIsEmptyBeforeTheBootHasFinished(): void
    {
        $fresh = new Contributions();

        self::assertSame([], $fresh->fieldTypes());
    }

    public function testATypeFromAnotherModuleIsReadAndValidated(): void
    {
        $this->bootWithTestTypes();
        $id = $this->slotForm(
            $this->block('kalaka/test-rating-field', ['key' => 'stars', 'required' => true])
            . $this->block('acme/test-surcharge-field', ['key' => 'extra'])
        );
        $definition = $this->forms->definition($this->readForm($id));

        self::assertSame(['name', 'email', 'stars', 'extra'], array_map(static fn ($field): string => $field->key, $definition->fields));

        $validated = $this->forms->validate($definition, ['name' => 'Anna', 'email' => 'anna@example.org', 'stars' => '4']);
        self::assertSame('4', $validated->stored()['stars']);

        foreach ([['stars' => '9', 'message' => 'Egy és öt között.'], ['stars' => '', 'message' => 'Ezt a mezőt ki kell töltened.']] as $case) {
            try {
                $this->forms->validate($definition, ['name' => 'Anna', 'email' => 'anna@example.org', 'stars' => $case['stars']]);
                self::fail('Accepted.');
            } catch (InvalidAnswers $e) {
                self::assertSame(['stars' => $case['message']], $e->fields);
            }
        }
        $this->expectException(InvalidInput::class);
        $this->forms->validate($definition, ['name' => 'Anna', 'email' => 'anna@example.org', 'stars' => 4]);
    }

    public function testATypeThatSaysItIsMoneyRelevantReportsSoThroughTheRegistry(): void
    {
        $this->bootWithTestTypes();

        $types = Contributions::current()->fieldTypes();

        self::assertInstanceOf(MoneyRelevantFieldType::class, $types['acme/test-surcharge-field']);
        self::assertNotInstanceOf(MoneyRelevantFieldType::class, $types['kalaka/test-rating-field']);
        self::assertNotInstanceOf(MoneyRelevantFieldType::class, $types['kalaka/name-field']);
    }

    public function testAKalakaBlockThatIsNeitherATypeNorARegisteredBlockMakesTheFormUnusable(): void
    {
        $id = $this->slotForm($this->block('kalaka/test-rating-field', ['key' => 'stars']));

        try {
            $this->forms->definition($this->readForm($id));
            self::fail('The form was read.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('unknown_field_block', $e->getMessage());
            self::assertStringContainsString('kalaka/test-rating-field', $e->getMessage());
        }

        $this->bootWithTestTypes();
        self::assertSame(['name', 'email', 'stars'], array_map(
            static fn ($field): string => $field->key,
            $this->forms->definition($this->readForm($id))->fields
        ));
    }

    public function testAFieldTypeIsContributedOncePerBlock(): void
    {
        $contributions = new Contributions();
        $contributions->fieldType(new RatingFieldType());

        $this->expectException(\InvalidArgumentException::class);
        $contributions->fieldType(new RatingFieldType());
    }

    public function testAModuleThatContributesTheSameBlockTwiceFailsToLoad(): void
    {
        $register = static function (\Kalaka\Foundation\Api\Module\Modules $modules): void {
            $modules->add(new TestFieldTypesModule());
        };
        add_action('kalaka_register_modules', $register);
        $log = ini_set('error_log', '/dev/null');
        try {
            $boot = new Boot([new \Kalaka\Forms\Module(), new TestFieldTypesModule()]);
            $boot->run();

            self::assertNotSame([], $boot->failures());
            self::assertFalse(LoadState::current()->isOpen());
        } finally {
            remove_action('kalaka_register_modules', $register);
            ini_set('error_log', (string) $log);
        }
    }
}
