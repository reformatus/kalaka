<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Deposits;

use Kalaka\Deposits\Cli\DepositCommand;
use Kalaka\Deposits\DepositSettings;
use Kalaka\Tests\Integration\Events\EventsTestCase;

/** `wp kalaka deposit <event> [<amount>] [--off]`: the stored amount, what it prints and what it refuses. */
final class DepositCommandTest extends EventsTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        // WP-CLI is not loaded in PHPUnit; core's recording double stands in for it.
        require_once dirname(__DIR__, 4) . '/kalaka/tests/integration/Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
        delete_option(DepositSettings::OPTION);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        delete_option(DepositSettings::OPTION);
        parent::tear_down();
    }

    /** @param list<string> $args @param array<string, mixed> $assoc */
    private function command(array $args, array $assoc = []): void
    {
        (new DepositCommand())($args, $assoc);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     * @return string the error message the command ended with
     */
    private function refused(array $args, array $assoc = []): string
    {
        \WP_CLI::reset();
        try {
            $this->command($args, $assoc);
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        $errors = array_values(array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'error'));
        self::assertCount(1, $errors);

        return $errors[0][1][0];
    }

    /** @return list<array{string, mixed}> */
    private function printed(): array
    {
        return array_values(array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] !== 'add_command'));
    }

    public function testSettingStoresTheAmountAndTheSettingsReadItBack(): void
    {
        $event = $this->create();

        $this->command([(string) $event, '10000']);

        self::assertSame([['success', 'Deposit for event ' . $event . ': 10000']], $this->printed());
        self::assertSame('10000', (new DepositSettings())->of($event));
        self::assertSame([(string) $event => '10000'], (new DepositSettings())->all());
        self::assertSame([(string) $event => '10000'], get_option(DepositSettings::OPTION));
    }

    public function testASecondSetReplacesTheAmountAndKeepsTwoDecimals(): void
    {
        $event = $this->create();

        $this->command([(string) $event, '10000']);
        $this->command([(string) $event, '12500.50']);

        self::assertSame('12500.50', (new DepositSettings())->of($event));
    }

    public function testZeroIsAnAmount(): void
    {
        $event = $this->create();

        $this->command([(string) $event, '0']);

        self::assertSame('0', (new DepositSettings())->of($event));
    }

    public function testShowPrintsTheDepositOrSaysThereIsNone(): void
    {
        $event = $this->create();

        $this->command([(string) $event]);
        self::assertSame([['line', 'No deposit for event ' . $event . '.']], $this->printed());

        $this->command([(string) $event, '10000']);
        \WP_CLI::reset();
        $this->command([(string) $event]);
        self::assertSame([['line', 'Deposit for event ' . $event . ': 10000']], $this->printed());
    }

    public function testOffRemovesTheDeposit(): void
    {
        $event = $this->create();
        $this->command([(string) $event, '10000']);
        \WP_CLI::reset();

        $this->command([(string) $event], ['off' => true]);

        self::assertSame([['success', 'Deposit removed for event ' . $event . '.']], $this->printed());
        self::assertNull((new DepositSettings())->of($event));
        self::assertSame([], (new DepositSettings())->all());
        self::assertFalse(get_option(DepositSettings::OPTION));
    }

    public function testOffOnAnEventWithoutADepositChangesNothing(): void
    {
        $event = $this->create();

        $this->command([(string) $event], ['off' => true]);

        self::assertNull((new DepositSettings())->of($event));
        self::assertFalse(get_option(DepositSettings::OPTION));
    }

    public function testOtherEventsAreUnaffected(): void
    {
        $first = $this->create('Első tábor');
        $second = $this->create('Második tábor');
        $third = $this->create('Harmadik tábor');

        $this->command([(string) $first, '10000']);
        $this->command([(string) $second, '5000.25']);
        $this->command([(string) $first, '12000']);

        self::assertSame('12000', (new DepositSettings())->of($first));
        self::assertSame('5000.25', (new DepositSettings())->of($second));
        self::assertNull((new DepositSettings())->of($third));

        $this->command([(string) $first], ['off' => true]);

        self::assertNull((new DepositSettings())->of($first));
        self::assertSame('5000.25', (new DepositSettings())->of($second));
        self::assertSame([(string) $second => '5000.25'], (new DepositSettings())->all());
    }

    public function testAnUnknownEventIsRefusedForEveryForm(): void
    {
        $event = $this->create();
        $unknown = (string) ($event + 1000);

        self::assertSame('No event found: ' . $unknown, $this->refused([$unknown]));
        self::assertSame('No event found: ' . $unknown, $this->refused([$unknown, '10000']));
        self::assertSame('No event found: ' . $unknown, $this->refused([$unknown], ['off' => true]));
        self::assertSame([], (new DepositSettings())->all());
    }

    /** @return array<string, array{string}> */
    public static function badEvents(): array
    {
        return ['text' => ['abc'], 'decimal' => ['1.5'], 'negative' => ['-3'], 'zero' => ['0'], 'empty' => ['']];
    }

    /** @dataProvider badEvents */
    public function testAnEventThatIsNotAnIntegerIdIsRefused(string $given): void
    {
        self::assertSame(
            'The event must be an event ID (an integer): ' . $given,
            $this->refused([$given, '10000'])
        );
        self::assertSame([], (new DepositSettings())->all());
    }

    /** @return array<string, array{string, string}> */
    public static function badAmounts(): array
    {
        return [
            'text' => ['ten', 'The amount must be a non-negative number: ten'],
            'negative' => ['-100', 'The amount must be a non-negative number: -100'],
            'comma' => ['1,5', 'The amount must be a non-negative number: 1,5'],
            'exponent' => ['1e3', 'The amount must be a non-negative number: 1e3'],
            'empty' => ['', 'The amount must be a non-negative number: '],
            'three decimals' => ['10.001', 'The amount has at most two decimals: 10.001'],
            'trailing zeros' => ['10.500', 'The amount has at most two decimals: 10.500'],
        ];
    }

    /** @dataProvider badAmounts */
    public function testAMalformedAmountIsRefusedAndStoresNothing(string $amount, string $message): void
    {
        $event = $this->create();
        $this->command([(string) $event, '10000']);

        self::assertSame($message, $this->refused([(string) $event, $amount]));

        self::assertSame('10000', (new DepositSettings())->of($event));
    }

    public function testAnAmountWithOffIsRefused(): void
    {
        $event = $this->create();
        $this->command([(string) $event, '10000']);

        self::assertSame(
            'Give an amount or --off, not both.',
            $this->refused([(string) $event, '5000'], ['off' => true])
        );

        self::assertSame('10000', (new DepositSettings())->of($event));
    }

    public function testTheSettingsRefuseAMalformedAmountThemselves(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DepositSettings())->set(1, '-1');
    }

    public function testAStoredOptionThatIsNotAMapReadsAsNoDeposit(): void
    {
        update_option(DepositSettings::OPTION, 'broken');

        self::assertSame([], (new DepositSettings())->all());
        self::assertNull((new DepositSettings())->of(1));
    }
}
