<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\Module;
use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Tests\Integration\Foundation\IndexCheck;

/** The views and grants tables, no role holding full access, and the minimal role. */
final class AdminSchemaTest extends AdminTestCase
{
    /**
     * @return array<string, string> each column's type, with ` null` for a nullable one
     */
    private function declaredColumns(string $table): array
    {
        global $wpdb;

        $name = $wpdb->prefix . $table;
        self::assertSame('utf8mb4_hungarian_ci', $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $name
        )));

        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s'
            . ' ORDER BY ORDINAL_POSITION',
            $name
        ), ARRAY_A);
        self::assertIsArray($columns);

        $declared = [];
        foreach ($columns as $column) {
            $nullable = $column['IS_NULLABLE'] === 'YES' ? ' null' : '';
            $declared[$column['COLUMN_NAME']] = $column['COLUMN_TYPE'] . $nullable;
            if ($column['CHARACTER_SET_NAME'] !== null) {
                self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $column['COLUMN_NAME']);
                self::assertSame('utf8mb4_hungarian_ci', $column['COLLATION_NAME'], $column['COLUMN_NAME']);
            }
        }

        return $declared;
    }

    /** AC1 */
    public function testTheViewsTableExistsWithItsColumnsAndTheDeclaredCollation(): void
    {
        self::assertSame(1, (int) get_option('kalaka_schema_admin'));
        self::assertSame([], LoadState::current()->failures);

        self::assertSame(
            [
                'id' => 'bigint(20) unsigned',
                'event_id' => 'bigint(20) unsigned',
                'version' => 'int(10) unsigned',
                'name' => 'varchar(191)',
                'filter' => 'longtext',
                'columns' => 'longtext',
            ],
            $this->declaredColumns('kalaka_views')
        );
    }

    /** AC1 */
    public function testTheGrantsTableExistsWithItsColumnsAndTheDeclaredCollation(): void
    {
        self::assertSame(
            [
                'id' => 'bigint(20) unsigned',
                'event_id' => 'bigint(20) unsigned',
                'version' => 'int(10) unsigned',
                'user_id' => 'bigint(20) unsigned',
                'view_id' => 'bigint(20) unsigned null',
                'bound_value' => 'varchar(191) null',
                'actions' => 'longtext',
            ],
            $this->declaredColumns('kalaka_grants')
        );
    }

    public function testTheIndexesAreAsDeclared(): void
    {
        global $wpdb;

        $tables = (new Module())->schema()->tables;
        self::assertCount(2, $tables);
        foreach ($tables as $table) {
            $statement = str_replace('{prefix}', $wpdb->prefix, $table);
            self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
            self::assertSame([], IndexCheck::mismatches($match[1], $statement));
        }
    }

    public function testDbDeltaFindsNothingToChange(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $statements = array_map(
            static fn (string $statement): string => str_replace('{prefix}', $wpdb->prefix, $statement),
            (new Module())->schema()->tables
        );

        self::assertSame([], dbDelta($statements, false));
    }

    /** S36-1: full access is given per user, never to a role. */
    public function testNoRoleHoldsFullAccess(): void
    {
        foreach (wp_roles()->role_objects as $name => $role) {
            self::assertFalse($role->has_cap(AdminAccess::FULL_ACCESS), $name);
        }
    }

    /** S36-1: administrators do not get full access by their role. */
    public function testAnAdministratorWithoutTheCapabilityIsRefusedASiteLevelUseCase(): void
    {
        $administrator = new UserActor(self::factory()->user->create(['role' => 'administrator']));

        $this->expectException(PermissionDenied::class);
        $this->exec(new CreateEvent(), ['name' => 'Tábor'], $administrator);
    }

    /** AC6 */
    public function testTheMinimalRoleExistsWithOnlyReadAndTheStepIsSafeToRerun(): void
    {
        $role = get_role('kalaka_grantee');
        self::assertInstanceOf(\WP_Role::class, $role);
        self::assertSame(['read' => true], $role->capabilities);
        self::assertSame('Kaláka-felhasználó', wp_roles()->get_names()['kalaka_grantee']);

        (new Module())->schema()->steps[1]();
        (new Module())->schema()->steps[1]();

        $after = get_role('kalaka_grantee');
        self::assertInstanceOf(\WP_Role::class, $after);
        self::assertSame(['read' => true], $after->capabilities);
        self::assertSame('Kaláka-felhasználó', wp_roles()->get_names()['kalaka_grantee']);
    }

    public function testTheMinimalRoleDoesNotHoldFullAccess(): void
    {
        $user = self::factory()->user->create(['role' => 'kalaka_grantee']);

        self::assertFalse(user_can($user, AdminAccess::FULL_ACCESS));
        self::assertTrue(user_can($user, 'read'));
    }
}
