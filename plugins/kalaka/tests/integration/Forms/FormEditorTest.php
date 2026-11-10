<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Module;

/** The block editor's access to forms: REST for full access only, and autosaves are never a version. */
final class FormEditorTest extends FormsTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
    }

    private function fullAccess(): int
    {
        $id = self::factory()->user->create();
        $user = get_userdata($id);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);

        return $id;
    }

    private function get(string $route): \WP_REST_Response
    {
        $response = rest_do_request(new \WP_REST_Request('GET', $route));
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    public function testOnlyFullAccessReadsFormsInRest(): void
    {
        $id = $this->slotForm();

        $users = [
            0,
            self::factory()->user->create(['role' => 'subscriber']),
            self::factory()->user->create(['role' => 'administrator']),
        ];
        foreach ($users as $user) {
            wp_set_current_user($user);
            self::assertContains($this->get('/wp/v2/kalaka_form/' . $id)->get_status(), [401, 403], 'user ' . $user);
            self::assertContains($this->get('/wp/v2/kalaka_form')->get_status(), [401, 403], 'user ' . $user);
            self::assertContains(
                $this->get('/wp/v2/kalaka_form/' . $id . '/revisions')->get_status(),
                [401, 403],
                'user ' . $user
            );
        }

        wp_set_current_user($this->fullAccess());
        $response = $this->get('/wp/v2/kalaka_form/' . $id);
        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(7, $data['meta']['kalaka_event_id']);
        self::assertSame('registration', $data['meta']['kalaka_placement']);
        self::assertSame(200, $this->get('/wp/v2/kalaka_form')->get_status());
    }

    public function testFullAccessCreatesAFormThroughRestWithItsSettingsAndDerivedKeys(): void
    {
        wp_set_current_user($this->fullAccess());
        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
        $request->set_body_params([
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->block('kalaka/name-field', ['label' => 'Név', 'displayName' => true])
                . $this->block('kalaka/email-field', ['label' => 'E-mail', 'contactEmail' => true]),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);

        $response = rest_do_request($request);

        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        $definition = $this->forms->definition($this->readForm((int) $data['id']));
        self::assertSame(['nev', 'e_mail'], array_map(static fn ($field): string => $field->key, $definition->fields));
    }

    public function testAnAdministratorWithoutFullAccessCannotWriteAForm(): void
    {
        $id = $this->slotForm();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form/' . $id);
        $request->set_body_params(['title' => 'Más']);

        self::assertContains(rest_do_request($request)->get_status(), [401, 403]);
        $create = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
        $create->set_body_params(['title' => 'Új']);
        self::assertContains(rest_do_request($create)->get_status(), [401, 403]);
    }

    public function testAnAutosaveIsNeverTheFormsVersion(): void
    {
        $id = $this->slotForm();
        $version = $this->readForm($id)->version;
        $post = get_post($id, ARRAY_A);
        self::assertIsArray($post);

        // What the block editor's autosave of a published form saves: a revision named `<id>-autosave-v1`.
        $autosave = _wp_put_post_revision(
            ['post_content' => $this->slotFields() . $this->field('text', 'draft_only')] + $post,
            true
        );
        self::assertIsInt($autosave);
        self::assertNotFalse(wp_is_post_autosave($autosave));

        self::assertSame($version, $this->readForm($id)->version);
        $this->expectException(\Kalaka\Foundation\Api\Error\SystemFailure::class);
        $this->forms->definition($this->readForm($id), $autosave);
    }
}
