<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\Forms;
use Kalaka\Tests\Integration\Events\EventsTestCase;

require_once __DIR__ . '/../Events/EventsTestCase.php';

/** The base of the Forms tests: forms inserted the way code and WP-CLI do, as block markup in the content. */
abstract class FormsTestCase extends EventsTestCase
{
    protected Forms $forms;

    public function set_up(): void
    {
        parent::set_up();

        $this->forms = new Forms();
    }

    /** @param array<string, mixed> $attributes */
    protected function block(string $name, array $attributes): string
    {
        return serialize_block([
            'blockName' => $name,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function field(string $type, string $key, array $attributes = []): string
    {
        return $this->block('kalaka/' . $type . '-field', $attributes + ['key' => $key, 'label' => $key]);
    }

    /** The two marked fields of a registration-slot form. */
    protected function slotFields(): string
    {
        return $this->field('name', 'name', ['displayName' => true])
            . $this->field('email', 'email', ['contactEmail' => true]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $post
     */
    protected function insertForm(string $content, array $meta = [], array $post = []): int
    {
        $id = wp_insert_post($post + [
            'post_type' => 'kalaka_form',
            'post_status' => 'publish',
            'post_title' => 'Regisztráció',
            'post_content' => $content,
            'meta_input' => $meta + ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ], true);
        self::assertIsInt($id);

        return $id;
    }

    /** A registration-slot form with the two marked fields and more fields. */
    protected function slotForm(string $more = ''): int
    {
        return $this->insertForm($this->slotFields() . $more);
    }

    protected function readForm(int $id): Form
    {
        $form = $this->forms->form($id);
        self::assertNotNull($form);

        return $form;
    }

    /** @return list<int> the IDs of the form's revisions, oldest first */
    protected function revisionIds(int $formId): array
    {
        $ids = array_map(static fn (\WP_Post $revision): int => $revision->ID, wp_get_post_revisions($formId, ['order' => 'ASC']));

        return array_values($ids);
    }
}
