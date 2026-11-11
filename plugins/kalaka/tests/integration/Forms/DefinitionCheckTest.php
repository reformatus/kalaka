<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\BrokenForms;
use Kalaka\Forms\BrokenFormsWarning;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** A published form never goes from working to broken through an edit; one that is broken stays closed. */
final class DefinitionCheckTest extends FormsTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
        $user = self::factory()->user->create();
        $wpUser = get_userdata($user);
        self::assertInstanceOf(\WP_User::class, $wpUser);
        $wpUser->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);
    }

    /** @param array<string, mixed> $params */
    private function rest(string $route, array $params): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', $route);
        $request->set_body_params($params);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    private function unknownBlock(): string
    {
        return $this->block('kalaka/nope-field', ['key' => 'nope']);
    }

    public function testTheEditorCannotPublishABrokenFormAndIsToldWhy(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $this->unknownBlock(),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('kalaka_form_definition', $data['code']);
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            $data['message']
        );
        self::assertSame([], get_posts(['post_type' => 'kalaka_form', 'post_status' => 'any']));
    }

    public function testASlotFormWithoutItsContactEmailCannotBePublished(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->field('name', 'name', ['displayName' => true]),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertStringContainsString('contact_email_count', (string) $data['message']);
    }

    public function testABrokenDraftCanBeSaved(): void
    {
        $response = $this->rest('/wp/v2/kalaka_form', [
            'title' => 'Regisztráció',
            'status' => 'draft',
            'content' => $this->unknownBlock(),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        self::assertSame(201, $response->get_status());
    }

    public function testAnEditThatWouldBreakAPublishedFormIsRefusedAndTheFormKeepsWorking(): void
    {
        $id = $this->insertChecked($this->slotFields());
        $version = $this->readForm($id)->version;

        $response = $this->rest(
            '/wp/v2/kalaka_form/' . $id,
            ['content' => $this->slotFields() . $this->unknownBlock()]
        );

        self::assertSame(400, $response->get_status());
        self::assertSame($version, $this->readForm($id)->version);
        self::assertCount(2, $this->forms->definition($this->readForm($id))->fields);

        $result = wp_update_post([
            'ID' => $id,
            'post_content' => wp_slash($this->slotFields() . $this->unknownBlock()),
        ]);
        self::assertSame($id, $result);
        self::assertSame($version, $this->readForm($id)->version);
        self::assertStringNotContainsString('nope-field', (string) get_post_field('post_content', $id));
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
    }

    public function testAPlacementChangeOverRestIsCheckedWithTheNewPlacement(): void
    {
        $slot = $this->insertChecked($this->slotFields());
        $toAdditional = $this->rest('/wp/v2/kalaka_form/' . $slot, ['meta' => ['kalaka_placement' => 'additional']]);
        self::assertSame(400, $toAdditional->get_status());
        $data = $toAdditional->get_data();
        self::assertIsArray($data);
        self::assertStringContainsString('marking_on_additional_form', (string) $data['message']);
        self::assertSame('registration', get_post_meta($slot, 'kalaka_placement', true));

        $moved = $this->rest('/wp/v2/kalaka_form/' . $slot, [
            'content' => $this->field('text', 'note'),
            'meta' => ['kalaka_placement' => 'additional'],
        ]);
        self::assertSame(200, $moved->get_status());
        self::assertSame('additional', get_post_meta($slot, 'kalaka_placement', true));

        $additional = $this->insertChecked($this->field('text', 'note'), ['kalaka_placement' => 'additional']);
        $toSlot = $this->rest('/wp/v2/kalaka_form/' . $additional, ['meta' => ['kalaka_placement' => 'registration']]);
        self::assertSame(400, $toSlot->get_status());
        $data = $toSlot->get_data();
        self::assertIsArray($data);
        self::assertStringContainsString('contact_email_count', (string) $data['message']);

        $promoted = $this->rest('/wp/v2/kalaka_form/' . $additional, [
            'content' => $this->slotFields(),
            'meta' => ['kalaka_placement' => 'registration'],
        ]);
        self::assertSame(200, $promoted->get_status());
        self::assertSame('registration', get_post_meta($additional, 'kalaka_placement', true));
        self::assertCount(2, $this->forms->definition($this->readForm($additional))->fields);
    }

    public function testCodeCannotPublishABrokenFormEitherItIsKeptAsADraftWithANotice(): void
    {
        $id = $this->insertChecked($this->slotFields() . $this->unknownBlock());

        self::assertSame('draft', get_post_status($id));
        self::assertNull($this->forms->form($id));
        ob_start();
        DefinitionCheck::notice();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('unknown_field_block („kalaka/nope-field”)', $html);
        self::assertFalse(get_transient(DefinitionCheck::NOTICE . get_current_user_id()));
    }

    public function testRestoringABrokenRevisionKeepsThePublishedFormWorking(): void
    {
        // A revision from before the check: the form was published broken, then fixed.
        $id = $this->insertForm($this->slotFields() . $this->unknownBlock());
        $broken = $this->readForm($id)->version;
        wp_update_post(['ID' => $id, 'post_content' => wp_slash($this->slotFields())]);
        $version = $this->readForm($id)->version;
        self::assertNotSame($broken, $version);

        $restored = wp_restore_post_revision($broken);

        self::assertSame($id, $restored);
        self::assertSame('publish', get_post_status($id));
        self::assertSame($version, $this->readForm($id)->version);
        self::assertCount(2, $this->forms->definition($this->readForm($id))->fields);
        self::assertSame(
            'Az űrlapot így nem lehet menteni: unknown_field_block („kalaka/nope-field”).',
            get_transient(DefinitionCheck::NOTICE . get_current_user_id())
        );
    }

    public function testTheBrokenFormListIgnoresOtherPostTypesAndAutosaves(): void
    {
        $id = $this->insertChecked($this->slotFields());
        delete_option(BrokenForms::OPTION);

        self::factory()->post->create();
        $post = get_post($id, ARRAY_A);
        self::assertIsArray($post);
        _wp_put_post_revision(['post_content' => $this->unknownBlock()] + $post, true);

        self::assertNull(get_option(BrokenForms::OPTION, null));
    }

    public function testAFormBrokenBeforeTheCheckStaysClosedAndIsNamedInASiteWarning(): void
    {
        $working = $this->insertChecked($this->slotFields());
        $broken = $this->insertForm($this->slotFields() . $this->unknownBlock(), [], ['post_title' => 'Régi űrlap']);

        $shown = (new BrokenFormsWarning())->check();

        self::assertNotNull($shown);
        self::assertSame('Hibás közzétett űrlap', $shown->title);
        self::assertSame('#' . $broken . ' Régi űrlap: unknown_field_block kalaka/nope-field', $shown->details);
        self::assertStringNotContainsString('#' . $working . ' ', (string) $shown->details);
        $this->expectException(SystemFailure::class);
        $this->forms->definition($this->readForm($broken));
    }

    public function testTheWarningReadsTheStoredListWhichIsComputedOnceWhenMissing(): void
    {
        update_option(BrokenForms::OPTION, ['#1 Valami: invalid_key X'], false);
        $shown = (new BrokenFormsWarning())->check();
        self::assertNotNull($shown);
        self::assertSame('#1 Valami: invalid_key X', $shown->details);

        $broken = $this->insertForm($this->slotFields() . $this->unknownBlock(), [], ['post_title' => 'Régi']);
        delete_option(BrokenForms::OPTION);
        self::assertNull((new BrokenFormsWarning())->check());
        BrokenForms::ensure();
        self::assertSame(['#' . $broken . ' Régi: unknown_field_block kalaka/nope-field'], BrokenForms::stored());
        self::assertFalse(wp_load_alloptions()[BrokenForms::OPTION] ?? false);
    }

    public function testNoWarningWhileEveryPublishedFormWorks(): void
    {
        $this->insertChecked($this->slotFields());

        self::assertNull((new BrokenFormsWarning())->check());
    }
}
