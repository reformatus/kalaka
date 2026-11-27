<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Mail\Body\ActionMailComposer;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\MailBlocks;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\BodyUnusable;
use Kalaka\Mail\Sender;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * Money in a mail (owner topic 15, G2 and G7): the `payment.*` keys of a "payment recorded" mail, and the money
 * table and the transfer block placed in a body, rendered at send time through the same read as the status page.
 */
final class MoneyMailTest extends MailBodyTestCase
{
    private int $event;

    private ?RegistrationKey $registered = null;

    private int $seq = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->event = $this->published();
        $wpdb->update($wpdb->prefix . 'kalaka_events', [
            'transfer_beneficiary' => 'Példa Egyesület',
            'transfer_account' => '11111111-22222222-33333333',
            'transfer_reference_prefix' => 'TAB27',
            'payment_due_days' => 7,
        ], ['id' => $this->event]);
        ObjectCache::clear();
    }

    /** The registration under test, with its owed 60 000 Ft, made on first use. */
    private function key(): RegistrationKey
    {
        if ($this->registered === null) {
            $this->registered = $this->registration($this->event);
            $this->line('charge', '60000');
        }

        return $this->registered;
    }

    /** A ledger line written behind the repositories; returns its ID. */
    private function line(
        string $kind,
        string $amount,
        ?string $method = null,
        ?string $day = null,
        ?string $note = null,
        bool $correction = false,
    ): int {
        global $wpdb;
        $registration = $this->key();
        $online = in_array($kind, ['payment', 'refund'], true);
        $wpdb->insert($wpdb->prefix . 'kalaka_ledger_lines', [
            'event_id' => $this->event,
            'code' => $registration->code,
            'kind' => $kind,
            'payer' => 'participant',
            'amount' => $amount,
            'currency' => 'HUF',
            'source_type' => $kind === 'charge' ? 'fee_component' : 'wp_user',
            'source_event_id' => null,
            'source_key' => $kind === 'charge' ? 'fixed_price' : '1',
            'idempotency_key' => 'l' . ++$this->seq,
            'value_date' => $online || $day === null ? null : $this->midnightInBudapest($day),
            'paid_at' => $online ? '2026-11-29 10:00:00' : null,
            'recorded_at' => '2026-11-29 10:00:00',
            'method' => $method,
            'correction' => $correction ? 1 : 0,
        ]);
        $id = (int) $wpdb->insert_id;
        if ($note !== null) {
            $wpdb->insert($wpdb->prefix . 'kalaka_payment_notes', [
                'event_id' => $this->event,
                'line_id' => $id,
                'code' => $registration->code,
                'version' => 1,
                'note' => $note,
            ]);
        }

        return $id;
    }

    /** The UTC instant of 00:00 of a day in the site time zone, as it is stored. */
    private function midnightInBudapest(string $day): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($day . ' -1 day 23:00'));
    }

    private function render(string $inner, string $mail = 'payment_recorded', ?int $line = null): string
    {
        $body = $this->document($this->event, $inner);
        $this->switchOn($this->event, $mail, $body);

        return (new MailRenderer())->render($this->event, $mail, $this->key(), $line)->html;
    }

    private function keys(): string
    {
        return $this->paragraph(
            '<span data-kalaka-value="payment.direction">x</span> '
            . '<span data-kalaka-value="payment.amount">x</span> '
            . '<span data-kalaka-value="payment.method">x</span> '
            . '<span data-kalaka-value="payment.date">x</span>'
        );
    }

    public function testThePaymentKeysReadTheLineThatCausedTheMail(): void
    {
        $line = $this->line('manual_payment', '-15000', 'bank_transfer', '2026-11-29', 'TAB27 12A3');

        self::assertSame(
            'Befizetés 15 000 Ft Átutalás 2026. november 29.',
            self::text($this->render($this->keys(), 'payment_recorded', $line))
        );
    }

    public function testARefundReadsAsARefundAndAnOnlineLineAsOnline(): void
    {
        $refund = $this->line('manual_refund', '4000', 'cash', '2026-11-30');
        self::assertSame(
            'Visszafizetés 4 000 Ft Készpénz 2026. november 30.',
            self::text($this->render($this->keys(), 'payment_recorded', $refund))
        );

        $online = $this->line('payment', '-2000');
        $html = (new MailRenderer())->render($this->event, 'payment_recorded', $this->key(), $online)->html;
        self::assertSame('Befizetés 2 000 Ft Online 2026. november 29.', self::text($html));
    }

    public function testACorrectionLineUsedAsACauseReadsAsNone(): void
    {
        $correction = $this->line('manual_payment', '-1000', null, '2026-11-29', 'Javítás', true);
        $charge = $this->line('charge', '500');

        self::assertSame('', self::text($this->render($this->keys(), 'payment_recorded', $correction)));
        self::assertSame('', self::text($this->render($this->keys(), 'payment_recorded', $charge)));
        self::assertSame('', self::text($this->render($this->keys(), 'payment_recorded', 999999)));
    }

    public function testEveryOtherMailDeclaresThePaymentKeysAsNone(): void
    {
        $this->line('manual_payment', '-1000', 'cash', '2026-11-29');

        // A line given to another mail (a cause that is a ledger line) reads as none there as well.
        $line = $this->line('manual_payment', '-1000', 'cash', '2026-11-29');
        self::assertSame('', self::text($this->render($this->keys(), 'registered', $line)));
        self::assertSame('', self::text($this->render($this->keys(), 'confirmed', $line)));
        // The same line in its own mail reads.
        self::assertSame(
            'Befizetés 1 000 Ft Készpénz 2026. november 29.',
            self::text($this->render($this->keys(), 'payment_recorded', $line))
        );
    }

    public function testAnOnlineLineReadsOnlineAndItsPaidTimesDayInTheSiteZone(): void
    {
        // 23:30 UTC on 29 November is 00:30 on 30 November in Budapest.
        $line = $this->line('payment', '-2000');
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_ledger_lines', ['paid_at' => '2026-11-29 23:30:00'], ['id' => $line]);
        update_option('timezone_string', 'Europe/Budapest');

        self::assertSame(
            'Befizetés 2 000 Ft Online 2026. november 30.',
            self::text($this->render($this->keys(), 'payment_recorded', $line))
        );
    }

    public function testAChargeLineUsedAsACauseReadsAsNone(): void
    {
        $charge = $this->line('charge', '500');

        self::assertSame('', self::text($this->render($this->keys(), 'payment_recorded', $charge)));
    }

    public function testALineOfAnotherPayerReadsAsNone(): void
    {
        global $wpdb;
        $line = $this->line('manual_payment', '-1000', 'cash', '2026-11-29');
        $wpdb->update($wpdb->prefix . 'kalaka_ledger_lines', ['payer' => 'congregation'], ['id' => $line]);

        self::assertSame('', self::text($this->render($this->keys(), 'payment_recorded', $line)));
    }

    public function testAWaitlistedRegistrationsMailCarriesNoMoneyTableJustAsItsPageDoesNot(): void
    {
        global $wpdb;
        $this->key();
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'waitlisted'],
            ['event_id' => $this->event, 'code' => '12A3']
        );
        ObjectCache::clear();

        $html = $this->render('<!-- wp:kalaka/mail-money /-->', 'waitlisted');

        self::assertSame('', trim(self::text($html)));
        self::assertStringNotContainsString('<table', $html);
        // The page's own rule: no money table for a waitlisted registration.
        $view = (new \Kalaka\Portal\Api\StatusRead())->of($this->key());
        self::assertNotNull($view);
        self::assertFalse($view->showsMoney());
        self::assertStringNotContainsString('Pénzügyek', \Kalaka\Portal\StatusMarkup::status($view, false));
    }

    public function testAWithdrawnRegistrationsMailKeepsTheTableAsItsPageDoes(): void
    {
        global $wpdb;
        $this->line('manual_payment', '-8000', 'cash', '2026-11-29');
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'withdrawn'],
            ['event_id' => $this->event, 'code' => '12A3']
        );
        ObjectCache::clear();

        self::assertStringContainsString(
            'Befizetve összesen 8 000 Ft',
            self::text($this->render('<!-- wp:kalaka/mail-money /-->', 'confirmed'))
        );
    }

    public function testTheMoneyTableRendersInMailMarkupThroughTheSameReadAsThePage(): void
    {
        $this->line('manual_payment', '-8000', 'card_terminal', '2026-11-27');
        $long = 'közlemény helytelen volt: nem volt benne az azonosító';
        $this->line('manual_payment', '-15000', 'bank_transfer', '2026-11-28', $long);
        $this->line('manual_refund', '4000', 'bank_transfer', '2026-11-29');
        $this->line('manual_payment', '-1000', null, '2026-11-29', 'Javítás', true);

        $html = $this->render('<!-- wp:kalaka/mail-money /-->', 'confirmed');

        self::assertSame(
            'Részvételi díj + 60 000 Ft Fizetendő összesen 60 000 Ft'
            . ' Befizetés Bankkártya · 2026. 11. 27. + 8 000 Ft'
            . ' Befizetés Átutalás · 2026. 11. 28. · „' . $long . '” + 15 000 Ft'
            . ' Visszafizetés Átutalás · 2026. 11. 29. − 4 000 Ft'
            . ' Korrekció 2026. 11. 29. · „Javítás” + 1 000 Ft'
            . ' Befizetve összesen 20 000 Ft Még fizetendő 40 000 Ft',
            self::text($html)
        );
        // A mail client runs no popover and no media query: inline styles only, the whole note, no button.
        self::assertStringNotContainsString('popover', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('class=', $html);
        self::assertStringContainsString('style="', $html);
        $last = substr($html, (int) strrpos($html, '<tr>'));
        self::assertSame(3, substr_count($html, 'font-weight:bold'), 'only the last row is bold');
        self::assertSame(3, substr_count($last, 'font-weight:bold'));
    }

    public function testTheTableOfAPaidAndOverpaidMailEndsInTheRightLastRow(): void
    {
        $this->line('manual_payment', '-70000', 'cash', '2026-11-29');
        $html = $this->render('<!-- wp:kalaka/mail-money /-->', 'confirmed');

        self::assertStringContainsString('Túlfizetés 10 000 Ft', self::text($html));
        self::assertStringNotContainsString('Még fizetendő', $html);
    }

    public function testTheTransferBlockRendersWhileSomethingIsDueAndNothingAfter(): void
    {
        $due = $this->render('<!-- wp:kalaka/mail-transfer /-->', 'confirmed');
        self::assertSame(
            'Az átutaláshoz ezeket az adatokat add meg: Kedvezményezett Példa Egyesület'
            . ' Számlaszám 11111111-22222222-33333333 Összeg 60 000 Ft Közlemény TAB27 12A3',
            self::text($due)
        );

        $this->line('manual_payment', '-60000', 'bank_transfer', '2026-11-29');
        self::assertSame('', trim(self::text($this->render('<!-- wp:kalaka/mail-transfer /-->', 'confirmed'))));
    }

    public function testTheBlocksRenderNothingOutsideAMail(): void
    {
        $this->line('manual_payment', '-1000', 'cash', '2026-11-29');

        self::assertSame('', trim(do_blocks('<!-- wp:kalaka/mail-money /--><!-- wp:kalaka/mail-transfer /-->')));
    }

    public function testThePaymentMailIsQueuedByTheLineAndSentWithItsKeysAndTheTable(): void
    {
        $body = $this->document($this->event, $this->keys() . "\n<!-- wp:kalaka/mail-money /-->", 'Befizetés');
        $this->switchOn($this->event, 'payment_recorded', $body);
        $sent = [];
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', static function (mixed $result, array $atts) use (&$sent): bool {
            $sent[] = $atts;

            return true;
        }, 10, 2);

        $key = $this->key();
        $queued = $this->runner->run(
            new AdminProbe(function (array $input, Context $context) use ($key): bool {
                $this->lockRegistrations($context, $key);
                $recorded = (new Ledger())->record(
                    new NewLine(
                        $key,
                        LineKind::ManualPayment,
                        'participant',
                        Money::of('-12000', Currency::HUF),
                        new Subject('wp_user', null, '1'),
                        'manual:e2e',
                        '2026-11-29',
                        null,
                        \Kalaka\Ledger\Api\PaymentMethod::Cash,
                        false,
                        'Teszt'
                    ),
                    $context
                );

                return (new ActionMails())->lineRecorded($context, $recorded);
            }),
            [],
            $this->admin
        );
        self::assertTrue($queued);

        (new Sender(fn () => $this->runner, new ActionMailComposer()))->send();

        self::assertCount(1, $sent);
        self::assertSame('teszt@example.test', $sent[0]['to']);
        self::assertSame('Befizetés', $sent[0]['subject']);
        $text = self::text((string) $sent[0]['message']);
        self::assertStringContainsString('Befizetés 12 000 Ft Készpénz 2026. november 29.', $text);
        self::assertStringContainsString('Befizetés Készpénz · 2026. 11. 29. · „Teszt” + 12 000 Ft', $text);
        self::assertStringContainsString('Még fizetendő 48 000 Ft', $text);
        self::assertSame('sent', $this->deliveries()[0]['status']);
    }

    /** A paragraph or an HTML block that holds only the typed key. */
    private static function alone(string $typed, string $as = 'paragraph'): string
    {
        return $as === 'html'
            ? "<!-- wp:html -->\n" . $typed . "\n<!-- /wp:html -->"
            : "<!-- wp:paragraph -->\n<p>" . $typed . "</p>\n<!-- /wp:paragraph -->";
    }

    public function testTheTypedKeysRenderExactlyWhatTheirBlocksRender(): void
    {
        $this->line('manual_payment', '-8000', 'cash', '2026-11-27', 'Teszt');
        $money = $this->render('<!-- wp:kalaka/mail-money /-->', 'confirmed');
        $transfer = $this->render('<!-- wp:kalaka/mail-transfer /-->', 'confirmed');
        self::assertStringContainsString('<table', $money);
        self::assertStringContainsString('<table', $transfer);

        foreach (['paragraph', 'html'] as $as) {
            self::assertSame($money, $this->render(self::alone('{{money_table}}', $as), 'confirmed'), $as);
            self::assertSame($money, $this->render(self::alone('{{ money_table }}', $as), 'confirmed'), $as);
            self::assertSame($transfer, $this->render(self::alone('{{transfer_details}}', $as), 'confirmed'), $as);
        }
        // Both in one body, beside other text: the same as the two blocks beside it.
        $typed = $this->render(
            $this->paragraph('Szia') . "\n" . self::alone('{{money_table}}') . "\n"
            . self::alone('{{transfer_details}}'),
            'confirmed'
        );
        $blocks = $this->render(
            $this->paragraph('Szia') . "\n<!-- wp:kalaka/mail-money /-->\n<!-- wp:kalaka/mail-transfer /-->",
            'confirmed'
        );
        self::assertSame(self::text($blocks), self::text($typed));
        self::assertStringNotContainsString('{{', $typed);
    }

    public function testTheTypedTransferKeyRendersNothingWhileNothingIsDue(): void
    {
        self::assertStringContainsString(
            'Összeg 60 000 Ft',
            self::text($this->render(self::alone('{{transfer_details}}'), 'confirmed'))
        );

        $this->line('manual_payment', '-60000', 'bank_transfer', '2026-11-29');
        $html = $this->render(self::alone('{{transfer_details}}'), 'confirmed');

        self::assertSame('', trim(self::text($html)));
        self::assertStringNotContainsString('<table', $html);
    }

    public function testTheTypedMoneyKeyFollowsThePageAndRendersNothingForAWaitlistedRegistration(): void
    {
        global $wpdb;
        $this->key();
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'waitlisted'],
            ['event_id' => $this->event, 'code' => '12A3']
        );
        ObjectCache::clear();

        $html = $this->render(self::alone('{{money_table}}'), 'waitlisted');

        self::assertSame('', trim(self::text($html)));
        self::assertStringNotContainsString('<table', $html);
    }

    public function testATypedKeyInsideAConditionsBranchRenders(): void
    {
        $empty = ['match' => 'all', 'items' => [['key' => 'registration.code', 'operator' => 'empty']]];
        $inner = $this->branch('if', $this->paragraph('Nincs kód'), $empty) . "\n"
            . $this->branch('else', self::alone('{{money_table}}'));

        $html = $this->render($inner, 'confirmed');

        self::assertStringContainsString('Fizetendő összesen 60 000 Ft', self::text($html));
        self::assertStringNotContainsString('Nincs kód', $html);
    }

    public function testAManualMailRendersTheTypedKeysToo(): void
    {
        $body = $this->document(
            $this->event,
            self::alone('{{money_table}}') . "\n" . self::alone('{{transfer_details}}')
        );

        $html = (new MailRenderer())->renderBody($this->event, $body, $this->key())->html;

        self::assertSame(
            self::text($this->render('<!-- wp:kalaka/mail-money /--><!-- wp:kalaka/mail-transfer /-->', 'confirmed')),
            self::text($html)
        );
    }

    /** @return array<string, array{string}> */
    public static function misplacedKeys(): array
    {
        return [
            'inline in a paragraph' => [
                '<!-- wp:paragraph --><p>Tartozásod: {{money_table}}</p><!-- /wp:paragraph -->',
            ],
            'in a link' => [
                '<!-- wp:paragraph --><p><a href="{{transfer_details}}">x</a></p><!-- /wp:paragraph -->',
            ],
            'in a heading' => [
                '<!-- wp:heading --><h2>{{money_table}}</h2><!-- /wp:heading -->',
            ],
        ];
    }

    /** @dataProvider misplacedKeys */
    public function testATypedKeyAnywhereButAloneIsAnUnknownPlaceholderAtSaveAndFailsTheMailAtSend(string $inner): void
    {
        $unknown = BodyCheck::unknown($inner, $this->event);
        self::assertNotNull($unknown);
        self::assertStringContainsString('{{', $unknown->keys[0]);
        self::assertNotNull(BodyCheck::refusal(0, $inner, $this->event));

        $this->switchOn($this->event, 'confirmed', $this->brokenDocument($this->event, $inner));
        try {
            (new MailRenderer())->render($this->event, 'confirmed', $this->key());
            self::fail('Expected a failure.');
        } catch (BodyUnusable $e) {
            self::assertStringStartsWith('unknown_placeholder {{', $e->problem);
        }
    }

    public function testAKeyStandingAloneIsNotRefusedAtSaveAndAnyOtherBodyIsLeftAsTyped(): void
    {
        self::assertNull(BodyCheck::unknown(self::alone('{{money_table}}'), $this->event));
        self::assertNull(BodyCheck::problem(self::alone('{{transfer_details}}', 'html'), $this->event));
        self::assertNull(BodyCheck::refusal(0, self::alone('{{ money_table }}'), $this->event));
        self::assertSame('<p>{{event.name}}</p>', MailBlocks::expand('<p>{{event.name}}</p>'));
        self::assertSame(
            self::alone('{{event.name}}'),
            MailBlocks::expand(self::alone('{{event.name}}'))
        );
    }

    /** The text of rendered HTML: cells and paragraphs apart, whitespace and no-break spaces collapsed. */
    private static function text(string $html): string
    {
        $spaced = (string) preg_replace('/<\/(p|th|td|tr)>/', '$0 ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', wp_strip_all_tags($spaced))));
    }
}
