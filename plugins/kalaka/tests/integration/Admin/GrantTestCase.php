<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Module\ActionCatalogue;

/** The base of the grant tests: a catalogue of two actions, views and grants through the use cases. */
abstract class GrantTestCase extends AdminTestCase
{
    protected const EDIT = 'kalaka_edit_fields';

    protected const WITHDRAW = 'kalaka_withdraw_registration';

    private ActionCatalogue $catalogue;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->catalogue = ActionCatalogue::current();
        ActionCatalogue::record(new ActionCatalogue([self::EDIT, self::WITHDRAW]));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);

        parent::tear_down();
    }

    /** A view of the event whose filter takes the `module` field as its parameter. */
    protected function parameterView(int $event): int
    {
        return $this->saveView($event, [['column' => 'field:module', 'parameter' => true]]);
    }

    /** A view of the event without a parameter: the registered ones. */
    protected function plainView(int $event): int
    {
        return $this->saveView($event, [['column' => 'status', 'in' => ['registered']]]);
    }

    /** @param list<array<string, mixed>> $filter */
    private function saveView(int $event, array $filter): int
    {
        $id = $this->exec(new SaveView(), [
            'event_id' => $event,
            'name' => 'Nézet',
            'filter' => $filter,
            'columns' => [['column' => 'code', 'write' => false]],
        ]);
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function grantInput(int $event, int $user, array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $event,
            'user_id' => $user,
            'view_id' => null,
            'bound_value' => null,
            'actions' => [self::EDIT],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return int the grant's id
     */
    protected function saveGrant(int $event, int $user, array $overrides = []): int
    {
        $id = $this->exec(new SaveGrant(), $this->grantInput($event, $user, $overrides));
        self::assertIsInt($id);

        return $id;
    }

    /** A user without full access. */
    protected function plainUser(): int
    {
        return self::factory()->user->create(['role' => 'editor']);
    }

    protected function grantCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_grants");
    }

    /** @return array<string, ?string> */
    protected function grantRow(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}kalaka_grants WHERE id = %d", $id), ARRAY_A);
        self::assertIsArray($row);

        return $row;
    }
}
