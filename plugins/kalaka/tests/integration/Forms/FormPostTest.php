<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** AC1 and AC2: the `kalaka_form` post type, its settings and its versions. */
final class FormPostTest extends FormsTestCase
{
    public function testThePostTypeIsRegisteredAsDecided(): void
    {
        $type = get_post_type_object('kalaka_form');

        self::assertInstanceOf(\WP_Post_Type::class, $type);
        self::assertFalse($type->public);
        self::assertTrue($type->show_ui);
        self::assertFalse($type->show_in_menu);
        self::assertTrue($type->show_in_rest);
        self::assertSame(\Kalaka\Forms\FormRestController::class, $type->rest_controller_class);
        self::assertTrue(use_block_editor_for_post_type('kalaka_form'));
        self::assertTrue(post_type_supports('kalaka_form', 'title'));
        self::assertTrue(post_type_supports('kalaka_form', 'editor'));
        self::assertTrue(post_type_supports('kalaka_form', 'revisions'));
        self::assertTrue(post_type_supports('kalaka_form', 'custom-fields'));
        self::assertSame('Űrlap', $type->labels->singular_name);
        self::assertSame('Űrlap szerkesztése', $type->labels->edit_item);
        self::assertSame('Új űrlap hozzáadása', $type->labels->add_new_item);
        self::assertSame('Űrlap frissítve.', $type->labels->item_updated);
        self::assertFalse(post_type_supports('kalaka_form', 'thumbnail'));
        foreach ((array) $type->cap as $name => $capability) {
            self::assertSame(AdminAccess::FULL_ACCESS, $capability, 'capability ' . $name);
        }
    }

    public function testTheSettingsAreRegisteredAsTypedSinglePostMetaInRestForFullAccessOnly(): void
    {
        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();

        $registered = get_registered_meta_keys('post', 'kalaka_form');

        self::assertSame('integer', $registered['kalaka_event_id']['type']);
        self::assertSame('string', $registered['kalaka_placement']['type']);
        self::assertSame('string', $registered['kalaka_required_role']['type']);
        foreach (['kalaka_event_id', 'kalaka_placement', 'kalaka_required_role'] as $key) {
            self::assertTrue($registered[$key]['single'], $key);
            self::assertNotFalse($registered[$key]['show_in_rest'], $key);
        }
        $full = $this->fullAccessUser();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $id = $this->slotForm();
        foreach (['kalaka_event_id', 'kalaka_placement', 'kalaka_required_role'] as $key) {
            self::assertTrue(user_can($full, 'edit_post_meta', $id, $key), $key);
            self::assertFalse(user_can($admin, 'edit_post_meta', $id, $key), $key);
        }
    }

    public function testAPublishedFormReadsBackWithItsSettings(): void
    {
        $id = $this->insertForm($this->slotFields(), [
            'kalaka_event_id' => 12,
            'kalaka_placement' => 'additional',
            'kalaka_required_role' => 'editor',
        ], ['post_password' => 'titok']);

        $form = $this->readForm($id);

        self::assertSame($id, $form->id);
        self::assertSame(12, $form->eventId);
        self::assertSame(FormPlacement::Additional, $form->placement);
        self::assertSame('editor', $form->requiredRole);
        self::assertTrue($form->hasPassword);
    }

    public function testAFormWithoutRoleOrPasswordReadsWithNeither(): void
    {
        $form = $this->readForm($this->slotForm());

        self::assertSame(FormPlacement::Registration, $form->placement);
        self::assertNull($form->requiredRole);
        self::assertFalse($form->hasPassword);
    }

    public function testAnythingButAPublishedFormReadsAsNull(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);

        self::assertNull($this->forms->form($page));
        self::assertNull($this->forms->form(987654321));
        self::assertNull($this->forms->form($this->insertForm($this->slotFields(), [], ['post_status' => 'draft'])));
        self::assertNull($this->forms->form($this->insertForm($this->slotFields(), [], ['post_status' => 'private'])));
        self::assertNull($this->forms->form($this->insertForm($this->slotFields(), [], ['post_status' => 'trash'])));
    }

    public function testAFormWithAMissingOrInvalidEventOrPlacementReadsAsNull(): void
    {
        foreach (
            [
                ['kalaka_event_id' => ''],
                ['kalaka_event_id' => 0],
                ['kalaka_event_id' => 'abc'],
                ['kalaka_placement' => ''],
                ['kalaka_placement' => 'slot'],
            ] as $meta
        ) {
            self::assertNull(
                $this->forms->form($this->insertForm($this->slotFields(), $meta)),
                (string) wp_json_encode($meta)
            );
        }

        $noMeta = wp_insert_post(['post_type' => 'kalaka_form', 'post_status' => 'publish', 'post_title' => 'Üres']);
        self::assertIsInt($noMeta);
        self::assertNull($this->forms->form($noMeta));
    }

    public function testOnlyFullAccessMayEditOrDeleteAForm(): void
    {
        $id = $this->slotForm();
        $plain = self::factory()->user->create(['role' => 'administrator']);
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        $full = $this->fullAccessUser();

        foreach (['edit_post', 'delete_post', 'read_post'] as $capability) {
            self::assertTrue(user_can($full, $capability, $id), $capability);
            self::assertFalse(user_can($subscriber, $capability, $id), $capability);
        }
        self::assertTrue(user_can($full, AdminAccess::FULL_ACCESS));
        self::assertFalse(user_can($plain, AdminAccess::FULL_ACCESS));
        self::assertFalse(user_can($plain, 'edit_post', $id));
        self::assertFalse(user_can($plain, 'delete_post', $id));
    }

    public function testAFormHasOneRevisionAtOnceAndItsVersionIsThatRevisionsId(): void
    {
        $id = $this->slotForm();

        $revisions = $this->revisionIds($id);

        self::assertCount(1, $revisions);
        self::assertSame($revisions[0], $this->readForm($id)->version);
    }

    public function testAChangeOfContentGivesANewGreaterVersion(): void
    {
        $id = $this->slotForm();
        $first = $this->readForm($id)->version;

        wp_update_post(['ID' => $id, 'post_content' => $this->slotFields() . $this->field('text', 'note')]);
        $second = $this->readForm($id)->version;

        self::assertGreaterThan($first, $second);
        self::assertCount(2, $this->revisionIds($id));
    }

    public function testAChangeOfTheTitleAloneGivesANewVersionToo(): void
    {
        $id = $this->slotForm();
        $first = $this->readForm($id)->version;

        wp_update_post(['ID' => $id, 'post_title' => 'Más név']);

        self::assertGreaterThan($first, $this->readForm($id)->version);
    }

    public function testSettingsAndStatusAreNotPartOfTheVersion(): void
    {
        $id = $this->slotForm();
        $first = $this->readForm($id)->version;

        update_post_meta($id, 'kalaka_required_role', 'editor');
        update_post_meta($id, 'kalaka_event_id', 99);
        self::assertSame($first, $this->readForm($id)->version);

        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        wp_update_post(['ID' => $id, 'post_password' => 'titok']);

        self::assertSame($first, $this->readForm($id)->version);
        self::assertCount(1, $this->revisionIds($id));
    }

    public function testAnEarlierVersionParsesTheContentItHad(): void
    {
        $id = $this->insertForm($this->slotFields());
        $first = $this->readForm($id)->version;
        wp_update_post(['ID' => $id, 'post_content' => $this->slotFields() . $this->field('text', 'note')]);
        $form = $this->readForm($id);

        $current = $this->forms->definition($form);
        $earlier = $this->forms->definition($form, $first);

        self::assertSame(
            ['name', 'email', 'note'],
            array_map(static fn ($field): string => $field->key, $current->fields)
        );
        self::assertSame(['name', 'email'], array_map(static fn ($field): string => $field->key, $earlier->fields));
        self::assertSame($form->version, $current->version);
        self::assertSame($first, $earlier->version);
    }

    public function testAVersionThatIsNotARevisionOfThatFormIsRefused(): void
    {
        $one = $this->slotForm();
        $two = $this->slotForm();
        $page = self::factory()->post->create(['post_type' => 'page']);
        $form = $this->readForm($one);

        foreach ([$this->readForm($two)->version, $two, $page, 0, 987654321] as $version) {
            try {
                $this->forms->definition($form, $version);
                self::fail('Version ' . $version . ' was accepted.');
            } catch (SystemFailure $e) {
                self::assertStringContainsString('unknown_version', $e->getMessage());
            }
        }
    }

    public function testAFormWhoseOnlyRevisionWasRemovedCannotBeRead(): void
    {
        $id = $this->slotForm();
        wp_delete_post_revision($this->readForm($id)->version);

        try {
            $this->forms->form($id);
            self::fail('A form without a revision was read.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('no_revision', $e->getMessage());
        }
    }

    public function testRevisionsOfFormsAreNeverPruned(): void
    {
        $filter = static fn (): int => 3;
        add_filter('wp_revisions_to_keep', $filter);
        try {
            $post = get_post($this->slotForm());
            self::assertInstanceOf(\WP_Post::class, $post);

            self::assertSame(-1, wp_revisions_to_keep($post));

            $page = get_post(self::factory()->post->create(['post_type' => 'post']));
            self::assertInstanceOf(\WP_Post::class, $page);
            self::assertSame(3, wp_revisions_to_keep($page));
        } finally {
            remove_filter('wp_revisions_to_keep', $filter);
        }
    }

    public function testManyChangesKeepEveryRevision(): void
    {
        $id = $this->slotForm();
        for ($i = 1; $i <= 6; $i++) {
            wp_update_post(['ID' => $id, 'post_content' => $this->slotFields() . $this->field('text', 'note' . $i)]);
        }

        self::assertCount(7, $this->revisionIds($id));
    }

    public function testDeletingAFormDeletesItsRevisions(): void
    {
        $id = $this->slotForm();
        wp_update_post(['ID' => $id, 'post_title' => 'Más név']);
        $revisions = $this->revisionIds($id);
        self::assertCount(2, $revisions);

        wp_delete_post($id, true);

        foreach ($revisions as $revision) {
            self::assertNull(get_post($revision));
        }
    }
}
