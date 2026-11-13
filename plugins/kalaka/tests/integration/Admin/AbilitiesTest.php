<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\GrantFullAccess;
use Kalaka\Admin\FullAccess\RevokeFullAccess;
use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Runner;

/** The admin use cases as WordPress abilities, and S36-2: who reaches them at all. */
final class AbilitiesTest extends GrantTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The abilities run the production runner; it joins the harness's open transaction here.
        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    /** @return array<string, array{AdminUseCase, string, string}> use case, label, description */
    private static function abilities(): array
    {
        return [
            'save-view' => [new SaveView(), 'Nézet mentése', 'Egy esemény nézetének létrehozása vagy módosítása.'],
            'delete-view' => [new DeleteView(), 'Nézet törlése', 'Egy esemény hozzáférés nélküli nézetének törlése.'],
            'save-grant' => [
                new SaveGrant(),
                'Hozzáférés mentése',
                'Hozzáférés megadása vagy módosítása egy esemény nézetéhez és műveleteihez.',
            ],
            'delete-grant' => [new DeleteGrant(), 'Hozzáférés törlése', 'Egy hozzáférés visszavonása.'],
            'grant-full-access' => [
                new GrantFullAccess(),
                'Teljes hozzáférés megadása',
                'Teljes hozzáférés megadása egy felhasználónak a Kaláka minden eseményéhez.',
            ],
            'revoke-full-access' => [
                new RevokeFullAccess(),
                'Teljes hozzáférés visszavonása',
                'Egy felhasználó teljes hozzáférésének visszavonása.',
            ],
        ];
    }

    private function ability(string $name): \WP_Ability
    {
        $ability = wp_get_ability($name);
        self::assertInstanceOf(\WP_Ability::class, $ability, $name);

        return $ability;
    }

    /** @return array<string, mixed> */
    private function viewInput(int $event): array
    {
        return [
            'event_id' => $event,
            'name' => 'Nézet',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false]],
        ];
    }

    public function testEachAdminUseCaseIsAnAbilityInTheKalakaCategoryWithItsInputSchema(): void
    {
        $category = wp_get_ability_category('kalaka');
        self::assertInstanceOf(\WP_Ability_Category::class, $category);
        self::assertSame('Kaláka', $category->get_label());
        self::assertSame('A Kaláka műveletei.', $category->get_description());

        foreach (self::abilities() as $slug => [$useCase, $label, $description]) {
            $ability = $this->ability('kalaka/' . $slug);
            self::assertSame($useCase::name(), $ability->get_name());
            self::assertSame($label, $ability->get_label(), $slug);
            self::assertSame($description, $ability->get_description(), $slug);
            self::assertSame('kalaka', $ability->get_category(), $slug);
            self::assertSame($useCase->inputSchema(), $ability->get_input_schema(), $slug);
            self::assertTrue($ability->get_meta()['show_in_rest'], $slug);
        }
    }

    public function testNestedAndSystemUseCasesAreNotAbilities(): void
    {
        self::assertFalse(wp_has_ability('kalaka/copy-views'));
        self::assertFalse(wp_has_ability('kalaka/evaluate-registration'));
        self::assertFalse(wp_has_ability('kalaka/flag-evaluation-failure'));
    }

    /** S36-2 (hole A4): nobody, and a user with neither full access nor a grant, never reach the runner. */
    public function testAUserWithoutFullAccessOrAGrantIsRefusedWithoutAnAuditEntry(): void
    {
        $event = $this->createEvent();
        $before = count($this->entries());

        foreach ([0, self::factory()->user->create(['role' => 'subscriber'])] as $user) {
            wp_set_current_user($user);
            $ability = $this->ability('kalaka/save-view');

            self::assertFalse($ability->check_permissions($this->viewInput($event)));
            self::assertInstanceOf(\WP_Error::class, $ability->execute($this->viewInput($event)));
        }

        self::assertSame(0, $this->viewCount());
        self::assertCount($before, $this->entries());
    }

    /** S36-2: a grant holder passes the gate; the runner's coarse check still refuses a site-level call. */
    public function testAGrantHolderReachesTheRunnerWhichRefusesASiteLevelUseCase(): void
    {
        $event = $this->createEvent();
        $holder = $this->plainUser();
        $this->saveGrant($event, $holder);
        $before = count($this->entries());
        wp_set_current_user($holder);
        $ability = $this->ability('kalaka/save-view');

        self::assertTrue($ability->check_permissions($this->viewInput($event)));
        $result = $ability->execute($this->viewInput($event));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame(0, $this->viewCount());
        $entries = $this->entries();
        self::assertCount($before + 1, $entries);
        self::assertSame('failed', $entries[$before]['outcome']);
        self::assertSame(PermissionDenied::class, $this->decode($entries[$before]['error'])['class']);
    }

    public function testAFullAccessUserSavesAViewThroughTheAbility(): void
    {
        $event = $this->createEvent();
        $before = count($this->entries());
        wp_set_current_user($this->admin->userId);

        $id = $this->ability('kalaka/save-view')->execute($this->viewInput($event));

        self::assertIsInt($id);
        self::assertSame(1, $this->viewCount());
        $entries = $this->entries();
        self::assertSame('kalaka/save-view', $entries[$before]['action']);
        self::assertSame('succeeded', $entries[$before]['outcome']);
        self::assertSame((string) $this->admin->userId, $entries[$before]['actor_user_id']);
    }

    public function testInputTheSchemaRefusesNeverReachesTheUseCase(): void
    {
        $before = count($this->entries());
        wp_set_current_user($this->admin->userId);

        $result = $this->ability('kalaka/save-view')->execute(['event_id' => 'x']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('ability_invalid_input', $result->get_error_code());
        self::assertCount($before, $this->entries());
    }

    public function testOverRestNobodyGets401AUserWithoutAccess403AndFullAccessRunsIt(): void
    {
        $event = $this->createEvent();
        $request = new \WP_REST_Request('POST', '/wp-abilities/v1/abilities/kalaka/save-view/run');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['input' => $this->viewInput($event)]));

        wp_set_current_user(0);
        self::assertSame(401, rest_do_request($request)->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        self::assertSame(403, rest_do_request($request)->get_status());
        self::assertSame(0, $this->viewCount());

        wp_set_current_user($this->admin->userId);
        self::assertSame(200, rest_do_request($request)->get_status());
        self::assertSame(1, $this->viewCount());
    }
}
