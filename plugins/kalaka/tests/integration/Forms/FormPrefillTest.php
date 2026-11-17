<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\Engine\FieldType\ConsentFieldType;
use Kalaka\Forms\Engine\FieldType\TextFieldType;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\Engine\FormPageView;
use Kalaka\Forms\EventFormHost;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * A form opens with its host's stored values (`FormHost::prefill()`): each turned into its field's answer by
 * `FieldType::prefill()`, the form walked for those answers, and each field rendered with its own.
 */
final class FormPrefillTest extends FormsTestCase
{
    private const SIZES = [['value' => 's', 'label' => 'Kicsi'], ['value' => 'm', 'label' => 'Nagy']];

    private function event(): int
    {
        $day = static fn (int $offset): string => Runner::current()->clock()->now()->setTimezone(wp_timezone())
            ->setTime(0, 0)->modify(sprintf('%+d days', $offset))->format('Y-m-d H:i');
        $id = $this->create('Nyári tábor');
        $this->update($id, [
            'name' => 'Nyári tábor',
            'registration_opens_at' => $day(-1),
            'registration_closes_at' => $day(10),
            'starts_at' => $day(30),
            'ends_at' => $day(36),
        ]);
        $this->transition(new PublishEvent(), $id);

        return $id;
    }

    /** @param array<string, string> $stored */
    private function render(string $content, array $stored): string
    {
        $formId = $this->insertForm($this->slotFields() . $content, ['kalaka_event_id' => $this->event()]);
        $host = new PrefillingHost(new EventFormHost(), $stored);

        return (new FormPage($host))->html($formId, static function (FormPageView $view): void {
            echo $view->content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the rendered form
        });
    }

    public function testTheFieldsOpenWithTheirStoredValues(): void
    {
        $html = $this->render(
            $this->field('text', 'note', ['multiline' => true])
                . $this->field('choice', 'size', ['options' => self::SIZES])
                . $this->field('choice', 'sizes', ['options' => self::SIZES, 'multiple' => true])
                . $this->field('consent', 'ok'),
            [
                'name' => 'Teszt Anna',
                'email' => 'teszt.anna@example.org',
                'note' => "két\nsor",
                'size' => 'm',
                'sizes' => '["s","m"]',
                'ok' => '1',
            ]
        );

        self::assertMatchesRegularExpression('#name="name"[^>]* value="Teszt Anna"#', $html);
        self::assertMatchesRegularExpression('#name="email"[^>]* value="teszt.anna@example.org"#', $html);
        self::assertStringContainsString(">két\nsor</textarea>", $html);
        self::assertMatchesRegularExpression('#name="size"\s+value="m"[^>]* checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="size"\s+value="s"[^>]* checked#', $html);
        self::assertMatchesRegularExpression('#name="sizes\[\]"\s+value="s"[^>]* checked#', $html);
        self::assertMatchesRegularExpression('#name="sizes\[\]"\s+value="m"[^>]* checked#', $html);
        self::assertMatchesRegularExpression('#name="ok"\s+value="1"[^>]* checked#', $html);
    }

    public function testWithNoStoredValuesTheFormOpensEmpty(): void
    {
        $html = $this->render($this->field('choice', 'size', ['options' => self::SIZES]), []);

        self::assertDoesNotMatchRegularExpression('#name="name"[^>]* value=#', $html);
        self::assertStringNotContainsString(' checked', $html);
    }

    public function testTheFormIsWalkedForThePrefilledAnswers(): void
    {
        $condition = ['match' => 'all', 'items' => [['key' => 'size', 'operator' => 'equals', 'value' => 'm']]];
        $branch = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $condition]) . ' -->'
            . $this->field('text', 'why') . '<!-- /wp:kalaka/if -->';

        $shown = $this->render($this->field('choice', 'size', ['options' => self::SIZES]) . $branch, ['size' => 'm']);
        $hidden = $this->render($this->field('choice', 'size', ['options' => self::SIZES]) . $branch, ['size' => 's']);

        self::assertMatchesRegularExpression('#<div data-kalaka-chain="\d+" data-kalaka-branch="0">#', $shown);
        self::assertMatchesRegularExpression('#<div data-kalaka-chain="\d+" data-kalaka-branch="0" hidden>#', $hidden);
    }

    public function testAValueItsTypeMakesNothingOfOpensEmpty(): void
    {
        $html = $this->render(
            $this->field('choice', 'size', ['options' => self::SIZES]) . $this->field('consent', 'ok'),
            ['size' => 'x', 'ok' => '0']
        );

        self::assertStringNotContainsString(' checked', $html);
    }

    public function testTheCoreTypesPrefill(): void
    {
        self::assertSame('szöveg', (new TextFieldType())->prefill(new FieldTypeInput('szöveg', [])));
        self::assertNull((new TextFieldType())->prefill(new FieldTypeInput('', [])));
        self::assertSame('m', (new ChoiceFieldType())->prefill(new FieldTypeInput('m', ['options' => self::SIZES])));
        self::assertSame(
            ['s', 'm'],
            (new ChoiceFieldType())->prefill(
                new FieldTypeInput('["s","m"]', ['options' => self::SIZES, 'multiple' => true])
            )
        );
        // A stored value that is not one of the options is dropped (#193 review C2-1).
        self::assertNull((new ChoiceFieldType())->prefill(new FieldTypeInput('x', ['options' => self::SIZES])));
        self::assertSame(
            ['m'],
            (new ChoiceFieldType())->prefill(
                new FieldTypeInput('["x","m"]', ['options' => self::SIZES, 'multiple' => true])
            )
        );
        self::assertTrue((new ConsentFieldType())->prefill(new FieldTypeInput('1', [])));
        self::assertNull((new ConsentFieldType())->prefill(new FieldTypeInput('', [])));
    }
}
