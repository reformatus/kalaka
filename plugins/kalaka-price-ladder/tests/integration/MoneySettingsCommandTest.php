<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Pricing\Cli\MoneySettingsCommand;
use Kalaka\Pricing\Cli\SettingsDocument;

/** `wp kalaka money-settings` with the price ladder: its tiers, conditions and deadlines (G3). */
final class MoneySettingsCommandTest extends PriceLadderTestCase
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

    public function testApplyEntersALadderAndShowPrintsItBackWithItsDeadlineDay(): void
    {
        $tiers = [
            self::tier('korai', 'Korai ár', '40000', [], '2026-12-31'),
            ...self::ivkTiers(),
        ];
        $path = (string) wp_tempnam('kalaka-money-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, (string) wp_json_encode(['components' => ['price_ladder' => ['tiers' => $tiers]]]));
        $this->files[] = $path;
        $key = $this->register('00A0');

        $this->command()->apply([(string) $this->event], ['file' => $path, 'yes' => true]);

        self::assertCount(6, $this->ladder->tiers($this->event));
        self::assertSame(['price_ladder participant 40000 charge:price_ladder:participant:1'], $this->charges($key));

        \WP_CLI::reset();
        $this->command()->show([(string) $this->event], []);
        $shown = json_decode(\WP_CLI::$calls[0][1], true);
        self::assertSame($tiers, $shown['components']['price_ladder']['tiers']);
        self::assertNull($shown['fixed_price']);
    }
}
