<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
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

    public function testTheEditorOffersAMailTheConditionBlocksAndTheSlotButNoFieldBlock(): void
    {
        $slot = \WP_Block_Type_Registry::get_instance()->get_registered('kalaka/mail-content');
        self::assertNotNull($slot);
        self::assertSame('Levél szövege', $slot->title);
        $mail = get_post($this->document($this->create(), $this->paragraph('Szia')));
        $page = get_post(self::factory()->post->create(['post_type' => 'page']));

        $allowed = get_allowed_block_types(new \WP_Block_Editor_Context(['post' => $mail]));

        self::assertIsArray($allowed);
        foreach (['kalaka/if', 'kalaka/else-if', 'kalaka/else', 'kalaka/mail-content', 'core/paragraph'] as $name) {
            self::assertContains($name, $allowed);
        }
        self::assertSame([], array_values(array_filter(
            $allowed,
            static fn (string $name): bool => str_ends_with($name, '-field')
        )));
        self::assertTrue(get_allowed_block_types(new \WP_Block_Editor_Context(['post' => $page])));
    }

    public function testOutsideTheRendererTheSlotRendersNothing(): void
    {
        self::assertSame('', trim(do_blocks('<!-- wp:kalaka/mail-content /-->')));
    }
}
