<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ApplyReport;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\FixedPriceRepository;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The walkthrough's C3 and C6: a money settings change through preview, apply and the report. */
final class MoneySettingsTest extends PricingTestCase
{
    public function testThePreviewShowsOldAndNewOwedAndWritesNothing(): void
    {
        $this->setPrice('40000');
        $csilla = $this->register('00A0');
        $this->pay($csilla, '40000');
        $daniel = $this->register('00A2');
        $dora = $this->register('00A5');
        $this->setStatus($dora, RegistrationStatus::Withdrawn);
        $bence = $this->register('00A8', RegistrationStatus::Waitlisted);
        $lines = $this->lineCount();

        $rows = $this->preview('45000');

        self::assertSame(
            ['00A0' => true, '00A2' => true, '00A8' => false],
            array_combine(
                array_map(static fn (PreviewRow $row): string => $row->registration->code, $rows),
                array_map(static fn (PreviewRow $row): bool => $row->changes(), $rows)
            )
        );
        self::assertSame('40000', self::plain($rows[0]->before[Payer::PARTICIPANT]->amount()));
        self::assertSame('45000', self::plain($rows[0]->after[Payer::PARTICIPANT]->amount()));
        self::assertSame([], $rows[2]->before);
        self::assertSame($lines, $this->lineCount());
        $stored = (new FixedPriceRepository())->find($this->event);
        self::assertSame('40000', self::plain($stored?->amount->amount() ?? ''));
        self::assertSame([], $this->charges($bence));
        self::assertCount(1, $this->charges($daniel));
    }

    public function testTheApplySavesThenConvergesEachOpenRegistrationAndLeavesClosedOnesAlone(): void
    {
        $this->setPrice('40000');
        $csilla = $this->register('00A0');
        $this->pay($csilla, '40000');
        $daniel = $this->register('00A2');
        $dora = $this->register('00A5');
        $this->setStatus($dora, RegistrationStatus::Withdrawn);

        $report = $this->apply('45000');

        self::assertCount(2, $report->done());
        self::assertSame([], $report->failed());
        self::assertSame('fixed_price participant 5000 charge:fixed_price:participant:2', $this->charges($csilla)[1]);
        self::assertSame('5000', $this->balance($csilla));
        self::assertSame('45000', $this->balance($daniel));
        self::assertCount(2, $this->charges($dora));

        $lines = $this->lineCount();
        $this->apply('45000');
        self::assertSame($lines, $this->lineCount());
    }

    public function testALoweredPriceShowsAsAnOverpayment(): void
    {
        $this->setPrice('40000');
        $csilla = $this->register('00A0');
        $this->pay($csilla, '40000');

        $this->setPrice('35000');

        self::assertSame('-5000', $this->balance($csilla));
    }

    public function testOneFailingRegistrationIsReportedAndConvergesOnRetry(): void
    {
        $flaky = new FailingComponent('00A2');
        $this->useComponents($flaky);
        $this->setPrice('40000');
        $csilla = $this->register('00A0');
        $daniel = $this->register('00A2');

        $flaky->failing = true;
        $preview = $this->preview('45000');
        self::assertNull($preview[0]->problem);
        self::assertNotNull($preview[1]->problem);

        $report = $this->apply('45000');

        self::assertCount(1, $report->done());
        self::assertCount(1, $report->failed());
        self::assertSame('00A2', $report->failed()[0]->registration->code);
        self::assertSame('45000', $this->balance($csilla));
        self::assertSame('40000', $this->balance($daniel));

        $flaky->failing = false;
        $retried = $this->settings->retry($daniel, new CliActor(null));

        self::assertTrue($retried->isDone());
        self::assertSame('45000', $this->balance($daniel));
    }

    public function testASkippedRegistrationConvergesOnItsNextEvaluation(): void
    {
        $flaky = new FailingComponent('00A2');
        $this->useComponents($flaky);
        $this->setPrice('40000');
        $daniel = $this->register('00A2');

        $flaky->failing = true;
        $report = $this->apply('45000');
        self::assertCount(1, $report->failed());

        $flaky->failing = false;
        $this->pay($daniel, '10000');

        self::assertSame('35000', $this->balance($daniel));
    }

    public function testARegistrationSubmittedAfterThePreviewIsComputedAtApply(): void
    {
        $this->setPrice('40000');
        $this->preview('45000');
        $emil = $this->register('00A0');
        self::assertSame('40000', $this->balance($emil));

        $report = $this->apply('45000');

        self::assertCount(1, $report->done());
        self::assertSame('45000', $this->balance($emil));
    }

    public function testTheFixedPriceMustBeInTheEventsCurrencyAndItsEventMustExist(): void
    {
        try {
            $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '40000', 'currency' => 'EUR']);
            self::fail('A price in another currency was saved.');
        } catch (InvalidInput) {
        }
        try {
            $this->exec(
                new SetFixedPrice(),
                ['event_id' => $this->event + 1000, 'amount' => '40000', 'currency' => 'HUF']
            );
            self::fail('A price for a missing event was saved.');
        } catch (NotFound) {
        }

        self::assertNull((new FixedPriceRepository())->find($this->event));
    }

    // TODO(S20): when confirmation lands, add the owner's rule (2026-11-14) as a test here: a confirmed
    // registration stays confirmed after an apply that raises the price; the apply never unconfirms.

    private function apply(string $price): ApplyReport
    {
        $proposed = new ProposedMoneySettings($this->huf($price));

        return $this->settings->apply($this->event, $proposed, new CliActor(null));
    }

    /** @return list<PreviewRow> */
    private function preview(string $price): array
    {
        return $this->settings->preview($this->event, new ProposedMoneySettings($this->huf($price)));
    }

    /** @param array<string, mixed> $input */
    private function exec(SetFixedPrice $useCase, array $input): mixed
    {
        return $this->runner->run($useCase, $input, new CliActor(null));
    }

    private function lineCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_ledger_lines");
    }

    private static function plain(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
