<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\Engine\FieldType\ConsentFieldType;
use Kalaka\Forms\Engine\FieldType\TextFieldType;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\Engine\FormPageView;
use Kalaka\Forms\EventFormHost;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Registrations\Api\Drafts;

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
        // Drafts need accounts (N9), and an event with accounts needs the account block in its form.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'optional'], ['id' => $id]);
        ObjectCache::clear();

        return $id;
    }

    /** @param array<string, string> $stored */
    private function render(string $content, array $stored): string
    {
        $formId = $this->insertForm(
            '<!-- wp:kalaka/account /-->' . $this->slotFields() . $content,
            ['kalaka_event_id' => $this->event()]
        );
        $host = new PrefillingHost(new EventFormHost(), $stored);

        return (new FormPage($host))->html($formId, static function (FormPageView $view): void {
            echo $view->content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the rendered form
        });
    }

    /**
     * The page's script context for a form with two triggers: `size`, and `tier`, which shows only for the
     * size `m`.
     *
     * @param array<string, string> $stored
     * @param array<string, mixed> $draft the logged-in person's draft, saved before the render
     * @return array<string, mixed>
     */
    private function context(array $stored, array $draft = []): array
    {
        $tiers = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];
        $reads = static fn (string $key, string $value): string => wp_json_encode(['condition' => [
            'match' => 'all',
            'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]],
        ]]);
        $event = $this->event();
        $formId = $this->insertForm(
            '<!-- wp:kalaka/account /-->' . $this->slotFields()
                . $this->field('choice', 'size', ['options' => self::SIZES])
                . '<!-- wp:kalaka/if ' . $reads('size', 'm') . ' -->'
                . $this->field('choice', 'tier', ['options' => $tiers]) . '<!-- /wp:kalaka/if -->'
                . '<!-- wp:kalaka/if ' . $reads('tier', 'b') . ' -->'
                . $this->field('text', 'why') . '<!-- /wp:kalaka/if -->'
                . $this->field('text', 'note'),
            ['kalaka_event_id' => $event]
        );
        if ($draft !== []) {
            $user = self::factory()->user->create();
            wp_set_current_user($user);
            (new Drafts($this->runner))->save(
                $event,
                $formId,
                $this->readForm($formId)->version,
                new AnswerSet($draft),
                0,
                0,
                new AccountActor($user)
            );
        }
        $html = (new FormPage(new PrefillingHost(new EventFormHost(), $stored)))->html(
            $formId,
            static function (FormPageView $view): void {
                echo wp_json_encode($view->context);
            }
        );
        wp_set_current_user(0);
        $context = json_decode($html, true);
        self::assertIsArray($context);

        return $context;
    }

    public function testThePageContextCarriesTheTriggersAnswersItOpenedWith(): void
    {
        $context = $this->context(['name' => 'Teszt Anna', 'size' => 'm', 'tier' => 'b', 'note' => 'x']);

        self::assertSame(['size', 'tier'], $context['triggers']);
        self::assertSame(['size' => 'm', 'tier' => 'b'], $context['opened']);
    }

    public function testThePageContextCarriesTheDraftsAnswersOverTheStoredOnes(): void
    {
        $context = $this->context(['size' => 's'], ['size' => 'm', 'tier' => 'a']);

        self::assertSame(['size' => 'm', 'tier' => 'a'], $context['opened']);
    }

    public function testATriggerThatDoesNotShowIsNotInThePageContext(): void
    {
        self::assertSame(['size' => 's'], $this->context(['size' => 's', 'tier' => 'b'])['opened']);
        self::assertSame([], $this->context([])['opened']);
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
