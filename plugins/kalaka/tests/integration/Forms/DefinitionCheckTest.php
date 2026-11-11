<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\BrokenFormsWarning;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Error\InvalidInput;
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

        $this->expectException(InvalidInput::class);
        wp_update_post(['ID' => $id, 'post_content' => wp_slash($this->slotFields() . $this->unknownBlock())]);
    }

    public function testCodeCannotPublishABrokenFormEither(): void
    {
        $this->expectException(InvalidInput::class);
        $this->insertChecked($this->slotFields() . $this->unknownBlock());
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

    public function testNoWarningWhileEveryPublishedFormWorks(): void
    {
        $this->insertChecked($this->slotFields());

        self::assertNull((new BrokenFormsWarning())->check());
    }
}
