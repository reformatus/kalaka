<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Ledger\Api\ChargeLine;
use Kalaka\Events\Api\Phase;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\LineLabels;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The status block and the transfer block over every state of the frozen spec (2026-11-13). */
final class StatusBlockTest extends PortalTestCase
{
    private const NOTE = 'A befizetéseket kézzel rögzítjük, ezért néhány napba is telhet, mire itt megjelennek.';

    public function testWithoutAnActiveRegistrationTheBlockAsksForTheMailedLinkAndTheTransferBlockIsEmpty(): void
    {
        self::assertSame(
            '<section class="kalaka-status"><h2>Jelentkezésem állapota</h2><p>A jelentkezésed állapotának'
            . ' megnézéséhez nyisd meg a jelentkezés után kapott e-mailben lévő linket.</p></section>',
            $this->statusBlock()
        );
        self::assertSame('', $this->transferBlock());
    }

    public function testAfterABadLinkTheBlockSaysSoAndTheTransferBlockIsEmpty(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->activate($key, true);

        self::assertSame(
            '<section class="kalaka-status"><h2>Jelentkezésem állapota</h2><p>Ez a link nem érvényes. Nyisd meg a'
            . ' legutóbb kapott e-mailben lévő linket.</p></section>',
            $this->statusBlock()
        );
        self::assertSame('', $this->transferBlock());
    }

    public function testAwaitingPaymentShowsTheBoldAmountTheDeadlineAndTheTransferDetails(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->charge($key, '3000');
        $this->pay($key, '20000');
        $this->activate($key);

        $html = $this->statusBlock();
        self::assertStringContainsString(
            '<p>Azonosító: <span class="kalaka-code has-medium-font-size">12A3</span></p>'
            . '<p><strong>Fizetésre vár.</strong></p><h3>Pénzügyek</h3><table class="kalaka-money"><tbody>',
            $html
        );
        self::assertStringNotContainsString('wp-block-group', $html);
        self::assertStringContainsString(
            '<tr class="kalaka-rule kalaka-due"><th scope="row">Még fizetendő</th><td class="kalaka-sign"></td>'
            . "<td class=\"kalaka-amount\"><strong>31\u{00A0}000\u{00A0}Ft</strong></td></tr>",
            $html
        );
        // Registered 2026-11-30 in Budapest + 7 days.
        self::assertStringContainsString('<p class="kalaka-deadline">Fizetési határidő: 2026. december 7.</p>', $html);
        self::assertSame(
            'Jelentkezésem állapota Azonosító: 12A3 Fizetésre vár. Pénzügyek Fizetendő összesen 51 000 Ft'
            . ' Befizetve 20 000 Ft Még fizetendő 31 000 Ft Fizetési határidő: 2026. december 7. ' . self::NOTE,
            self::text($html)
        );
        self::assertSame(1, substr_count($html, '<strong>') - 1, 'only the state word and the amount are bold');

        self::assertSame(
            '<section class="kalaka-transfer"><p>Az átutaláshoz ezeket az adatokat add meg:</p>'
            . '<table class="kalaka-transfer-details"><tbody>'
            . '<tr><th scope="row">Kedvezményezett</th><td>Példa Egyesület</td></tr>'
            . '<tr><th scope="row">Számlaszám</th><td>11111111-22222222-33333333</td></tr>'
            . "<tr><th scope=\"row\">Összeg</th><td>31\u{00A0}000\u{00A0}Ft</td></tr>"
            . '<tr><th scope="row">Közlemény</th><td>TAB27 12A3</td></tr>'
            . '</tbody></table></section>',
            $this->transferBlock()
        );
    }

    public function testOverdueShowsThePinkBoxAndThePastDeadline(): void
    {
        $key = $this->stored('12A3', at: '2026-11-20 10:00:00');
        $this->charge($key, '48000');
        $this->activate($key);

        $html = $this->statusBlock();
        self::assertStringContainsString(
            '<div class="wp-block-group has-background has-pale-pink-background-color has-text-color has-black-color">'
            . '<p><strong>Lejárt a fizetési határidő.</strong></p></div>',
            $html
        );
        self::assertStringContainsString('Fizetési határidő: 2026. november 27.', $html);
        self::assertStringContainsString('<strong>48', $html);
        self::assertNotSame('', $this->transferBlock());
        self::assertTrue((new StatusRead())->forActive()?->payment?->overdue);
    }

    public function testTheDueDayEndsAtMidnightInTheSiteTimeZone(): void
    {
        // Registered 2026-11-22 23:30 UTC = 2026-11-23 00:30 in Budapest: due 2026-11-30, overdue only from
        // 2026-12-01 00:00 Budapest; the clock is 2026-11-30 09:00 Budapest.
        $key = $this->stored('12A3', at: '2026-11-22 23:30:00');
        $this->charge($key, '48000');
        $this->activate($key);

        self::assertStringContainsString('Fizetési határidő: 2026. november 30.', $this->statusBlock());
        self::assertStringContainsString('<strong>Fizetésre vár.</strong>', $this->statusBlock());
        self::assertSame(
            '2026-11-30 23:00:00',
            (new StatusRead())->forActive()?->payment?->paymentDue?->format('Y-m-d H:i:s')
        );
    }

    public function testTheClockRunsFromTheTimeItLastBecameRegisteredNotFromItsCreation(): void
    {
        // Created waitlisted on 2026-11-01, admitted 2026-11-28 10:00 UTC: due 2026-12-05.
        $key = $this->stored('12A3', RegistrationStatus::Waitlisted, '2026-11-01 10:00:00');
        $this->setStatus($key, RegistrationStatus::Registered, '2026-11-28 10:00:00');
        $this->charge($key, '48000');
        $this->activate($key);

        self::assertStringContainsString('Fizetési határidő: 2026. december 5.', $this->statusBlock());
        self::assertStringContainsString('<strong>Fizetésre vár.</strong>', $this->statusBlock());
    }

    public function testHeldShowsThePinkNoticeThePlainAmountNoDeadlineAndNoTransfer(): void
    {
        $key = $this->stored('12A3', at: '2026-11-20 10:00:00');
        $this->charge($key, '48000');
        $this->decide($key, StatusRead::PAYMENT_HOLD, 'held');
        $this->activate($key);

        $html = $this->statusBlock();
        self::assertStringContainsString(
            'has-pale-pink-background-color has-text-color has-black-color"><p><strong>Elbírálás alatt.</strong>'
            . ' Kérjük, ne fizess, amíg e-mailben meg nem írjuk a végleges összeget.</p></div>',
            $html
        );
        self::assertStringNotContainsString('Lejárt', $html);
        self::assertStringContainsString(
            '<tr class="kalaka-rule"><th scope="row">Még fizetendő</th><td class="kalaka-sign"></td>'
            . "<td class=\"kalaka-amount\">48\u{00A0}000\u{00A0}Ft</td></tr>",
            $html
        );
        self::assertStringNotContainsString('Fizetési határidő', $html);
        self::assertStringContainsString(self::NOTE, $html);
        self::assertSame('', $this->transferBlock());
    }

    public function testConfirmedShowsTheGreenBoxAZeroAmountThatIsNotBoldAndNoTransfer(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->pay($key, '48000');
        $this->decide($key, StatusRead::CONFIRMATION, 'confirmed');
        $this->activate($key);

        $html = $this->statusBlock();
        self::assertStringContainsString(
            '<div class="wp-block-group has-background has-light-green-cyan-background-color has-text-color'
            . ' has-black-color"><p><strong>Visszaigazolva.</strong></p></div><h3>Pénzügyek</h3>',
            $html
        );
        self::assertStringContainsString(
            '<tr class="kalaka-rule"><th scope="row">Még fizetendő</th><td class="kalaka-sign"></td>'
            . "<td class=\"kalaka-amount\">0\u{00A0}Ft</td></tr>",
            $html
        );
        self::assertStringNotContainsString('Fizetési határidő', $html);
        self::assertSame('', $this->transferBlock());
        self::assertSame(Phase::Confirmed, (new StatusRead())->forActive()?->phase);
    }

    public function testOverpaidShowsTheOverpaymentAsAPositiveAmountAndNeverANegativeAmountToPay(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->pay($key, '50000');
        $this->decide($key, StatusRead::CONFIRMATION, 'confirmed');
        $this->activate($key);

        $html = $this->statusBlock();
        self::assertStringContainsString('<strong>Visszaigazolva.</strong></p></div>', $html);
        self::assertStringContainsString(
            '<tr class="kalaka-rule"><th scope="row">Túlfizetés</th><td class="kalaka-sign"></td>'
            . "<td class=\"kalaka-amount\">2\u{00A0}000\u{00A0}Ft</td></tr>",
            $html
        );
        self::assertStringNotContainsString('Még fizetendő', $html);
        self::assertStringNotContainsString("\u{2212}", $html);
        self::assertStringNotContainsString('-2', $html);
        self::assertStringNotContainsString('SQ-8', $html);
        self::assertSame('', $this->transferBlock());
    }

    public function testConfirmedWithALaterChargeShowsTheAmountDueTheDeadlineAndTheTransfer(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->pay($key, '48000');
        $this->decide($key, StatusRead::CONFIRMATION, 'confirmed');
        $this->charge($key, '3000');
        $this->activate($key);

        $html = $this->statusBlock();
        self::assertStringContainsString('<strong>Visszaigazolva.</strong>', $html);
        self::assertStringContainsString("<strong>3\u{00A0}000\u{00A0}Ft</strong>", $html);
        self::assertStringContainsString('Fizetési határidő: 2026. december 7.', $html);
        self::assertStringContainsString("<td>3\u{00A0}000\u{00A0}Ft</td>", $this->transferBlock());
    }

    public function testWaitlistedShowsTheGreyBoxAndNoMoney(): void
    {
        $key = $this->stored('12A3', RegistrationStatus::Waitlisted);
        $this->activate($key);

        self::assertSame(
            '<section class="kalaka-status"><h2>Jelentkezésem állapota</h2>'
            . '<p>Azonosító: <span class="kalaka-code has-medium-font-size">12A3</span></p>'
            . '<div class="wp-block-group has-background has-very-light-gray-background-color has-text-color'
            . ' has-black-color"><p><strong>Várólistán vagy.</strong> Amíg nem kerülsz be, nem kell fizetned.</p>'
            . '</div></section>',
            $this->statusBlock()
        );
        self::assertSame('', $this->transferBlock());
    }

    public function testWithdrawnAndRemovedShowOneSentenceAndNoMoney(): void
    {
        $withdrawn = $this->stored('12A3', RegistrationStatus::Withdrawn);
        $removed = $this->stored('00A0', RegistrationStatus::Removed);
        $this->charge($withdrawn, '48000');

        $this->activate($withdrawn);
        self::assertStringEndsWith(
            '<p><strong>Lemondtad ezt a jelentkezést.</strong></p></section>',
            $this->statusBlock()
        );
        self::assertStringNotContainsString('Pénzügyek', $this->statusBlock());
        self::assertSame('', $this->transferBlock());

        $this->activate($removed);
        self::assertStringEndsWith(
            '<p><strong>A szervezők törölték ezt a jelentkezést.</strong></p></section>',
            $this->statusBlock()
        );
        self::assertSame('', $this->transferBlock());
    }

    public function testAClosedOrArchivedEventShowsNothingOfTheRegistration(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->activate($key);

        foreach (['unpublished', 'archived', 'draft'] as $state) {
            $this->event(['state' => $state]);
            self::assertSame(
                '<section class="kalaka-status"><h2>Jelentkezésem állapota</h2>'
                . '<p>A jelentkezésed állapota jelenleg nem érhető el.</p></section>',
                $this->statusBlock(),
                $state
            );
            self::assertStringNotContainsString('12A3', $this->statusBlock(), $state);
            self::assertSame('', $this->transferBlock(), $state);
        }
    }

    public function testTheTransferBlockNeedsTheBankDetailsAndAnEmptyPrefixLeavesTheBareCode(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000.40');
        $this->activate($key);

        $this->event(['transfer_reference_prefix' => null]);
        self::assertStringContainsString('<td>12A3</td>', $this->transferBlock());
        // Rounded up to whole forints, as every payment request.
        self::assertStringContainsString("<td>48\u{00A0}001\u{00A0}Ft</td>", $this->transferBlock());
        self::assertStringContainsString("<strong>48\u{00A0}001\u{00A0}Ft</strong>", $this->statusBlock());

        $this->event(['transfer_account' => null]);
        self::assertSame('', $this->transferBlock());
    }

    public function testTheTotalsAreTheParticipantsSideOnly(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->charge($key, '-10000');
        $this->charge($key, '10000', 'congregation');
        $this->activate($key);

        $view = (new StatusRead())->forActive();
        self::assertSame('38000.00000000', $view?->payment?->participant->balance->amount());
        self::assertSame(['congregation'], array_keys($view?->payment?->otherPayers ?? []));
        self::assertStringContainsString("<td>38\u{00A0}000\u{00A0}Ft</td>", $this->transferBlock());
    }

    public function testWithoutLabelsTheTableShowsTheTotalsOnlyAndWithLabelsOneRowPerLine(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '48000');
        $this->charge($key, '-10000');
        $this->charge($key, '3000');
        $this->activate($key);

        self::assertStringContainsString(
            '<table class="kalaka-money"><tbody><tr><th scope="row">Fizetendő összesen</th>',
            $this->statusBlock()
        );

        $labels = new class extends LineLabels {
            public function labelOf(ChargeLine $line): ?string
            {
                return 'Tétel ' . $line->id;
            }
        };
        $html = $this->statusBlock(new StatusRead(labels: $labels));
        preg_match_all('/<tr[^>]*><th scope="row">([^<]*)<\/th><td class="kalaka-sign">([^<]*)<\/td>/u', $html, $rows);
        self::assertSame(['', "\u{2212}", '+', '', '', ''], $rows[2]);
        self::assertSame(['Fizetendő összesen', 'Befizetve', 'Még fizetendő'], array_slice($rows[1], 3));
        self::assertStringContainsString("<td class=\"kalaka-amount\">10\u{00A0}000\u{00A0}Ft</td>", $html);
    }
}
