<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\FieldMarkup;
use Kalaka\Forms\Engine\FieldType\ScaleFieldType;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The scale field (`kalaka/scale-field`): its range, its answer, its number value and text, conditions on it,
 * its prefill, its markup and its settings as the editor saves them.
 */
final class ScaleFieldTest extends FormsTestCase
{
    private const LABELS = ['egyáltalán nem', 'kicsit', 'közepesen', 'inkább igen', 'teljesen'];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
    }

    /** @param array<string, mixed> $attributes */
    private function input(mixed $value, array $attributes = []): FieldTypeInput
    {
        return new FieldTypeInput($value, $attributes);
    }

    public function testTheRangeDefaultsToOneToFiveAndHasTwoToElevenIntegerPoints(): void
    {
        self::assertSame([1, 5], ScaleFieldType::range([]));
        self::assertSame([1, 7], ScaleFieldType::range(['max' => 7]));
        self::assertSame([0, 10], ScaleFieldType::range(['min' => 0, 'max' => 10]));
        self::assertSame([-2, 2], ScaleFieldType::range(['min' => -2, 'max' => 2]));
        self::assertSame([1, 2], ScaleFieldType::range(['min' => 1, 'max' => 2]));

        self::assertNull(ScaleFieldType::range(['min' => 1, 'max' => 1]), 'one point');
        self::assertNull(ScaleFieldType::range(['min' => 5, 'max' => 1]), 'reversed');
        self::assertNull(ScaleFieldType::range(['min' => 0, 'max' => 11]), 'twelve points');
        self::assertNull(ScaleFieldType::range(['min' => '1', 'max' => 5]), 'a string');
        self::assertNull(ScaleFieldType::range(['min' => 1.0, 'max' => 5]), 'a float');
        self::assertSame([-100, -96], ScaleFieldType::range(['min' => -100, 'max' => -96]));
        self::assertNull(ScaleFieldType::range(['min' => 1000000000, 'max' => 1000000004]), 'beyond ±100');
        self::assertNull(ScaleFieldType::range(['min' => -101, 'max' => -97]), 'below −100');
    }

    public function testAFormWithAnInvalidRangeIsAProblemNamingTheKey(): void
    {
        foreach ([['min' => 3, 'max' => 3], ['min' => 1, 'max' => 20], ['max' => 'öt']] as $attributes) {
            $id = $this->insertForm($this->slotFields() . $this->field('scale', 'rating', $attributes));
            try {
                $this->forms->definition($this->readForm($id));
                self::fail('An invalid scale was read.');
            } catch (SystemFailure $e) {
                self::assertStringEndsWith('invalid_scale rating', $e->getMessage());
            }
        }
        $fine = $this->insertForm($this->slotFields() . $this->field('scale', 'rating'));
        self::assertNotNull($this->forms->definition($this->readForm($fine))->field('rating'));
    }

    public function testAnAnswerIsAPointOfTheScaleStoredAsItsInteger(): void
    {
        $type = new ScaleFieldType();
        $seven = ['min' => 1, 'max' => 7];

        self::assertSame('1', $type->answer($this->input('1', $seven))->value);
        self::assertSame('7', $type->answer($this->input('7', $seven))->value);
        self::assertSame('0', $type->answer($this->input('0', ['min' => 0, 'max' => 10]))->value);
        self::assertSame('-2', $type->answer($this->input('-2', ['min' => -2, 'max' => 2]))->value);
        self::assertSame('5', $type->answer($this->input('5'))->value, 'the default range');

        foreach (['0', '8', '6', '07', '+3', '3.0', '3 ', 'három', ''] as $sent) {
            $answer = $type->answer($this->input($sent, $sent === '6' ? [] : $seven));
            self::assertNull($answer->value, $sent);
            self::assertSame('Válassz a megadott lehetőségek közül.', $answer->error, $sent);
        }
    }

    public function testAnAnswerOfAnotherJsonTypeIsInvalidInput(): void
    {
        foreach ([3, ['3'], true] as $sent) {
            try {
                (new ScaleFieldType())->answer($this->input($sent));
                self::fail('A non-string answer was accepted.');
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheValueIsANumberAndItsTextAddsThePointsLabel(): void
    {
        $type = new ScaleFieldType();
        $attributes = ['min' => 1, 'max' => 5, 'pointLabels' => self::LABELS];

        self::assertSame(ValueType::Number, $type->valueType());
        $value = $type->value($this->input('4', $attributes));
        self::assertSame(ValueType::Number, $value->type);
        self::assertSame('4', $value->data);
        self::assertSame('4 – inkább igen', $value->text);

        // Labels run from the first point; a point without one, or with an empty one, is its number.
        $fromZero = ['min' => 0, 'pointLabels' => self::LABELS];
        self::assertSame('0 – egyáltalán nem', $type->value($this->input('0', $fromZero))->text);
        self::assertSame('3', $type->value($this->input('3', ['pointLabels' => ['a', 'b', ' ']]))->text);
        self::assertSame('3', $type->value($this->input('3'))->text);
        self::assertSame('2 – b', $type->value($this->input('2', ['pointLabels' => ['a', '<em>b</em>']]))->text);

        self::assertTrue($type->value($this->input(''))->isEmpty());
        self::assertSame(ValueType::Number, $type->value($this->input('x'))->type);
    }

    private function conditionForm(string $operator, string $value): FormDefinition
    {
        $condition = ['match' => 'all', 'items' => [['key' => 'rating', 'operator' => $operator, 'value' => $value]]];

        return $this->forms->definition($this->readForm($this->slotForm(
            $this->field('scale', 'rating', ['max' => 7])
                . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $condition]) . ' -->'
                . $this->field('text', 'why') . '<!-- /wp:kalaka/if -->'
        )));
    }

    private function shows(FormDefinition $definition, ?string $rating): bool
    {
        $answers = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org']
            + ($rating === null ? [] : ['rating' => $rating]);
        $facts = new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::none(ValueType::Date),
            EventValues::END => Value::none(ValueType::Date),
        ]);

        return $this->forms->availability($definition, $answers, $facts)->shows('why');
    }

    public function testAConditionComparesAScaleAsANumber(): void
    {
        $equals = $this->conditionForm('equals', '5');
        self::assertTrue($this->shows($equals, '5'));
        self::assertFalse($this->shows($equals, '6'));

        $less = $this->conditionForm('lessThan', '3');
        self::assertTrue($this->shows($less, '2'));
        self::assertFalse($this->shows($less, '3'));

        // As a number, not as text: as text, 7 would not be less than 10.
        self::assertTrue($this->shows($this->conditionForm('lessThan', '10'), '7'));

        $greater = $this->conditionForm('greaterThan', '4');
        self::assertTrue($this->shows($greater, '7'));
        self::assertFalse($this->shows($greater, '4'));
        self::assertFalse($this->shows($greater, null), 'an unanswered scale is empty');
    }

    public function testAConditionValueThatIsNotANumberIsAProblem(): void
    {
        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('invalid_condition');

        $this->conditionForm('equals', 'öt');
    }

    public function testASubmitStoresThePointAndRefusesOneOutsideTheRange(): void
    {
        $definition = $this->forms->definition($this->readForm($this->slotForm(
            $this->field('scale', 'rating', ['max' => 7, 'required' => true])
        )));
        $slot = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

        self::assertSame('6', $this->forms->validate($definition, $slot + ['rating' => '6'])->stored()['rating']);
        try {
            $this->forms->validate($definition, $slot + ['rating' => '8']);
            self::fail('A point outside the range was accepted.');
        } catch (InvalidAnswers $e) {
            self::assertSame(['rating' => 'Válassz a megadott lehetőségek közül.'], $e->fields);
        }
    }

    public function testZeroIsAnAnswerNotABlank(): void
    {
        $definition = $this->forms->definition($this->readForm($this->slotForm(
            $this->field('scale', 'rating', ['min' => 0, 'max' => 10, 'required' => true])
        )));

        $stored = $this->forms->validate(
            $definition,
            ['name' => 'Teszt Elek', 'email' => 'teszt@example.org', 'rating' => '0']
        )->stored();

        self::assertSame('0', $stored['rating']);
    }

    public function testPrefillKeepsAPointInTheRangeAndDropsAnythingElse(): void
    {
        $type = new ScaleFieldType();
        $seven = ['min' => 1, 'max' => 7];

        self::assertSame('7', $type->prefill($this->input('7', $seven)));
        self::assertSame('1', $type->prefill($this->input('1', $seven)));
        foreach (['0', '8', '07', '4.5', 'négy', ''] as $stored) {
            self::assertNull($type->prefill($this->input($stored, $seven)), $stored);
        }
        // The quiz's handoff (`kalaka[<key>]=n`, S74) checks each value with `answer()`: out of range is an error,
        // so the value is dropped.
        self::assertNotNull($type->answer($this->input('8', $seven))->error);
    }

    /** @param array<string, mixed> $attributes */
    private function render(array $attributes): string
    {
        return render_block([
            'blockName' => ScaleFieldType::BLOCK_NAME,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    private function squeezed(string $html): string
    {
        $html = trim((string) preg_replace('/\s+/', ' ', $html));

        return str_replace(['> <', ' </'], ['><', '</'], $html);
    }

    public function testItRendersAFieldsetWithTheTitleAsLegendAndARadioPerPoint(): void
    {
        $html = $this->render([
            'key' => 'rating',
            'label' => 'Mennyire?',
            'required' => true,
            'min' => 1,
            'max' => 3,
            'pointLabels' => ['kevéssé', '', 'nagyon'],
        ]);

        self::assertSame(
            '<div class="kalaka-field wp-block-kalaka-scale-field" data-kalaka-field="rating">'
            . '<fieldset><legend>Mennyire?<span class="kalaka-field__required" aria-hidden="true">*</span></legend>'
            . '<label><input type="radio" name="rating" value="1" required> kevéssé</label>'
            . '<label><input type="radio" name="rating" value="2" required> 2</label>'
            . '<label><input type="radio" name="rating" value="3" required> nagyon</label></fieldset>'
            . '<span id="kalaka-field-rating-error" class="kalaka-field__error" hidden></span></div>',
            $this->squeezed($html)
        );
        self::assertStringNotContainsString('style', $html);
    }

    public function testWithItsDefaultsItRendersFivePointsAndOptionalNoRequired(): void
    {
        $html = $this->render(['key' => 'rating', 'label' => 'Mennyire?']);

        self::assertSame(5, substr_count($html, 'type="radio"'));
        self::assertMatchesRegularExpression('#value="5"[^>]*>\s*5\s*</label>#', $html);
        self::assertStringNotContainsString('required', $html);
    }

    public function testAPrefilledPointIsChecked(): void
    {
        $html = FieldMarkup::withPrefill(
            ['rating' => '4'],
            fn (): string => $this->render(['key' => 'rating', 'label' => 'Mennyire?', 'max' => 7])
        );

        self::assertMatchesRegularExpression('#value="4"[^>]* checked#', $html);
        self::assertSame(1, substr_count($html, ' checked'));
    }

    public function testTheEditorsSettingsAreSavedThroughRest(): void
    {
        $user = self::factory()->user->create();
        $account = get_userdata($user);
        self::assertInstanceOf(\WP_User::class, $account);
        $account->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);
        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
        $request->set_body_params([
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->block('kalaka/name-field', ['label' => 'Név', 'displayName' => true])
                . $this->block('kalaka/email-field', ['label' => 'E-mail', 'contactEmail' => true])
                . $this->block(
                    ScaleFieldType::BLOCK_NAME,
                    ['label' => 'Mennyire?', 'min' => 0, 'max' => 6, 'pointLabels' => ['semmennyire', '', 'nagyon']]
                ),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        $response = rest_do_request($request);

        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        $field = $this->forms->definition($this->readForm((int) $data['id']))->field('mennyire');
        self::assertNotNull($field);
        self::assertSame(0, $field->attributes['min']);
        self::assertSame(6, $field->attributes['max']);
        self::assertSame(['semmennyire', '', 'nagyon'], $field->attributes['pointLabels']);
    }
}
