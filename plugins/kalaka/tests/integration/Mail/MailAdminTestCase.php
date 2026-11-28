<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Module\ActionCatalogue;

/**
 * The base of the mail admin tests: abilities and routes called as a user, through WordPress's own paths, and a
 * grant holder on a view of the registered registrations.
 */
abstract class MailAdminTestCase extends MailBodyTestCase
{
    protected const ACTION = 'kalaka_manual_mail';

    private ActionCatalogue $catalogue;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->catalogue = ActionCatalogue::current();
        ActionCatalogue::record(new ActionCatalogue([self::ACTION]));
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    protected function as(int $user): void
    {
        wp_set_current_user($user);
        Access::forget();
    }

    protected function ability(string $name): \WP_Ability
    {
        $ability = wp_get_ability($name);
        self::assertInstanceOf(\WP_Ability::class, $ability, $name);

        return $ability;
    }

    /** An ability over core's run endpoint, as the app calls it: `POST`, the input under `input`. */
    protected function runAbility(string $name, string $inputJson = '{}'): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/wp-abilities/v1/abilities/' . $name . '/run');
        $request->set_header('content-type', 'application/json');
        $request->set_body('{"input":' . $inputJson . '}');
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    /** @param array<string, mixed> $params */
    protected function rest(string $method, string $route, array $params = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/kalaka-admin/v1' . $route);
        if ($method === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_header('content-type', 'application/json');
            $request->set_body((string) wp_json_encode($params));
        }
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    /** A user without full access who holds the manual-mail action on the event's registered registrations. */
    protected function holder(int $event): int
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        $view = $this->exec(new SaveView(), [
            'event_id' => $event,
            'name' => 'Nézet',
            'filter' => [['column' => 'status', 'in' => ['registered']]],
            'columns' => [['column' => 'code', 'write' => false]],
        ]);
        self::assertIsInt($view);
        $this->exec(new SaveGrant(), [
            'event_id' => $event,
            'user_id' => $user,
            'view_id' => $view,
            'bound_value' => null,
            'actions' => [self::ACTION],
        ]);

        return $user;
    }

    /** @return array{int, int} a published event with two registered registrations and a published body */
    protected function ready(): array
    {
        $event = $this->published();
        $this->registration($event, '00A0');
        $this->registration($event, '00A2');

        return [$event, $this->document($event, $this->paragraph('Szia'))];
    }
}
