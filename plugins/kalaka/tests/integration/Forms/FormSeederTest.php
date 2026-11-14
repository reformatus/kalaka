<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Cli\FormSeeder;
use Kalaka\Forms\FormPosts;
use Kalaka\Tests\Integration\Foundation\Seed\SeedTestCase;

/** AC10 (D30, D31): the seed's sample form and the page that embeds it. */
final class FormSeederTest extends SeedTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->contributions->seeder(new FormSeeder());
    }

    /** @return list<\WP_Post> */
    private function posts(string $type): array
    {
        return get_posts([
            'post_type' => $type,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
    }

    public function testOneRegistrationSlotFormForTheFirstSeededEventAndOnePageThatEmbedsIt(): void
    {
        $this->seeded();

        $forms = $this->posts(FormPosts::POST_TYPE);
        $pages = $this->posts('page');
        self::assertCount(1, $forms);
        self::assertCount(1, $pages);
        $events = (new \Kalaka\Events\Api\Events())->published();
        self::assertSame('Nyári tábor', $events[0]->name);

        $form = (new Forms())->form($forms[0]->ID);
        self::assertNotNull($form);
        self::assertSame($events[0]->id, $form->eventId);
        self::assertSame('registration', $form->placement->value);
        self::assertSame('Jelentkezés', $forms[0]->post_title);
        self::assertSame('publish', $forms[0]->post_status);
        self::assertSame('Jelentkezés', $pages[0]->post_title);
        self::assertSame('publish', $pages[0]->post_status);
        self::assertSame('<!-- wp:kalaka/form {"formId":' . $forms[0]->ID . '} /-->', $pages[0]->post_content);
        self::assertNotSame([], wp_get_post_revisions($forms[0]->ID));
    }

    public function testTheFormsDefinitionHasTheFourFieldsAndTheBranchOnTheChoice(): void
    {
        $this->seeded();
        $forms = new Forms();
        $form = $forms->form($this->posts(FormPosts::POST_TYPE)[0]->ID);
        self::assertNotNull($form);

        $definition = $forms->definition($form);

        self::assertSame(
            ['nev', 'email', 'szallas', 'ejszakak'],
            array_map(static fn ($field): string => $field->key, $definition->fields)
        );
        self::assertSame(
            ['Név', 'E-mail-cím', 'Kérsz szállást?', 'Hány éjszakára?'],
            array_map(static fn ($field): string => $field->label(), $definition->fields)
        );
        self::assertTrue($definition->fields[0]->isDisplayName());
        self::assertTrue($definition->fields[1]->isContactEmail());
        self::assertSame(
            [['igen', 'Igen'], ['nem', 'Nem']],
            array_map(
                static fn (array $option): array => [$option['value'], $option['label']],
                $definition->fields[2]->attributes['options']
            )
        );
        self::assertCount(1, $definition->fields[3]->branches);
    }

    public function testTheSeededFormIsOpenAtTheSeedDay(): void
    {
        $this->seeded();
        $forms = new Forms();
        $form = $forms->form($this->posts(FormPosts::POST_TYPE)[0]->ID);
        self::assertNotNull($form);
        $event = (new \Kalaka\Events\Api\Events())->find($form->eventId);
        self::assertNotNull($event);

        self::assertSame([], $forms->refusals($form, $event, $this->seedClock->now(), [], false));
    }

    public function testTheFormSeederNeedsAPublishedEvent(): void
    {
        $this->expectException(\LogicException::class);

        (new FormSeeder())->seed(new \Kalaka\Foundation\Api\Seed\Seeding($this->runner, $this->seedClock));
    }
}
