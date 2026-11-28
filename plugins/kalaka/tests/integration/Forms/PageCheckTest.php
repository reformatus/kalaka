<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\PageCheck;

/**
 * A page's typed placeholders (owner 2026-11-27, walkthrough 8.11 a): every `{{key}}` inside a Kaláka block is a
 * key the participant side declares for a page, at the editor's save and on any other path; a `{{` outside those
 * blocks is ordinary text and is left alone.
 */
final class PageCheckTest extends \WP_UnitTestCase
{
    private int $user = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->user);
    }

    private function paragraph(string $text): string
    {
        return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
    }

    /** A participant block as a page carries it, holding the markup. */
    private function statusBlock(string $inner): string
    {
        return '<!-- wp:kalaka/status -->' . $inner . '<!-- /wp:kalaka/status -->';
    }

    private function page(string $content, string $status = 'publish'): int
    {
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => $status,
            'post_title' => 'Levelek',
            'post_content' => $content,
        ], true);
        self::assertIsInt($id);

        return $id;
    }

    /** @param array<string, mixed> $params */
    private function restSave(int $id, array $params): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/wp/v2/pages/' . $id);
        $request->set_body_params($params);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    public function testEveryKeyOfAPageInsideAKalakaBlockSaves(): void
    {
        $content = $this->statusBlock($this->paragraph('{{registration.code}} — {{event.name}}'));
        $id = $this->page($content);

        self::assertSame('publish', get_post_status($id));
        self::assertSame(200, $this->restSave($id, ['content' => $content])->get_status());
    }

    public function testAnUnknownPlaceholderIsRefusedAtTheEditorsSave(): void
    {
        $id = $this->page($this->paragraph('Üres'));
        $bad = $this->statusBlock($this->paragraph('{{nincs}} és {{mas}}'));

        $response = $this->restSave($id, ['content' => $bad]);

        self::assertSame(422, $response->get_status());
        $error = $response->as_error();
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('kalaka_unknown_placeholder', $error->get_error_code());
        $data = $error->get_error_data();
        self::assertIsArray($data);
        self::assertSame(['{{nincs}}', '{{mas}}'], $data['keys']);
        self::assertStringContainsString(
            'Ismeretlen jelölő: {{nincs}}, {{mas}}. A mentéshez javítani vagy törölni kell.',
            $error->get_error_message()
        );
        self::assertSame($this->paragraph('Üres'), get_post_field('post_content', $id));
    }

    public function testANonRestSaveKeepsTheStoredContentAndSaysWhy(): void
    {
        $id = $this->page($this->paragraph('Szia'));
        $bad = $this->statusBlock($this->paragraph('{{nincs}}'));

        wp_update_post(['ID' => $id, 'post_content' => $bad]);

        self::assertSame('publish', get_post_status($id));
        self::assertStringNotContainsString('{{nincs}}', (string) get_post_field('post_content', $id));
        self::assertSame(
            'A művelet nem hajtható végre. Ismeretlen jelölő: {{nincs}}. A mentéshez javítani vagy törölni kell.',
            get_transient(PageCheck::NOTICE . $this->user)
        );

        ob_start();
        PageCheck::notice();
        $notice = (string) ob_get_clean();
        self::assertStringContainsString('Ismeretlen jelölő: {{nincs}}.', $notice);
        self::assertFalse(get_transient(PageCheck::NOTICE . $this->user));
    }

    public function testPlainBracesOutsideKalakaBlocksAreLeftAlone(): void
    {
        $content = $this->paragraph('A {{nincs}} itt csak szöveg.');
        $id = $this->page($content);

        self::assertSame('publish', get_post_status($id));
        self::assertSame($content, get_post_field('post_content', $id));
        self::assertSame(200, $this->restSave($id, ['content' => $content])->get_status());
    }

    public function testAPageWithoutPlaceholdersSaves(): void
    {
        $content = $this->statusBlock($this->paragraph('Semmi jelölő.'));
        $id = $this->page($content);

        self::assertSame('publish', get_post_status($id));
        self::assertSame(200, $this->restSave($id, ['content' => $content])->get_status());
    }

    public function testADraftWithAnUnknownPlaceholderIsTrashedRestoredAndRefusedWhenPublished(): void
    {
        $bad = $this->statusBlock($this->paragraph('{{nincs}}'));
        $id = $this->page($bad, 'draft');
        self::assertSame('draft', get_post_status($id));
        self::assertSame($bad, get_post_field('post_content', $id));

        // A draft may hold the placeholder, and the status changes a trash and a restore make go through.
        wp_trash_post($id);
        self::assertSame('trash', get_post_status($id));
        wp_untrash_post($id);
        self::assertSame('draft', get_post_status($id));

        // Publishing it is refused, from the editor and from code alike.
        self::assertSame(422, $this->restSave($id, ['status' => 'publish'])->get_status());
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        self::assertSame('draft', get_post_status($id));
        self::assertSame($bad, get_post_field('post_content', $id));
    }
}
