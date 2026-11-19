<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Pricing\Cli\MoneySettingsCommand;
use Kalaka\Pricing\Cli\SettingsDocument;

/** `wp kalaka money-settings` with the fixed price, lunch and the donation (G3). */
final class MoneySettingsCommandTest extends ExtrasTestCase
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

    public function testApplyEntersThePriceLunchAndDonationAndShowPrintsThemBack(): void
    {
        $file = $this->file([
            'fixed_price' => '30000',
            'components' => [
                'lunch' => ['items' => [self::item('szombat', 'Szombati ebéd', '2000', ['ebed' => 'igen'])]],
                'donation' => ['field_key' => 'adomany'],
            ],
        ]);
        $key = $this->registerWith('00A0', ['ebed' => 'igen', 'adomany' => '5000']);

        $this->command()->apply([(string) $this->event], ['file' => $file, 'yes' => true]);

        self::assertSame(
            [
                'fixed_price charge participant 30000',
                'lunch charge participant 2000',
                'donation charge participant 5000',
            ],
            array_map(
                static fn (string $line): string => implode(' ', array_slice(explode(' ', $line), 0, 1)) . ' charge '
                    . implode(' ', array_slice(explode(' ', $line), 1, 2)),
                $this->charges($key)
            )
        );

        \WP_CLI::reset();
        $this->command()->show([(string) $this->event], []);
        $shown = json_decode(\WP_CLI::$calls[0][1], true);
        self::assertSame('30000', $shown['fixed_price']);
        self::assertSame('szombat', $shown['components']['lunch']['items'][0]['key']);
        self::assertSame('2000', $shown['components']['lunch']['items'][0]['amount']);
        self::assertSame(['field_key' => 'adomany'], $shown['components']['donation']);
    }

    public function testPreviewOfLunchWritesNothingAndABadItemIsRefused(): void
    {
        $this->setPrice('1000');
        $key = $this->registerWith('00A0', ['ebed' => 'igen']);
        $lines = $this->lineCount();

        $this->command()->preview([(string) $this->event], [
            'file' => $this->file(['components' => ['lunch' => ['items' => [self::item('a', 'Ebéd', '500')]]]]),
        ]);
        self::assertSame($lines, $this->lineCount());
        self::assertSame([], $this->lunch->items($this->event));

        \WP_CLI::reset();
        $bad = $this->file(['components' => ['lunch' => ['items' => [['key' => 'a']]]]]);
        try {
            $this->command()->preview([(string) $this->event], ['file' => $bad]);
            self::fail('A lunch item without a label was taken.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        self::assertSame(['error', ['The settings of "lunch" are not valid.', 1]], \WP_CLI::$calls[0]);
        self::assertSame($lines, $this->lineCount());
        self::assertNotSame('', $key->code);
    }
}
