<?php

declare(strict_types=1);

namespace Kalaka\Ranking\Cli;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\Seed\Seeding;
use Kalaka\Ranking\RankingFieldType;

/**
 * IVK's three tracks and its module set, on the first published sample event (core's seeders run first): a
 * request-mode set "Modulok", a registration form holding the track
 * question (`szolgalati_terulet`: ifivezető, iskolamisszió, PRO) and the ranking naming the set, and a page
 * that embeds it. The ifivezető modules A–F are for everybody; "Iskolamisszió" and "PRO" carry a condition
 * on the track, in the ranking block's `optionConditions` (S44). Configuration is S48's; these are seed data,
 * not UI strings.
 *
 * The options are written before the form, so the form's save finds every key its `optionConditions` names;
 * the save ties the set to the ranking's field. The set is written through `Assignments\Api` (the
 * extension's way to core's use cases); the posts with WordPress functions, as the seed writes WordPress
 * data, marked like core's sample form so `wp kalaka seed --drop` deletes them first.
 */
final class RankingSeeder implements Seeder
{
    /** Core's sample marker (`Forms\Cli\FormSeeder::MARKER`): its seeder deletes every marked post on a drop. */
    private const MARKER = '_kalaka_seed_sample';

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly AssignmentSets $sets = new AssignmentSets(),
    ) {
    }

    public function seed(Seeding $seeding): void
    {
        $published = $this->events->published();
        if ($published === []) {
            throw new \LogicException('The ranking seeder needs the published sample event of the event seeder.');
        }
        $eventId = $published[0]->id;
        $actor = new CliActor(null);

        $setId = $this->sets->save([
            'event_id' => $eventId,
            'name' => 'Modulok',
            'required' => true,
            'published' => false,
        ], $actor);

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $position => $letter) {
            $this->option($setId, 'Modul ' . $letter, 30, $position);
        }
        $this->option($setId, 'Iskolamisszió', 99, 6);
        $this->option($setId, 'PRO', 99, 7);

        $formId = $this->insert([
            'post_type' => 'kalaka_form',
            'post_title' => 'Jelentkezés modulokkal',
            'post_content' => $this->definition($setId),
            'meta_input' => ['kalaka_event_id' => $eventId, 'kalaka_placement' => 'registration'],
        ]);

        $this->insert([
            'post_type' => 'page',
            'post_title' => 'Jelentkezés modulokkal',
            'post_content' => $this->block('kalaka/form', ['formId' => $formId]),
        ]);
    }

    private function option(int $setId, string $label, int $capacity, int $position): void
    {
        $this->sets->saveOption([
            'set_id' => $setId,
            'label' => $label,
            'limit' => $capacity,
            'leaders' => '',
            'place' => '',
            'position' => $position,
        ], new CliActor(null));
    }

    private function definition(int $setId): string
    {
        return $this->block('kalaka/name-field', ['key' => 'nev', 'label' => 'Név', 'displayName' => true])
            . $this->block('kalaka/email-field', ['key' => 'email', 'label' => 'E-mail-cím', 'contactEmail' => true])
            . $this->block('kalaka/choice-field', [
                'key' => 'szolgalati_terulet',
                'label' => 'Szolgálati terület',
                'required' => true,
                'options' => [
                    ['value' => 'ifivezeto', 'label' => 'Ifivezető'],
                    ['value' => 'iskolamisszio', 'label' => 'Iskolamisszió'],
                    ['value' => 'pro', 'label' => 'PRO'],
                ],
            ])
            . $this->block(RankingFieldType::BLOCK_NAME, [
                'key' => 'modulok',
                'label' => 'Modulok',
                'required' => true,
                'set' => $setId,
                'min' => 1,
                'max' => 3,
                'optionConditions' => [
                    'iskolamisszio' => $this->track('iskolamisszio'),
                    'pro' => $this->track('pro'),
                ],
            ]);
    }

    /** @return array<string, mixed> the condition: the track answer is this one */
    private function track(string $value): array
    {
        return [
            'match' => 'all',
            'items' => [['key' => 'szolgalati_terulet', 'operator' => 'equals', 'value' => $value]],
        ];
    }

    /** @param array<string, mixed> $post */
    private function insert(array $post): int
    {
        $post['post_status'] = 'publish';
        $post['meta_input'] = ($post['meta_input'] ?? []) + [self::MARKER => '1'];
        $id = wp_insert_post($post, true);
        if (is_wp_error($id)) {
            throw new \RuntimeException('A ranking sample post could not be written.');
        }

        return $id;
    }

    /** @param array<string, mixed> $attributes */
    private function block(string $name, array $attributes): string
    {
        return serialize_block([
            'blockName' => $name,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }
}
