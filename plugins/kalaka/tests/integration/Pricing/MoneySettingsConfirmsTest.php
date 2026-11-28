<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\ReferenceCode;

/**
 * money.md, "Money settings apply": the preview says which registrations would be confirmed after the apply
 * and how many "confirmed" mails would go (the owner's ruling Q8), by a dry run of the confirmation rule over
 * the proposed composition. It writes nothing and costs a fixed number of statements per registration.
 */
final class MoneySettingsConfirmsTest extends ConfirmationTestCase
{
    public function testALowerPriceThatMakesAPaidRegistrationConfirmCountsOneAndOneMailWhenTheMailIsOn(): void
    {
        $this->setPrice('40000');
        $this->switchConfirmedMail(true);
        $paid = $this->register('00A0');
        $this->payOn($paid, '30000', '2026-11-20');
        $unpaid = $this->register('00A2');
        $done = $this->register('00A5');
        $this->payOn($done, '40000', '2026-11-20');
        $this->evaluate($done);
        $waiting = $this->register('00A8', RegistrationStatus::Waitlisted);

        self::assertSame('confirmed', $this->confirmationState($done));
        $rows = $this->preview('30000');

        self::assertSame(
            ['00A0' => [true, true], '00A2' => [false, false], '00A5' => [false, false], '00A8' => [false, false]],
            $this->flags($rows)
        );
        self::assertSame(1, $this->counted($rows, 'confirms'));
        self::assertSame(1, $this->counted($rows, 'mailed'));
        self::assertNotNull($unpaid);
        self::assertNotNull($waiting);
    }

    /** @return array<string, array{bool}> */
    public static function mailStates(): array
    {
        return ['the confirmed mail on' => [true], 'the confirmed mail off' => [false]];
    }

    /**
     * The preview's counts are what the apply then does: the registrations it flags are exactly those that get a
     * new `confirmed` record, and its mailed count is the number of "confirmed" deliveries the apply queues.
     *
     * @dataProvider mailStates
     */
    public function testThePreviewCountsEqualWhatTheApplyThenConfirmsAndMails(bool $mailOn): void
    {
        $this->setPrice('40000');
        if ($mailOn) {
            $this->confirmedMailOn();
        }
        $keys = [];
        $keys['00A0'] = $this->register('00A0');
        $this->payOn($keys['00A0'], '30000', '2026-11-20');
        $keys['00A2'] = $this->register('00A2');
        $keys['00B0'] = $this->register('00B0');
        $this->payOn($keys['00B0'], '35000', '2026-11-20');
        $keys['00A5'] = $this->register('00A5');
        $this->payOn($keys['00A5'], '40000', '2026-11-20');
        $this->evaluate($keys['00A5']);
        $keys['00A8'] = $this->register('00A8', RegistrationStatus::Waitlisted);
        $recordsBefore = array_map(fn ($key): int => count($this->confirmedRecordIds($key)), $keys);
        $mailsBefore = count($this->deliveryRows());

        $rows = $this->preview('30000');
        $this->settings->apply($this->event, new ProposedMoneySettings($this->huf('30000')), new CliActor(null));

        $applied = array_keys(array_filter(
            $keys,
            fn ($key, string $code): bool => count($this->confirmedRecordIds($key)) > $recordsBefore[$code],
            ARRAY_FILTER_USE_BOTH
        ));
        $previewed = array_keys(array_filter($this->flags($rows), static fn (array $flag): bool => $flag[0]));
        sort($applied);
        sort($previewed);
        self::assertSame(['00A0', '00B0'], $applied, 'the scenario confirms two registrations');
        self::assertSame($applied, $previewed, 'the preview flags exactly those the apply confirmed');
        self::assertSame(
            count($this->deliveryRows()) - $mailsBefore,
            $this->counted($rows, 'mailed'),
            'and its mailed count is what the apply queued'
        );
        self::assertSame($mailOn ? 2 : 0, $this->counted($rows, 'mailed'));
    }

    public function testTheConfirmationCountsWithoutAMailWhenTheMailIsOffOrUnset(): void
    {
        $this->setPrice('40000');
        $paid = $this->register('00A0');
        $this->payOn($paid, '30000', '2026-11-20');

        $unset = $this->preview('30000');
        $this->switchConfirmedMail(false);
        $off = $this->preview('30000');

        foreach ([$unset, $off] as $rows) {
            self::assertSame(1, $this->counted($rows, 'confirms'));
            self::assertSame(0, $this->counted($rows, 'mailed'));
        }
    }

    public function testAHigherPriceConfirmsNobodyAndAnAlreadyConfirmedOneIsNeverCounted(): void
    {
        $this->setPrice('40000');
        $this->switchConfirmedMail(true);
        $paid = $this->register('00A0');
        $this->payOn($paid, '40000', '2026-11-20');
        $this->evaluate($paid);
        $part = $this->register('00A2');
        $this->payOn($part, '30000', '2026-11-20');

        $rows = $this->preview('50000');

        self::assertSame(0, $this->counted($rows, 'confirms'));
        self::assertSame(0, $this->counted($rows, 'mailed'));
    }

    public function testAFreeEventAfterTheChangeConfirmsEveryoneNotConfirmedYet(): void
    {
        $this->setPrice('40000');
        $this->switchConfirmedMail(true);
        $this->register('00A0');
        $this->register('00A2');

        $rows = $this->preview('0');

        self::assertSame(2, $this->counted($rows, 'confirms'));
        self::assertSame(2, $this->counted($rows, 'mailed'));
    }

    public function testThePreviewWritesNothing(): void
    {
        $this->setPrice('40000');
        $this->switchConfirmedMail(true);
        $paid = $this->register('00A0');
        $this->payOn($paid, '30000', '2026-11-20');

        $writes = [];
        $capture = static function (string $query) use (&$writes): string {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $query) === 1) {
                $writes[] = $query;
            }

            return $query;
        };
        add_filter('query', $capture);
        try {
            $rows = $this->preview('30000');
        } finally {
            remove_filter('query', $capture);
        }

        self::assertSame(1, $this->counted($rows, 'confirms'));
        self::assertSame([], $writes);
        self::assertSame([], $this->confirmations($paid));
        self::assertSame('unconfirmed', $this->confirmationState($paid));
    }

    public function testTheCostGrowsByTheSameStatementsForEveryRegistration(): void
    {
        $this->setPrice('40000');
        $this->switchConfirmedMail(true);
        $codes = ReferenceCode::fourCharacterCodes();
        $measure = function (int $count) use ($codes): int {
            for ($i = 0; $i < $count; ++$i) {
                $key = $this->register($codes[$this->next++]);
                $this->payOn($key, '30000', '2026-11-20');
            }
            global $wpdb;
            $before = $wpdb->num_queries;
            $this->preview('30000');

            return $wpdb->num_queries - $before;
        };

        $two = $measure(2);
        $four = $measure(2);
        $six = $measure(2);

        self::assertSame($four - $two, $six - $four, 'A fixed number of statements per registration.');
    }

    private int $next = 10;

    /** @return list<PreviewRow> */
    private function preview(string $price): array
    {
        $proposed = new ProposedMoneySettings($this->huf($price));

        return $this->settings->preview($this->event, $proposed, new CliActor(null));
    }

    /**
     * @param list<PreviewRow> $rows
     * @return array<string, array{bool, bool}>
     */
    private function flags(array $rows): array
    {
        $flags = [];
        foreach ($rows as $row) {
            $flags[$row->registration->code] = [$row->confirms, $row->mailed];
        }

        return $flags;
    }

    /** @param list<PreviewRow> $rows */
    private function counted(array $rows, string $what): int
    {
        return count(array_filter(
            $rows,
            static fn (PreviewRow $row): bool => $what === 'confirms' ? $row->confirms : $row->mailed
        ));
    }

    /** Switches the event's "confirmed" action mail on or off, as the catalogue stores it. */
    private function switchConfirmedMail(bool $on): void
    {
        global $wpdb;

        $wpdb->replace(
            $wpdb->prefix . 'kalaka_action_mails',
            ['event_id' => $this->event, 'mail' => 'confirmed', 'version' => 1, 'enabled' => $on ? 1 : 0]
        );
    }
}
