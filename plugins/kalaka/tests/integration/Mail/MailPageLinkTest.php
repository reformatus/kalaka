<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Mail\BodyUnusable;
use Kalaka\Registrations\Api\SecretLinks;

/**
 * The page-link value `page_<ID>.link` (the 2026-11-10 decision, "link to page X"): the registrant's own secret
 * link on a published page, made only for a body that places it.
 */
final class MailPageLinkTest extends MailBodyTestCase
{
    private function page(string $status = 'publish', string $type = 'page'): int
    {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => $status, 'post_title' => 'Átrangsorolás'], true);
        self::assertIsInt($id);

        return $id;
    }

    private function placing(int $page): string
    {
        return $this->paragraph('<span data-kalaka-value="page_' . $page . '.link">link</span>');
    }

    public function testItRendersTheRegistrantsSecretLinkOnThePage(): void
    {
        $event = $this->published();
        $key = $this->registration($event);
        $page = $this->page();
        $body = $this->document($event, $this->placing($page));

        $html = (new MailRenderer())->renderBody($event, $body, $key, self::now())->html;

        $link = add_query_arg('kalaka_link', $event . '.12A3.' . self::SECRET, (string) get_permalink($page));
        $link = esc_html($link);
        self::assertStringContainsString($link, $html);
        self::assertEquals($key, (new SecretLinks())->fromLink($event . '.12A3.' . self::SECRET));
    }

    public function testEveryRegistrantGetsTheirOwnLink(): void
    {
        $event = $this->published();
        $first = $this->registration($event, '12A3');
        $second = $this->registration($event, '12B4');
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['secret' => 'MasikTitokMasikTitok02'],
            ['code' => '12B4']
        );
        ObjectCache::clear();
        $body = $this->document($event, $this->placing($this->page()));

        $renderer = new MailRenderer();

        self::assertStringContainsString(self::SECRET, $renderer->renderBody($event, $body, $first, self::now())->html);
        self::assertStringContainsString(
            'MasikTitokMasikTitok02',
            $renderer->renderBody($event, $body, $second, self::now())->html
        );
    }

    public function testAPageThatIsNotPublishedOrNotAPageIsAnUndeclaredKey(): void
    {
        $event = $this->published();
        foreach ([$this->page('draft'), $this->page('publish', 'post'), 999999] as $page) {
            $content = $this->placing($page);

            self::assertStringStartsWith('unknown_value_key', (string) BodyCheck::problem($content, $event));
        }
        self::assertNull(BodyCheck::problem($this->placing($this->page()), $event));
    }

    public function testAPageUnpublishedAfterTheMailWasQueuedIsTheBodysOwnProblem(): void
    {
        $event = $this->published();
        $key = $this->registration($event);
        $page = $this->page();
        $body = $this->document($event, $this->placing($page));
        wp_update_post(['ID' => $page, 'post_status' => 'draft']);

        try {
            (new MailRenderer())->renderBody($event, $body, $key, self::now());
            self::fail('The page is gone, the body cannot render.');
        } catch (BodyUnusable $e) {
            self::assertStringStartsWith('unknown_value_key', $e->problem);
            self::assertStringNotContainsString(self::SECRET, $e->problem);
        }
    }

    public function testOnlyABodyThatPlacesTheKeyMakesALink(): void
    {
        $event = $this->published();
        $page = $this->page();

        self::assertSame([], MailValues::pageLinks($this->paragraph('Szia')));
        self::assertSame([$page], MailValues::pageLinks($this->placing($page) . $this->placing($page)));
        self::assertSame([], MailValues::pageLinks($this->branch(
            'if',
            $this->paragraph('x'),
            ['match' => 'all', 'items' => [['key' => 'page_' . $page . '.link', 'operator' => 'empty']]]
        )));
        self::assertArrayNotHasKey('page_' . $page . '.link', (new MailValues())->types($event));
    }
}
