<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Cli;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\Seed\Seeding;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * Sample registrations for the first published sample event, so the admin grid has rows: `COUNT` guests
 * submit the Forms seeder's sample form, as the form page would, with varied answers. The event holds 40,
 * so the last ones are waitlisted. The names are invented and the addresses are on `example.org`; they are
 * seed data, not UI strings. Withdrawn and removed registrations and decisions have no use case yet, so
 * the seed has none.
 */
final class RegistrationSeeder implements Seeder
{
    private const COUNT = 46;

    private const FAMILY = [
        'Kovács', 'Szabó', 'Tóth', 'Varga', 'Kiss', 'Molnár', 'Németh', 'Farkas', 'Balogh', 'Papp',
        'Takács', 'Juhász', 'Lakatos', 'Mészáros', 'Oláh', 'Simon', 'Rácz', 'Fekete', 'Szilágyi', 'Török',
        'Fehér', 'Balázs', 'Gál',
    ];

    private const GIVEN = [
        'Anna', 'Bence', 'Csenge', 'Dániel', 'Eszter', 'Gergő', 'Hanna', 'Levente', 'Luca', 'Máté',
        'Noémi', 'Péter', 'Réka', 'Ádám', 'Zsófia', 'Ákos', 'Boglárka', 'Örs', 'Lili',
    ];

    private const LUNCH = ['normal', 'normal', 'normal', 'vegetarianus', 'glutenmentes'];

    private const PROGRAMS = [
        [], ['kirandulas'], ['kezmuves'], ['sport'], ['kirandulas', 'sport'], ['kezmuves', 'sport'],
    ];

    private const NOTES = ['Későn érkezem.', 'Laktózérzékeny vagyok.', 'A testvéremmel egy szobába kérem.'];

    private const NIGHTS = ['2', '3', '5'];

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly Forms $forms = new Forms(),
        private readonly RegistrationSubmit $submit = new RegistrationSubmit(),
    ) {
    }

    public function seed(Seeding $seeding): void
    {
        $published = $this->events->published();
        $formId = $published === [] ? null : $this->sampleForm($published[0]->id);
        $form = $formId === null ? null : $this->forms->form($formId);
        if ($form === null) {
            throw new \LogicException('The registration seeder needs the sample form of the form seeder.');
        }

        for ($i = 0; $i < self::COUNT; $i++) {
            $family = self::FAMILY[($i * 7) % count(self::FAMILY)];
            $given = self::GIVEN[($i * 5 + intdiv($i, 3)) % count(self::GIVEN)];
            $answers = [
                'nev' => $family . ' ' . $given,
                'email' => 'jelentkezo' . ($i + 1) . '@example.org',
                'szallas' => $i % 4 === 0 ? 'nem' : 'igen',
                'ebed' => self::LUNCH[$i % count(self::LUNCH)],
                'programok' => self::PROGRAMS[$i % count(self::PROGRAMS)],
                'fenykep' => $i % 5 !== 0,
            ];
            if ($answers['szallas'] === 'igen') {
                $answers['ejszakak'] = self::NIGHTS[$i % count(self::NIGHTS)];
            }
            if ($i % 6 === 1) {
                $answers['megjegyzes'] = self::NOTES[$i % count(self::NOTES)];
            }
            $this->submit->submit(
                new SubmitRequest($form->id, $form->version, $answers, SubmitToken::generate()),
                new GuestActor()
            );
        }
    }

    /**
     * The registration form the form seeder wrote for the event, by its marker. The post type and the
     * marker are the Forms seeder's (`FormPosts::POST_TYPE`, `FormSeeder::MARKER`), named here because
     * Registrations does not use Forms' internals.
     */
    private function sampleForm(int $eventId): ?int
    {
        $ids = get_posts([
            'post_type' => 'kalaka_form',
            'post_status' => 'publish',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- dev-only seed
            'meta_key' => '_kalaka_seed_sample',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);
        foreach ($ids as $id) {
            $form = $this->forms->form((int) $id);
            if ($form !== null && $form->eventId === $eventId) {
                return $form->id;
            }
        }

        return null;
    }
}
