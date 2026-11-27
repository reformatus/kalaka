<?php

declare(strict_types=1);

namespace Kalaka\Admin\Cli;

use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\Module;
use Kalaka\Admin\View\SaveView;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\Seed\Seeding;

/**
 * A view and a grant holder for the first published sample event, so the admin grid shows views and a
 * grant holder's side: the view „Ebédlista” with a parameter on the lunch choice, and the user
 * `GRANTEE_LOGIN` (the minimal role, password `password`) with two grants on it, bound to the vegetarian
 * and the gluten-free lunch. The texts are seed data, not UI strings.
 */
final class AdminSeeder implements Seeder
{
    /** The login of the sample grant holder. */
    public const GRANTEE_LOGIN = 'kalaka_partner';

    public function __construct(private readonly Events $events = new Events())
    {
    }

    /** @throws CommandFailure when WordPress refuses the user */
    public function seed(Seeding $seeding): void
    {
        $published = $this->events->published();
        if ($published === []) {
            throw new \LogicException('The admin seeder needs the published sample event of the event seeder.');
        }
        $eventId = $published[0]->id;

        $columns = ['serial', 'code', 'field:nev', 'status', 'field:ebed', 'field:megjegyzes'];
        $input = [
            'event_id' => $eventId,
            'name' => 'Ebédlista',
            'filter' => [['column' => 'field:ebed', 'parameter' => true]],
            'columns' => array_map(
                static fn (string $column): array => ['column' => $column, 'write' => false],
                $columns
            ),
        ];
        $viewId = $seeding->run(SaveView::prepared($input), $input);

        // Two grants on the one view: the grid gives the holder one tab per grant.
        $grantee = $this->grantee();
        foreach (['vegetarianus', 'glutenmentes'] as $bound) {
            $seeding->run(new SaveGrant(), [
                'event_id' => $eventId,
                'user_id' => $grantee,
                'view_id' => $viewId,
                'bound_value' => $bound,
                'actions' => [],
            ]);
        }
    }

    /** @throws CommandFailure */
    private function grantee(): int
    {
        $user = get_user_by('login', self::GRANTEE_LOGIN);
        if ($user !== false) {
            return $user->ID;
        }
        $id = wp_insert_user([
            'user_login' => self::GRANTEE_LOGIN,
            'user_pass' => 'password',
            'user_email' => self::GRANTEE_LOGIN . '@example.org',
            'display_name' => 'Teszt Konyhafelelős',
            'role' => Module::GRANTEE_ROLE,
        ]);
        if (is_wp_error($id)) {
            $error = new CommandFailure('User ' . self::GRANTEE_LOGIN . ': ' . $id->get_error_message());
            throw $error;
        }

        return $id;
    }
}
