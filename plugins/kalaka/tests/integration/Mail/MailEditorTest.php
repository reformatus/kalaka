<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Engine\BlockGate;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Mail\Module;

/** Mail documents in the block editor: full access only, the mail blocks, and the condition blocks inside them. */
final class MailEditorTest extends MailBodyTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new Module())->registerMeta();
    }

    private function get(string $route): \WP_REST_Response
    {
        $response = rest_do_request(new \WP_REST_Request('GET', $route));
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    public function testOnlyFullAccessReadsAndWritesMailDocumentsInRest(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));

        foreach ([0, self::factory()->user->create(['role' => 'administrator'])] as $user) {
            wp_set_current_user($user);
            self::assertContains($this->get('/wp/v2/kalaka_mail/' . $id)->get_status(), [401, 403]);
            self::assertContains($this->get('/wp/v2/kalaka_mail')->get_status(), [401, 403]);
            $create = new \WP_REST_Request('POST', '/wp/v2/kalaka_mail');
            $create->set_body_params(['title' => 'Új']);
            self::assertContains(rest_do_request($create)->get_status(), [401, 403]);
        }

        $full = self::factory()->user->create();
        $user = get_userdata($full);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($full);
        $response = $this->get('/wp/v2/kalaka_mail/' . $id);
        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame($event, $data['meta']['kalaka_event_id']);
    }

    public function testAMailDocumentHasARevisionAtOnce(): void
    {
        $id = $this->document($this->create(), $this->paragraph('Szia'));

        $revision = wp_get_latest_revision_id_and_total_count($id);
        self::assertIsArray($revision);
        self::assertGreaterThan(0, $revision['latest_id']);
    }

    public function testABodyASwitchedOnMailUsesIsNeitherTrashedNorDeleted(): void
    {
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);

        self::assertFalse(wp_trash_post($body));
        self::assertFalse(wp_delete_post($body, true));
        self::assertSame('publish', get_post_status($body));

        $full = self::factory()->user->create();
        $user = get_userdata($full);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($full);
        foreach ([false, true] as $force) {
            $request = new \WP_REST_Request('DELETE', '/wp/v2/kalaka_mail/' . $body);
            $request->set_param('force', $force);
            $response = rest_do_request($request);
            self::assertSame(409, $response->get_status());
            $error = $response->as_error();
            self::assertInstanceOf(\WP_Error::class, $error);
            self::assertSame('kalaka_mail_in_use', $error->get_error_code());
            self::assertSame(
                'A művelet nem hajtható végre. Ezt a szöveget egy bekapcsolt levél használja.',
                $error->get_error_message()
            );
        }
        self::assertSame('publish', get_post_status($body));

        // Switched off, the body may go.
        $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);
        self::assertInstanceOf(\WP_Post::class, wp_trash_post($body));
        self::assertInstanceOf(\WP_Post::class, wp_delete_post($body, true));
    }

    public function testABodyInUseStaysPublishedAndKeepsItsEventInCode(): void
    {
        $event = $this->create();
        $other = $this->create('Másik');
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);

        foreach (['draft', 'pending', 'private', 'trash'] as $status) {
            wp_update_post(['ID' => $body, 'post_status' => $status]);
            self::assertSame('publish', get_post_status($body), $status);
        }
        self::assertFalse(update_post_meta($body, 'kalaka_event_id', $other));
        self::assertFalse(delete_post_meta($body, 'kalaka_event_id'));
        wp_update_post(['ID' => $body, 'meta_input' => ['kalaka_event_id' => $other]]);
        self::assertSame((string) $event, get_post_meta($body, 'kalaka_event_id', true));
        // A save that changes only the text goes on.
        wp_update_post(['ID' => $body, 'post_content' => $this->paragraph('Szia újra')]);
        self::assertStringContainsString('Szia újra', (string) get_post_field('post_content', $body));

        // Switched off, the body may change.
        $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);
        wp_update_post(['ID' => $body, 'post_status' => 'draft']);
        self::assertSame('draft', get_post_status($body));
        self::assertTrue((bool) update_post_meta($body, 'kalaka_event_id', $other));
    }

    public function testABodyInUseStaysPublishedAndKeepsItsEventInRest(): void
    {
        $event = $this->create();
        $other = $this->create('Másik');
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        $full = self::factory()->user->create();
        $user = get_userdata($full);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($full);

        $changes = [['status' => 'draft'], ['status' => 'private'], ['meta' => ['kalaka_event_id' => $other]]];
        foreach ($changes as $change) {
            $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_mail/' . $body);
            $request->set_body_params($change);
            $response = rest_do_request($request);
            self::assertSame(409, $response->get_status());
            $error = $response->as_error();
            self::assertInstanceOf(\WP_Error::class, $error);
            self::assertSame('kalaka_mail_in_use', $error->get_error_code());
            self::assertSame(
                'A művelet nem hajtható végre. Ezt a szöveget egy bekapcsolt levél használja.',
                $error->get_error_message()
            );
        }
        self::assertSame('publish', get_post_status($body));
        self::assertSame((string) $event, get_post_meta($body, 'kalaka_event_id', true));

        // An edit of the text, the same event and the same status go on.
        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_mail/' . $body);
        $request->set_body_params([
            'content' => $this->paragraph('Szia újra'),
            'status' => 'publish',
            'meta' => ['kalaka_event_id' => $event],
        ]);
        self::assertSame(200, rest_do_request($request)->get_status());
    }

    public function testTheVersionSkipsMoreAutosavesThanOneReadFetches(): void
    {
        $event = $this->create();
        $id = $this->document($event, $this->paragraph('Szia'));
        $version = (new MailPosts())->published($id, $event)->revision;
        $post = get_post($id, ARRAY_A);
        self::assertIsArray($post);

        // More autosaves than one read of the latest revision fetches, all newer than the version.
        for ($i = 0; $i < 7; $i++) {
            _wp_put_post_revision(['post_content' => $this->paragraph('Vázlat ' . $i)] + $post, true);
        }

        self::assertSame($version, (new MailPosts())->published($id, $event)->revision);
    }

    public function testTheEditorOffersAMailTheConditionBlocksButNoFieldOrPageBlock(): void
    {
        $mail = get_post($this->document($this->create(), $this->paragraph('Szia')));
        self::assertInstanceOf(\WP_Post::class, $mail);

        // Outside a form's editor an allow-all stays allow-all; the gated blocks are hidden on the client.
        self::assertTrue(get_allowed_block_types(new \WP_Block_Editor_Context(['post' => $mail])));
        $hidden = BlockGate::hidden($mail->post_type, 'core/edit-post', Contributions::current());
        foreach (array_keys(Contributions::current()->fieldTypes()) as $name) {
            self::assertContains($name, $hidden);
        }
        self::assertContains('kalaka/form-page', $hidden);
        foreach (Module::BODY_BLOCKS as $name) {
            self::assertNotContains($name, $hidden);
        }

        // A list from another filter keeps the condition blocks and loses the field blocks.
        self::assertSame(
            ['core/paragraph', ...Module::BODY_BLOCKS],
            BlockGate::allowed(
                ['core/paragraph', ...Module::BODY_BLOCKS, 'kalaka/text-field', 'kalaka/form-page'],
                $mail->post_type,
                'core/edit-post',
                Contributions::current()
            )
        );
    }
}
