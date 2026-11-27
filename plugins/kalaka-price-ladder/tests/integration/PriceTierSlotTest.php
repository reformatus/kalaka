<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\BrokenForms;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Engine\SlotRules;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The owner's ruling 2026-11-19: with a ladder, `price_tier` is a required field of the event's registration
 * form. A form without it is refused on save and closed when served, like a form without its contact email.
 */
final class PriceTierSlotTest extends PriceLadderTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        // A form save declares the event's participant fields through the production runner: in a test it
        // has to join the harness's transaction.
        $this->production = Runner::current();
        Runner::publish($this->runner);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        Runner::publish($this->production);
        parent::tear_down();
    }

    private static function block(string $name, array $attributes): string
    {
        return serialize_block([
            'blockName' => $name,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    private static function slotFields(): string
    {
        return self::block('kalaka/name-field', ['key' => 'name', 'label' => 'Név', 'displayName' => true])
            . self::block('kalaka/email-field', ['key' => 'email', 'label' => 'E-mail', 'contactEmail' => true]);
    }

    private static function tierField(bool $required = true): string
    {
        return self::block('kalaka/choice-field', [
            'key' => 'price_tier',
            'label' => 'Díjkategória',
            'required' => $required,
            'options' => [['value' => 'alap', 'label' => 'Alap ár'], ['value' => 'gy', 'label' => 'Gyülekezeti ár']],
        ]);
    }

    private function insert(string $content, string $placement = 'registration'): int
    {
        $check = [DefinitionCheck::class, 'onInsert'];
        remove_filter('wp_insert_post_data', $check, 20);
        try {
            $id = wp_insert_post([
                'post_type' => 'kalaka_form',
                'post_status' => 'publish',
                'post_title' => 'Jelentkezés',
                'post_content' => $content,
                'meta_input' => ['kalaka_event_id' => $this->event, 'kalaka_placement' => $placement],
            ], true);
        } finally {
            add_filter('wp_insert_post_data', $check, 20, 2);
        }
        self::assertIsInt($id);

        return $id;
    }

    /** @return ?array{string, string} the problem the form is served with, null when it opens */
    private function served(int $id): ?array
    {
        $forms = new Forms();
        $form = $forms->form($id);
        self::assertNotNull($form);
        try {
            $forms->definition($form, null, true);
        } catch (SystemFailure $e) {
            return DefinitionCheck::split($e);
        }

        return null;
    }

    public function testTheSaveRefusesALiveRegistrationFormWithoutARequiredPriceTierOnlyWhenTheEventHasTiers(): void
    {
        $problem = [SlotRules::REQUIRED_FIELD_MISSING, 'price_tier'];
        $check = fn (string $content, FormPlacement $placement = FormPlacement::Registration): ?array
            => DefinitionCheck::problem($content, 0, $placement, $this->event);

        // No ladder: nothing is required.
        self::assertNull($check(self::slotFields()));

        $this->applyLadder(self::ivkTiers());
        self::assertSame($problem, $check(self::slotFields()));
        self::assertSame($problem, $check(self::slotFields() . self::tierField(false)));
        self::assertNull($check(self::slotFields() . self::tierField()));
        // Not inside a condition branch, nor on a conditional page.
        $answered = ['match' => 'all', 'items' => [['key' => 'name', 'operator' => 'empty', 'not' => true]]];
        $branch = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $answered]) . ' -->'
            . self::tierField() . '<!-- /wp:kalaka/if -->';
        self::assertSame($problem, $check(self::slotFields() . $branch));
        // An additional form never needs it.
        $note = self::block('kalaka/text-field', ['key' => 'megjegyzes', 'label' => 'M']);
        self::assertNull($check($note, FormPlacement::Additional));
    }

    public function testAFormWithoutPriceTierIsClosedOnceTheEventHasTiersAndReopensWhenFixed(): void
    {
        $form = $this->insert(self::slotFields());
        self::assertNull($this->served($form));
        self::assertSame([], BrokenForms::compute());

        $this->applyLadder(self::ivkTiers());

        self::assertSame([SlotRules::REQUIRED_FIELD_MISSING, 'price_tier'], $this->served($form));
        self::assertCount(1, BrokenForms::compute());

        $fixed = $this->insert(self::slotFields() . self::tierField());
        self::assertNull($this->served($fixed));

        // An empty ladder requires nothing again.
        $this->applyLadder([]);
        self::assertNull($this->served($form));
    }
}
