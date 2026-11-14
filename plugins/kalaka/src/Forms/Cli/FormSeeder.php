<?php

declare(strict_types=1);

namespace Kalaka\Forms\Cli;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Engine\FormPosts;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\Seed\Seeding;

/**
 * One registration-slot form for the first sample event (the Events seeder's open one; seeders run in
 * contribution order) and a page that embeds it, so the seeded site shows a form. There is no form use
 * case yet, so the posts are written with WordPress functions, as the seed writes WordPress data. The
 * texts are seed data (events-and-forms.md, "Forms implementation").
 */
final class FormSeeder implements Seeder
{
    public function __construct(private readonly Events $events = new Events())
    {
    }

    public function seed(Seeding $seeding): void
    {
        $published = $this->events->published();
        if ($published === []) {
            throw new \LogicException('The form seeder needs the published sample event of the event seeder.');
        }

        $formId = wp_insert_post([
            'post_type' => FormPosts::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Jelentkezés',
            'post_content' => $this->definition(),
            'meta_input' => [
                FormPosts::META_EVENT_ID => $published[0]->id,
                FormPosts::META_PLACEMENT => 'registration',
            ],
        ], true);
        if (is_wp_error($formId)) {
            throw new \RuntimeException('The sample form could not be written.');
        }

        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Jelentkezés',
            'post_content' => $this->block('kalaka/form', ['formId' => $formId]),
        ], true);
        if (is_wp_error($pageId)) {
            throw new \RuntimeException('The sample page could not be written.');
        }
    }

    private function definition(): string
    {
        $nights = $this->block('kalaka/text-field', ['key' => 'ejszakak', 'label' => 'Hány éjszakára?']);

        return $this->block('kalaka/name-field', ['key' => 'nev', 'label' => 'Név', 'displayName' => true])
            . $this->block('kalaka/email-field', ['key' => 'email', 'label' => 'E-mail-cím', 'contactEmail' => true])
            . $this->block('kalaka/choice-field', [
                'key' => 'szallas',
                'label' => 'Kérsz szállást?',
                'options' => [
                    ['value' => 'igen', 'label' => 'Igen'],
                    ['value' => 'nem', 'label' => 'Nem'],
                ],
            ])
            . $this->block('kalaka/if', [
                'condition' => [
                    'match' => 'all',
                    'items' => [['key' => 'szallas', 'operator' => 'equals', 'value' => 'igen']],
                ],
            ], $nights);
    }

    /** @param array<string, mixed> $attributes */
    private function block(string $name, array $attributes, string $inner = ''): string
    {
        return serialize_block([
            'blockName' => $name,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => $inner === '' ? [] : [$inner],
        ]);
    }
}
