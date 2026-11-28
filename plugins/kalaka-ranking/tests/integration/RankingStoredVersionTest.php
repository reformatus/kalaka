<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ranking;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Ranking\RankingFieldType;
use Kalaka\Tests\Integration\Mail\MailBodyTestCase;

/**
 * S44: every form version reads its own ranking block's option conditions (`optionConditions`). A submission's
 * mail renders through the version it was given in, whatever the current version holds.
 */
final class RankingStoredVersionTest extends MailBodyTestCase
{
    private int $event;

    private int $set;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new FormsModule())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
        $this->event = $this->create();
        $this->set = (new AssignmentSets($this->runner))->save([
            'event_id' => $this->event,
            'name' => 'Modulok',
            'required' => false,
            'published' => false,
        ], new CliActor(null));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** The slot form's two marked fields, a text field `x` and the set's ranking, with its option conditions. */
    private function content(bool $conditions): string
    {
        $marked = $this->block('kalaka/name-field', ['key' => 'name', 'label' => 'Név', 'displayName' => true])
            . $this->block('kalaka/email-field', ['key' => 'email', 'label' => 'E-mail', 'contactEmail' => true]);

        return $marked
            . $this->block('kalaka/text-field', ['key' => 'x', 'label' => 'X'])
            . $this->block(RankingFieldType::BLOCK_NAME, [
                'key' => 'modulok',
                'label' => 'Modulok',
                'set' => $this->set,
                'min' => 1,
                'max' => 2,
            ] + ($conditions ? ['optionConditions' => ['modul_b' => ['match' => 'all', 'items' => [
                ['key' => 'x', 'operator' => 'equals', 'value' => 'igen'],
            ]]]] : []));
    }

    private function form(bool $conditions): int
    {
        $id = wp_insert_post([
            'post_type' => 'kalaka_form',
            'post_status' => 'publish',
            'post_title' => 'Jelentkezés',
            'post_content' => $this->content($conditions),
            'meta_input' => ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'registration'],
        ], true);
        self::assertIsInt($id);

        return $id;
    }

    private function option(string $key, string $label, int $position): void
    {
        (new AssignmentSets($this->runner))->saveOption([
            'set_id' => $this->set,
            'key' => $key,
            'label' => $label,
            'limit' => null,
            'leaders' => '',
            'place' => '',
            'position' => $position,
        ], new CliActor(null));
    }

    public function testAnEarlierVersionKeepsItsOwnConditionsAndTheCurrentOneItsOwn(): void
    {
        $this->option('modul_a', 'Modul A', 0);
        $this->option('modul_b', 'Modul B', 1);

        // The form as it was submitted: its ranking holds a condition on `modul_b`.
        $id = $this->form(true);
        $stored = (new Forms())->form($id);
        self::assertNotNull($stored);
        $version = $stored->version;
        $key = $this->registration($this->event, '12A3', [
            'name' => 'Teszt Elek',
            'email' => 'teszt@example.test',
            'modulok' => '["modul_a"]',
        ], 'registered', $id);

        // The form is edited: the condition goes.
        wp_update_post(['ID' => $id, 'post_content' => $this->content(false)]);
        $current = (new Forms())->form($id);
        self::assertNotNull($current);
        self::assertNotSame($version, $current->version);

        // Each version reads its own block's conditions.
        $earlier = (new Forms())->definition($current, $version);
        $now = (new Forms())->definition($current);
        self::assertSame(['modul_b'], array_keys($earlier->field('modulok')?->optionConditions ?? []));
        self::assertSame([], $now->field('modulok')?->optionConditions);

        // The submission's mail reads the stored version and renders.
        $body = $this->document($this->event, $this->paragraph('Szia'));
        $this->switchOn($this->event, 'registered', $body);
        $mail = (new MailRenderer())->render($this->event, 'registered', $key, self::now());
        self::assertStringContainsString('Szia', $mail->html);
    }
}
