<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\BodyUnusable;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Mail\RecipientUnavailable;
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

        $mail = (new MailRenderer())->render($event, 'registered', $key, self::now());

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

    public function testTheBodyReadsTheEventsValuesAndAConditionOnThem(): void
    {
        $event = $this->filled();
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->paragraph('<span data-kalaka-value="event.name">esemény</span>: '
                . '<span data-kalaka-value="event.start">kezdés</span> – '
                . '<span data-kalaka-value="event.end">vége</span>.')
            . "\n" . $this->branch(
                'if',
                $this->paragraph('Nyári tábor.'),
                [
                    'match' => 'all',
                    'items' => [['key' => 'event.start', 'operator' => 'greaterThan', 'value' => '2027-06-01']],
                ]
            ) . "\n" . $this->branch('else', $this->paragraph('Téli tábor.'))
        );
        $this->switchOn($event, 'registered', $body);

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;

        self::assertStringContainsString('Tábor: 2027. július 5. – 2027. július 12.', self::text($html));
        self::assertStringContainsString('Nyári tábor.', $html);
        self::assertStringNotContainsString('Téli tábor.', $html);
    }

    public function testABodyMayBranchOnTheMomentItIsRenderedAt(): void
    {
        $event = $this->filled();
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->branch(
                'if',
                $this->paragraph('Még nem kezdődött el.'),
                [
                    'match' => 'all',
                    'items' => [['key' => 'event.start', 'operator' => 'greaterThan', 'value' => ['ref' => 'now']]],
                ]
            ) . "\n" . $this->branch('else', $this->paragraph('Már elkezdődött.'))
        );
        $this->switchOn($event, 'registered', $body);
        $renderer = new MailRenderer();

        // The event starts on 2027-07-05: before it the first side shows, after it the other one.
        $before = $renderer->render($event, 'registered', $key, self::now())->html;
        $after = $renderer->render(
            $event,
            'registered',
            $key,
            new \DateTimeImmutable('2027-08-01 08:00:00', new \DateTimeZone('UTC'))
        )->html;

        self::assertStringContainsString('Még nem kezdődött el.', $before);
        self::assertStringNotContainsString('Már elkezdődött.', $before);
        self::assertStringContainsString('Már elkezdődött.', $after);
        self::assertStringNotContainsString('Még nem kezdődött el.', $after);
    }

    public function testABodyMayBranchOnWhetherTheRegistrationCameFromTheWaitlist(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->branch(
                'if',
                $this->paragraph('Felkerültél a várólistáról.'),
                [
                    'match' => 'all',
                    'items' => [['key' => 'registration.from_waitlist', 'operator' => 'equals', 'value' => 'yes']],
                ]
            ) . "\n" . $this->paragraph('Szia')
        );
        $this->switchOn($event, 'registered', $body);

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;

        // No admission act exists yet: every registration reads `no`.
        self::assertStringContainsString('Szia', $html);
        self::assertStringNotContainsString('várólistáról', $html);
    }

    public function testAnEventDateThatIsNotSetReadsAsNone(): void
    {
        $event = $this->create('Teszt tábor');
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->paragraph('Kezdés: <span data-kalaka-value="event.start">kezdés</span>.')
        );
        $this->switchOn($event, 'registered', $body);

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;

        self::assertStringContainsString('Kezdés: .', self::text($html));
    }

    public function testAKeyThatOnlyAnotherFormOfTheEventHoldsRendersEmptyWithoutFailing(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $this->additionalForm($event, 'shirt');
        $body = $this->document($event, $this->paragraph('Póló: [<span data-kalaka-value="shirt">méret</span>]')
            . "\n" . $this->branch(
                'if',
                $this->paragraph('Van póló.'),
                ['match' => 'all', 'items' => [['key' => 'shirt', 'operator' => 'empty', 'not' => true]]]
            ));
        $this->switchOn($event, 'registered', $body);

        $mail = (new MailRenderer())->render($event, 'registered', $key, self::now());

        self::assertSame('Póló: []', self::text($mail->html));
    }

    public function testASystemFieldReadsAsNoneUntilDecisionsCanBeRead(): void
    {
        global $wpdb;
        $event = $this->create();
        $key = $this->registration($event, values: ['name' => 'Teszt', 'email' => 'teszt@example.test', 'tier' => 'x']);
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $event,
            'field_key' => 'tier',
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'Sáv',
            'source' => 'system',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
        ]);
        $this->switchOn($event, 'registered', $this->document(
            $event,
            $this->paragraph('Sáv: [<span data-kalaka-value="tier">sáv</span>]')
        ));

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;
        self::assertSame('Sáv: []', self::text($html));
    }

    public function testTheLinkCarriesTheSecretAndTheRegistrationKey(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document($event, $this->paragraph('<span data-kalaka-value="registration.link">link</span>'));
        $this->switchOn($event, 'link_regenerated', $body);

        $html = (new MailRenderer())->render($event, 'link_regenerated', $key, self::now())->html;

        self::assertSame(1, preg_match('/<span\s*>([^<]+)<\/span>/', $html, $match));
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        self::assertStringStartsWith(home_url('/'), $url);
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        // One parameter, `<eventId>.<code>.<secret>`: the portal's link handler reads it (#183, SB-10).
        self::assertSame(['kalaka_link' => $event . '.12A3.' . self::SECRET], $query);
    }

    public function testTheLayoutWrapsTheRenderedBodyAsWritten(): void
    {
        $event = $this->create();
        $key = $this->registration($event, status: 'waitlisted');
        $body = $this->document($event, $this->waitlistChain());
        $this->setLayout($event, '<div style="color:#333">Fejléc <span data-kalaka-value="nincs">x</span>'
            . '{{content}} Lábléc &amp;</div>');
        $this->switchOn($event, 'waitlisted', $body);

        $mail = (new MailRenderer())->render($event, 'waitlisted', $key, self::now());

        $head = '<div style="color:#333">Fejléc <span data-kalaka-value="nincs">x</span>';
        self::assertStringStartsWith($head, $mail->html);
        self::assertStringEndsWith(' Lábléc &amp;</div>', $mail->html);
        self::assertStringContainsString('Várólistán vagy.', $mail->html);
        self::assertStringNotContainsString('Bent vagy.', $mail->html);
        $revision = wp_get_latest_revision_id_and_total_count($body);
        self::assertIsArray($revision);
        self::assertSame('b' . $revision['latest_id'] . '-l1', $mail->version);
    }

    public function testAMailThatDoesNotUseTheLayoutIsNotWrapped(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $this->setLayout($event, '<p>Fejléc</p>{{content}}');
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')), false);

        $html = (new MailRenderer())->render($event, 'registered', $key, self::now())->html;

        self::assertStringContainsString('Szia', $html);
        self::assertStringNotContainsString('Fejléc', $html);
    }

    public function testAKeyTheMailDoesNotDeclareFailsWithItsKeyOnly(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->brokenDocument($event, $this->paragraph('<span data-kalaka-value="event.place">x</span>'));
        $this->switchOn($event, 'registered', $body);

        try {
            (new MailRenderer())->render($event, 'registered', $key, self::now());
            self::fail('Expected a failure.');
        } catch (BodyUnusable $e) {
            self::assertSame('unknown_value_key event.place', $e->problem);
        }
    }

    public function testAMailWithoutABodyOrABodyNoLongerPublishedFails(): void
    {
        $event = $this->create();
        $key = $this->registration($event);
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        // Written around BodyInUse (which keeps a body in use published), straight in the table.
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $body]);
        clean_post_cache($body);

        try {
            (new MailRenderer())->render($event, 'registered', $key, self::now());
            self::fail('Expected a failure.');
        } catch (BodyUnusable $e) {
            self::assertSame('unpublished ' . $body, $e->problem);
        }
    }

    public function testTheRecipientIsTheContactEmailOrNoneOrUnavailableWithItsReason(): void
    {
        $event = $this->create();
        $values = new MailValues();

        self::assertSame('teszt@example.test', $values->recipient($this->registration($event)));
        self::assertNull($values->recipient($this->registration($event, '00A0', ['name' => 'Teszt'])));
        $form = $this->slotForm($event);
        $unpublished = $this->registration($event, '00J0', form: $form);
        wp_update_post(['ID' => $form, 'post_status' => 'draft']);

        $cases = [
            RecipientUnavailable::NO_REGISTRATION => new RegistrationKey($event, '00B0'),
            RecipientUnavailable::NO_RECIPIENT_FORM => $unpublished,
        ];
        foreach ($cases as $reason => $key) {
            try {
                $values->recipient($key);
                self::fail('Expected ' . $reason . '.');
            } catch (RecipientUnavailable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testTheRecipientComesFromTheVersionTheRegistrationWasSubmittedWith(): void
    {
        global $wpdb;
        $event = $this->create();
        $form = $this->slotForm($event);
        $key = $this->registration($event, form: $form);
        // A later save, then the current version broken against the event's fields (a key of another type),
        // as a form written around the save check is.
        wp_update_post(['ID' => $form, 'post_title' => 'Jelentkezés 2027']);
        $wpdb->update(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['type' => 'kalaka/text-field'],
            ['event_id' => $event, 'field_key' => 'email']
        );
        $current = (new Forms())->form($form);
        self::assertNotNull($current);
        try {
            (new Forms())->definition($current);
            self::fail('Expected the current version to be broken.');
        } catch (SystemFailure) {
            // The current version is broken; the submitted one is read as it was.
        }

        self::assertSame('teszt@example.test', (new MailValues())->recipient($key));
        $context = (new MailValues())->context($key, self::now());
        self::assertSame('teszt@example.test', $context->get('recipient.email')->text);
    }

    /** The text of rendered HTML, its whitespace collapsed. */
    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
    }
}
