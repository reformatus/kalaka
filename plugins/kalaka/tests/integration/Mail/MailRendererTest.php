<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Registrations\Api\RegistrationKey;

/** The renderer: a mail's body, its layout and its context. mail.md, "Content". */
final class MailRendererTest extends MailBodyTestCase
{
    private function waitlistChain(): string
    {
        return $this->branch(
            'if',
            $this->paragraph('Várólistán vagy.'),
            [
                'match' => 'all',
                'items' => [['key' => 'registration.status', 'operator' => 'equals', 'value' => 'waitlisted']],
            ]
        ) . "\n" . $this->branch('else', $this->paragraph('Bent vagy.'));
    }

    public function testTheBodyRendersWithTheRecipientTheRegistrationAndItsFields(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->paragraph('Kedves <span data-kalaka-value="recipient.name">név</span>, kódod '
                . '<span data-kalaka-value="registration.code">kód</span>, táborod '
                . '<span data-kalaka-value="camps">tábor</span>, állapotod '
                . '<span data-kalaka-value="registration.status">állapot</span>.')
            . "\n" . $this->waitlistChain(),
            'Jelentkezés &amp; tábor'
        );
        $this->switchOn($event, 'registered', $body);

        $mail = (new MailRenderer())->render($event, 'registered', $key);

        self::assertSame('Jelentkezés & tábor', $mail->subject);
        self::assertStringContainsString(
            'Kedves Teszt Elek, kódod 12A3, táborod Fiú, állapotod rögzített. Bent vagy.',
            self::text($mail->html)
        );
        self::assertStringContainsString('Bent vagy.', $mail->html);
        self::assertStringNotContainsString('Várólistán vagy.', $mail->html);
        self::assertStringNotContainsString('data-kalaka-value', $mail->html);
        $revision = wp_get_latest_revision_id_and_total_count($body);
        self::assertIsArray($revision);
        self::assertSame('b' . $revision['latest_id'], $mail->version);
    }

    public function testTheLinkCarriesTheSecretAndTheRegistrationKey(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document($event, $this->paragraph('<span data-kalaka-value="registration.link">link</span>'));
        $this->switchOn($event, 'link_regenerated', $body);

        $html = (new MailRenderer())->render($event, 'link_regenerated', $key)->html;

        self::assertSame(1, preg_match('/<span\s*>([^<]+)<\/span>/', $html, $match));
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        self::assertStringStartsWith(home_url('/'), $url);
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(
            ['kalaka_event' => (string) $event, 'kalaka_code' => '12A3', 'kalaka_link' => self::SECRET],
            $query
        );
    }

    public function testTheLayoutWrapsTheBodyAndAChainInTheBodyStillResolves(): void
    {
        $event = $this->create();
        $key = $this->registration($event, status: 'waitlisted');
        $layout = $this->document(
            $event,
            $this->paragraph('Fejléc') . "\n<!-- wp:group -->\n<div class=\"wp-block-group\">"
            . "<!-- wp:kalaka/mail-content /--></div>\n<!-- /wp:group -->\n" . $this->paragraph('Lábléc'),
            'Elrendezés'
        );
        $body = $this->document($event, $this->waitlistChain());
        $this->setLayout($event, $layout);
        $this->switchOn($event, 'waitlisted', $body);

        $mail = (new MailRenderer())->render($event, 'waitlisted', $key);

        self::assertSame('Fejléc Várólistán vagy. Lábléc', self::text($mail->html));
        $grouped = '/<div class="wp-block-group[^>]*>.*<p[^>]*>Várólistán vagy\.<\/p>\s*<\/div>/s';
        self::assertSame(1, preg_match($grouped, $mail->html), $mail->html);
        self::assertStringNotContainsString('Bent vagy.', $mail->html);
        $bodyRevision = wp_get_latest_revision_id_and_total_count($body);
        $layoutRevision = wp_get_latest_revision_id_and_total_count($layout);
        self::assertIsArray($bodyRevision);
        self::assertIsArray($layoutRevision);
        self::assertSame('b' . $bodyRevision['latest_id'] . '-l' . $layoutRevision['latest_id'], $mail->version);
    }

    public function testAMailThatDoesNotUseTheLayoutIsNotWrapped(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $layout = $this->document($event, $this->paragraph('Fejléc') . "\n<!-- wp:kalaka/mail-content /-->");
        $this->setLayout($event, $layout);
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')), false);

        $html = (new MailRenderer())->render($event, 'registered', $key)->html;

        self::assertStringContainsString('Szia', $html);
        self::assertStringNotContainsString('Fejléc', $html);
    }

    public function testAKeyTheMailDoesNotDeclareFailsWithItsKeyOnly(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document($event, $this->paragraph('<span data-kalaka-value="event.start">x</span>'));
        $this->switchOn($event, 'registered', $body);

        try {
            (new MailRenderer())->render($event, 'registered', $key);
            self::fail('Expected a failure.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('unknown_value_key event.start', $e->getMessage());
        }
    }

    public function testAMailWithoutABodyOrABodyNoLongerPublishedFails(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        wp_update_post(['ID' => $body, 'post_status' => 'draft']);

        $this->expectException(SystemFailure::class);
        (new MailRenderer())->render($event, 'registered', $key);
    }

    public function testTheRecipientIsTheContactEmailOrNoneWithoutThrowing(): void
    {
        $event = $this->create();
        $values = new MailValues();

        self::assertSame('teszt@example.test', $values->recipient($this->registration($event)));
        self::assertNull($values->recipient($this->registration($event, '00A0', ['name' => 'Teszt'])));
        self::assertNull($values->recipient(new RegistrationKey($event, '00B0')));
        $form = $this->slotForm($event);
        $withoutForm = $this->registration($event, '00J0', form: $form);
        wp_delete_post($form, true);
        self::assertNull($values->recipient($withoutForm));
    }

    /** The text of rendered HTML, its whitespace collapsed. */
    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
    }
}
