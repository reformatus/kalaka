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
 * S44: a stored, non-current form version takes its set's options without their conditions. A submission's
 * mail renders through the version it was given in, whose fields need not hold a key a set option's condition
 * reads now; the current version keeps the conditions.
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
            'window_opens_at' => null,
            'window_closes_at' => null,
            'published' => false,
        ], new CliActor(null));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** The slot form's two marked fields and the set's ranking; a text field `x` when `$x` is true. */
    private function content(bool $x): string
    {
        $marked = $this->block('kalaka/name-field', ['key' => 'name', 'label' => 'Név', 'displayName' => true])
            . $this->block('kalaka/email-field', ['key' => 'email', 'label' => 'E-mail', 'contactEmail' => true]);

        return $marked
            . ($x ? $this->block('kalaka/text-field', ['key' => 'x', 'label' => 'X']) : '')
            . $this->block(RankingFieldType::BLOCK_NAME, [
                'key' => 'modulok',
                'label' => 'Modulok',
                'set' => $this->set,
                'min' => 1,
                'max' => 2,
            ]);
    }

    private function form(bool $x): int
    {
        $id = wp_insert_post([
            'post_type' => 'kalaka_form',
            'post_status' => 'publish',
            'post_title' => 'Jelentkezés',
            'post_content' => $this->content($x),
            'meta_input' => ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'registration'],
        ], true);
        self::assertIsInt($id);

        return $id;
    }

    /** @param array<string, mixed> $condition */
    private function option(string $key, string $label, int $position, ?array $condition = null): void
    {
        (new AssignmentSets($this->runner))->saveOption([
            'set_id' => $this->set,
            'key' => $key,
            'label' => $label,
            'limit' => null,
            'leaders' => '',
            'place' => '',
            'condition' => $condition,
            'position' => $position,
        ], new CliActor(null));
    }

    public function testAStoredEarlierVersionTakesTheSetsOptionsWithoutTheirConditions(): void
    {
        // The form as it was submitted: it lacks `x`, which a set option's condition reads later.
        $id = $this->form(false);
        $stored = (new Forms())->form($id);
        self::assertNotNull($stored);
        $version = $stored->version;
        $key = $this->registration($this->event, '12A3', [
            'name' => 'Teszt Elek',
            'email' => 'teszt@example.test',
            'modulok' => '["modul_a"]',
        ], 'registered', $id);

        // The form grows `x` before the ranking; the set gets its options, one reading `x`.
        wp_update_post(['ID' => $id, 'post_content' => $this->content(true)]);
        $this->option('modul_a', 'Modul A', 0);
        $this->option('modul_b', 'Modul B', 1, ['match' => 'all', 'items' => [
            ['key' => 'x', 'operator' => 'equals', 'value' => 'igen'],
        ]]);
        $current = (new Forms())->form($id);
        self::assertNotNull($current);
        self::assertNotSame($version, $current->version);

        // The stored version parses, with no conditions read; the current one still applies the condition.
        $earlier = (new Forms())->definition($current, $version);
        $now = (new Forms())->definition($current);
        self::assertSame([], $earlier->field('modulok')?->optionConditions);
        self::assertArrayHasKey('modul_b', $now->field('modulok')?->optionConditions ?? []);

        // The submission's mail reads the stored version and renders.
        $body = $this->document($this->event, $this->paragraph('Szia'));
        $this->switchOn($this->event, 'registered', $body);
        $mail = (new MailRenderer())->render($this->event, 'registered', $key, self::now());
        self::assertStringContainsString('Szia', $mail->html);
    }
}
