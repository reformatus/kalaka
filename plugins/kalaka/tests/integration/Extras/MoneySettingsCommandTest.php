<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Pricing\Cli\MoneySettingsCommand;
use Kalaka\Pricing\Cli\SettingsDocument;

/** `wp kalaka money-settings` with the fixed price, the extra charges and the donation (G3). */
final class MoneySettingsCommandTest extends ExtrasTestCase
{
    /** @var list<string> */
    private array $files = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
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
        parent::tear_down();
    }

    private function command(): MoneySettingsCommand
    {
        $document = new SettingsDocument($this->settings);

        return new MoneySettingsCommand($this->settings, $document, static function (): void {
        });
    }

    /** @param array<string, mixed> $content */
    private function file(array $content): string
    {
        $path = (string) wp_tempnam('kalaka-money-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, (string) wp_json_encode($content));
        $this->files[] = $path;

        return $path;
    }

    public function testApplyEntersThePriceChargesAndDonationAndShowPrintsThemBack(): void
    {
        $file = $this->file([
            'fixed_price' => '30000',
            'components' => [
                'extras' => ['charges' => [self::item('szombat', 'Szombati ebéd', '2000', 'ebed')]],
                'donation' => ['field_key' => 'adomany'],
            ],
        ]);
        $key = $this->registerWith('00A0', ['ebed' => '1', 'adomany' => '5000']);

        $this->command()->apply([(string) $this->event], ['file' => $file, 'yes' => true]);

        self::assertSame(
            [
                'fixed_price participant 30000 charge:fixed_price:participant:1',
                'extras participant 2000 charge:extras:participant:1',
                'donation participant 5000 charge:donation:participant:1',
            ],
            $this->charges($key)
        );

        \WP_CLI::reset();
        $this->command()->show([(string) $this->event], []);
        $shown = json_decode(\WP_CLI::$calls[0][1], true);
        self::assertSame('30000', $shown['fixed_price']);
        self::assertSame('szombat', $shown['components']['extras']['charges'][0]['key']);
        self::assertSame('2000', $shown['components']['extras']['charges'][0]['amount']);
        self::assertSame('ebed', $shown['components']['extras']['charges'][0]['field']);
        self::assertSame(['field_key' => 'adomany'], $shown['components']['donation']);

        $lines = $this->lineCount();
        $this->command()->apply([(string) $this->event], ['file' => $this->file($shown), 'yes' => true]);
        self::assertSame($lines, $this->lineCount());
    }

    public function testPreviewOfChargesWritesNothingAndABadChargeIsRefused(): void
    {
        $this->setPrice('1000');
        $key = $this->registerWith('00A0', ['ebed' => '1']);
        $lines = $this->lineCount();

        $this->command()->preview([(string) $this->event], [
            'file' => $this->file([
                'components' => ['extras' => ['charges' => [self::item('a', 'Ebéd', '500', 'ebed')]]],
            ]),
        ]);
        self::assertSame($lines, $this->lineCount());
        self::assertSame([], $this->extras->charges($this->event));

        \WP_CLI::reset();
        $bad = $this->file(['components' => ['extras' => ['charges' => [['key' => 'a']]]]]);
        try {
            $this->command()->preview([(string) $this->event], ['file' => $bad]);
            self::fail('A charge without a label was taken.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        self::assertSame(['error', ['The settings of "extras" are not valid.', 1]], \WP_CLI::$calls[0]);
        self::assertSame($lines, $this->lineCount());
        self::assertNotSame('', $key->code);
    }

    public function testWhatShowPrintsForAnEventWithNoSettingsAppliesAsIs(): void
    {
        $command = $this->command();
        $command->show([(string) $this->event], []);
        $path = (string) wp_tempnam('kalaka-money-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, \WP_CLI::$calls[0][1]);
        $this->files[] = $path;

        $command->apply([(string) $this->event], ['file' => $path, 'yes' => true]);

        self::assertContains(['line', 'Re-evaluated: 0. Failed: 0.'], \WP_CLI::$calls);
        self::assertContains(['success', 'The settings are applied.'], \WP_CLI::$calls);
    }
}
