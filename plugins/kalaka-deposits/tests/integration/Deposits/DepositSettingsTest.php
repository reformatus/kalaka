<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Deposits;

use Kalaka\Deposits\Api\Deposits;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\Cli\MoneySettingsCommand;
use Kalaka\Pricing\Cli\SettingsDocument;
use Kalaka\Tests\Integration\Pricing\PricingTestCase;

/**
 * The deposit in the money settings: `wp kalaka money-settings show|preview|apply` carries it under the
 * component `deposit`, the apply saves it through `kalaka/set-deposit`, and it writes no fee line.
 */
final class DepositSettingsTest extends PricingTestCase
{
    /** @var list<string> */
    private array $files = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once dirname(__DIR__, 3) . '/kalaka/tests/integration/Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        array_map('wp_delete_file', $this->files);
        $this->files = [];
        parent::tear_down();
    }

    private function command(): MoneySettingsCommand
    {
        return new MoneySettingsCommand(
            $this->settings,
            new SettingsDocument($this->settings),
            static function (): void {
            }
        );
    }

    private function applyDeposit(int $eventId, ?string $amount): void
    {
        $proposed = new ProposedMoneySettings(components: [(new Deposits())->propose($eventId, $amount)]);
        $report = $this->settings->apply($eventId, $proposed, new CliActor(null));
        self::assertSame([], $report->failed());
    }

    /** @param array<string, mixed> $content */
    private function file(array $content): string
    {
        $path = (string) wp_tempnam('kalaka-deposit-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, (string) wp_json_encode($content));
        $this->files[] = $path;

        return $path;
    }

    public function testApplyStoresTheAmountAndShowPrintsItBack(): void
    {
        $this->applyDeposit($this->event, '12500.50');

        self::assertSame('12500.50', (new Deposits())->amount($this->event));

        $this->command()->show([(string) $this->event], []);

        $shown = json_decode(\WP_CLI::$calls[0][1], true);
        self::assertSame(['amount' => '12500.50'], $shown['components']['deposit']);
    }

    public function testAnAmountOfNullRemovesTheDeposit(): void
    {
        $this->applyDeposit($this->event, '10000');
        $this->applyDeposit($this->event, null);

        self::assertNull((new Deposits())->amount($this->event));

        $this->command()->show([(string) $this->event], []);
        $shown = json_decode(\WP_CLI::$calls[0][1], true);
        self::assertSame(['amount' => null], $shown['components']['deposit']);
    }

    public function testTheCommandLineSavesAndRemovesTheDeposit(): void
    {
        $path = $this->file(['components' => ['deposit' => ['amount' => '10000']]]);
        $this->command()->apply([(string) $this->event], ['file' => $path, 'yes' => true]);
        self::assertSame('10000', (new Deposits())->amount($this->event));

        $path = $this->file(['components' => ['deposit' => ['amount' => null]]]);
        \WP_CLI::reset();
        $this->command()->apply([(string) $this->event], ['file' => $path, 'yes' => true]);
        self::assertNull((new Deposits())->amount($this->event));
        self::assertContains(['success', 'The settings are applied.'], \WP_CLI::$calls);
    }

    public function testAnUnknownEventIsRefused(): void
    {
        $this->expectException(NotFound::class);

        (new Deposits())->propose(999999, '10000');
    }

    public function testAMalformedAmountIsRefusedAndNothingIsSaved(): void
    {
        foreach (['0', '0.00', '-1', '1.234', 'abc', ''] as $amount) {
            try {
                (new Deposits())->propose($this->event, $amount);
                self::fail('The amount "' . $amount . '" was accepted.');
            } catch (InvalidInput) {
                self::assertNull((new Deposits())->amount($this->event));
            }
        }

        $path = $this->file(['components' => ['deposit' => ['amount' => '1.234']]]);
        try {
            $this->command()->apply([(string) $this->event], ['file' => $path, 'yes' => true]);
            self::fail('The command line accepted three decimals.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        $errors = array_values(array_filter(
            \WP_CLI::$calls,
            static fn (array $call): bool => $call[0] === 'error'
        ));
        self::assertCount(1, $errors);
        self::assertStringContainsString('The settings of "deposit" are not valid.', $errors[0][1][0]);
        self::assertNull((new Deposits())->amount($this->event));
    }

    public function testAnotherEventKeepsItsOwnDeposit(): void
    {
        $other = $this->create('Másik tábor');

        $this->applyDeposit($this->event, '10000');

        self::assertSame('10000', (new Deposits())->amount($this->event));
        self::assertNull((new Deposits())->amount($other));

        $this->applyDeposit($other, '5000');
        self::assertSame('10000', (new Deposits())->amount($this->event));
        self::assertSame('5000', (new Deposits())->amount($other));
    }

    public function testTheComponentWritesNoFeeLine(): void
    {
        $key = $this->register('00A0');
        $this->setPrice('1500');
        self::assertSame(['fixed_price participant 1500 charge:fixed_price:participant:1'], $this->charges($key));

        $this->applyDeposit($this->event, '10000');

        self::assertSame(['fixed_price participant 1500 charge:fixed_price:participant:1'], $this->charges($key));
    }

    public function testApplyReevaluatesOpenRegistrations(): void
    {
        $this->register('00A0');

        $proposed = new ProposedMoneySettings(components: [(new Deposits())->propose($this->event, '10000')]);
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));

        self::assertSame(['00A0'], array_map(static fn ($item): string => $item->registration->code, $report->done()));
        self::assertSame([], $report->failed());
    }
}
