<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\FieldKeyHeld;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\Cli\MoneySettingsCommand;
use Kalaka\Pricing\Cli\SettingsDocument;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationStatus;

/** `wp kalaka money-settings show|preview|apply` (G3): the JSON shape, the preview, the apply and the refusals. */
final class MoneySettingsCommandTest extends PricingTestCase
{
    private ConfigurableTierComponent $extra;

    /** @var list<string> */
    private array $files = [];

    /** @var list<array{string, list<array<string, string>>}> */
    private array $printed = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
        $this->extra = new ConfigurableTierComponent('200');
        $this->useComponents($this->extra);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        array_map('wp_delete_file', $this->files);
        $this->files = [];
        parent::tear_down();
    }

    private function command(FeeComponent ...$more): MoneySettingsCommand
    {
        $components = new CoreFeeComponents([$this->extra, ...$more]);
        $this->printed = [];

        $settings = new MoneySettings($this->runner, $components);

        return new MoneySettingsCommand(
            $settings,
            new SettingsDocument($settings, $components),
            function (string $format, array $items): void {
                $this->printed[] = [$format, $items];
            }
        );
    }

    /** @param array<string, mixed>|string $content */
    private function file(array|string $content): string
    {
        $path = (string) wp_tempnam('kalaka-money-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, is_string($content) ? $content : (string) wp_json_encode($content));
        $this->files[] = $path;

        return $path;
    }

    private function fails(\Closure $run): string
    {
        try {
            $run();
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        $calls = array_values(array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'error'));
        self::assertCount(1, $calls);

        return $calls[0][1][0];
    }

    public function testShowPrintsTheFixedPriceAndEveryComponentsSettings(): void
    {
        $this->setPrice('1500');

        $this->command()->show([(string) $this->event], []);

        $line = \WP_CLI::$calls[0];
        self::assertSame('line', $line[0]);
        self::assertSame(
            [
                'fixed_price' => '1500',
                'retention' => 'everything_paid',
                'components' => [
                    'extras' => ['charges' => []],
                    'extra' => ['amount' => '200'],
                ],
            ],
            json_decode($line[1], true)
        );
    }

    public function testShowWithoutAPriceHasNullAndTheComponentsAnObject(): void
    {
        $this->useComponents();

        $components = new CoreFeeComponents();
        $settings = new MoneySettings($this->runner, $components);
        (new MoneySettingsCommand($settings, new SettingsDocument($settings, $components)))
            ->show([(string) $this->event], []);

        self::assertStringContainsString('"fixed_price": null', \WP_CLI::$calls[0][1]);
        self::assertStringContainsString('"components": {', \WP_CLI::$calls[0][1]);
        self::assertStringContainsString('"extras": {', \WP_CLI::$calls[0][1]);
        self::assertStringContainsString('"charges": []', \WP_CLI::$calls[0][1]);
        self::assertStringNotContainsString('"donation"', \WP_CLI::$calls[0][1]);
    }

    public function testPreviewShowsWhatEachPayerOwesNowAndWouldOweAndWritesNothing(): void
    {
        $this->setPrice('1000');
        $this->register('00A0');
        $this->register('00B0', RegistrationStatus::Withdrawn);
        $lines = $this->lineCount();
        $file = $this->file(['fixed_price' => '1800', 'components' => ['extra' => ['amount' => null]]]);

        $this->command()->preview([(string) $this->event], ['file' => $file, 'format' => 'json']);

        self::assertSame(
            [['json', [[
                'registration' => '00A0',
                'status' => 'registered',
                'payer' => 'participant',
                'now' => '1200',
                'would_owe' => '1800',
                'problem' => '',
            ]]]],
            $this->printed
        );
        self::assertSame($lines, $this->lineCount());
        self::assertSame('200', $this->extra->amount);
        self::assertSame([], array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'confirm'));
    }

    public function testApplyWithYesSavesTheSettingsAndWritesTheDifferenceAsChargeLines(): void
    {
        $this->setPrice('1000');
        $key = $this->register('00A0');
        self::assertSame('1200', $this->balance($key));
        $file = $this->file(['fixed_price' => '1800', 'components' => ['extra' => ['amount' => '300']]]);

        $this->command()->apply([(string) $this->event], ['file' => $file, 'yes' => true]);

        self::assertSame('300', $this->extra->amount);
        self::assertSame('2100', $this->balance($key));
        self::assertCount(1, $this->printed);
        $calls = array_column(\WP_CLI::$calls, 0);
        self::assertSame(['confirm', 'line', 'success'], array_values(array_unique($calls)));
        self::assertContains(['line', 'Re-evaluated: 1. Failed: 0.'], \WP_CLI::$calls);
    }

    public function testApplyWithoutYesAsksAndTheRefusedAnswerChangesNothing(): void
    {
        $this->setPrice('1000');
        $key = $this->register('00A0');
        $file = $this->file(['fixed_price' => '1800']);
        \WP_CLI::$exitWithoutYes = true;

        try {
            $this->command()->apply([(string) $this->event], ['file' => $file]);
            self::fail('The apply went on without a yes.');
        } catch (\WP_CLI\ExitException $e) {
            self::assertSame(0, $e->exit_code);
        }

        self::assertContains('confirm', array_column(\WP_CLI::$calls, 0));
        self::assertNotContains('error', array_column(\WP_CLI::$calls, 0));

        self::assertSame('1200', $this->balance($key));
    }

    public function testWhatShowPrintsAppliedAgainChangesNothing(): void
    {
        $this->setPrice('1000');
        $key = $this->register('00A0');
        $lines = $this->lineCount();
        $command = $this->command();
        $command->show([(string) $this->event], []);
        $file = $this->file(\WP_CLI::$calls[0][1]);

        $command->apply([(string) $this->event], ['file' => $file, 'yes' => true]);

        self::assertSame($lines, $this->lineCount());
        self::assertSame('1200', $this->balance($key));
    }

    /** The CLI names a refusal by its code: an offer selected by open registrations has its own reason. */
    public function testARuleViolationIsNamedByItsCodeAndAnotherOneIsLeftToTheBoundary(): void
    {
        $this->setPrice('1000');
        $this->register('00A0');
        $named = $this->file(['components' => ['extra' => ['amount' => 'refused-offer']]]);
        $other = $this->file(['components' => ['extra' => ['amount' => 'refused']]]);

        foreach (['preview', 'apply'] as $verb) {
            \WP_CLI::reset();
            $text = $this->fails(fn () => $this->command()->$verb(
                [(string) $this->event],
                ['file' => $named, 'yes' => true]
            ));
            self::assertSame(
                'The settings of "extra" are refused: an offer is selected by open registrations.',
                $text
            );

            \WP_CLI::reset();
            $text = $this->fails(fn () => $this->command()->$verb(
                [(string) $this->event],
                ['file' => $other, 'yes' => true]
            ));
            self::assertStringNotContainsString('offer is selected', $text);
            self::assertStringNotContainsString('held by a participant field', $text);
        }
    }

    /** @return iterable<string, array{string|array<string, mixed>, string}> */
    public static function badFiles(): iterable
    {
        yield 'not JSON' => ['{nope', 'The file is not valid JSON'];
        yield 'a list' => ['[1]', 'The file must hold a JSON object'];
        yield 'an unknown key' => [['price' => '1'], 'Unknown key in the file: price.'];
        yield 'a price that is no decimal' => [['fixed_price' => 'abc'], '"fixed_price" must be a decimal string'];
        yield 'too many decimals' => [['fixed_price' => '1.123456789'], '"fixed_price" must be a decimal string'];
        // The sign is the use case's: the CLI holds no check of its own, so the refusal is InvalidInput's.
        yield 'a negative price' => [['fixed_price' => '-5'], 'Hibás vagy hiányzó adat'];
        yield 'a number price' => [['fixed_price' => 5], '"fixed_price" must be a decimal string'];
        yield 'an unknown component' => [
            ['components' => ['nope' => ['amount' => '1']]],
            'No component has settings under the ID "nope".',
        ];
        yield 'invalid component settings' => [
            ['components' => ['extra' => ['amount' => 5]]],
            'The settings of "extra" are not valid.',
        ];
        yield 'a component with the wrong shape' => [
            ['components' => ['extra' => ['amount' => '1', 'more' => true]]],
            'The settings of "extra" are not valid.',
        ];
    }

    /**
     * @dataProvider badFiles
     * @param string|array<string, mixed> $content
     */
    public function testABadFileIsRefusedWithAClearErrorAndNothingIsWritten(
        string|array $content,
        string $message
    ): void {
        $this->setPrice('1000');
        $this->register('00A0');
        $lines = $this->lineCount();
        $file = $this->file($content);

        foreach (['preview', 'apply'] as $verb) {
            \WP_CLI::reset();
            $args = ['file' => $file, 'yes' => true];
            $text = $this->fails(fn () => $this->command()->$verb([(string) $this->event], $args));
            self::assertStringContainsString($message, $text);
        }

        self::assertSame($lines, $this->lineCount());
        self::assertSame('200', $this->extra->amount);
    }

    public function testARuleViolationThatIsNotAHeldFieldKeyPassesThroughWithoutTheKeyWording(): void
    {
        $components = new CoreFeeComponents([$this->extra]);
        $document = new SettingsDocument(new MoneySettings($this->runner, $components), $components);

        try {
            $document->proposed($this->event, ['components' => ['extra' => ['amount' => 'violation']]]);
            self::fail('Refused.');
        } catch (RuleViolation $violation) {
            self::assertNotInstanceOf(FieldKeyHeld::class, $violation);
            self::assertNotInstanceOf(CommandFailure::class, $violation);
        }
    }

    public function testAMissingOrUnreadableFileAndABadEventAreRefused(): void
    {
        self::assertStringContainsString(
            'Give the settings with --file',
            $this->fails(fn () => $this->command()->preview([(string) $this->event], []))
        );
        \WP_CLI::reset();
        self::assertStringContainsString(
            'cannot be read',
            $this->fails(fn () => $this->command()->preview([(string) $this->event], ['file' => '/nonexistent.json']))
        );
        \WP_CLI::reset();
        self::assertStringContainsString(
            'positive whole number',
            $this->fails(fn () => $this->command()->show(['abc'], []))
        );
        \WP_CLI::reset();
        self::assertNotSame('', $this->fails(fn () => $this->command()->show(['999999'], [])));
    }

    public function testAUserWithoutFullAccessIsRefusedByTheApplyAndNothingIsWritten(): void
    {
        $this->setPrice('1000');
        $key = $this->register('00A0');
        $lines = $this->lineCount();
        $file = $this->file(['fixed_price' => '1800', 'components' => ['extra' => ['amount' => '300']]]);
        wp_set_current_user(self::factory()->user->create());

        $text = $this->fails(
            fn () => $this->command()->apply([(string) $this->event], ['file' => $file, 'yes' => true])
        );

        self::assertNotSame('', $text);
        self::assertSame($lines, $this->lineCount());
        self::assertSame('1200', $this->balance($key));
        self::assertSame('200', $this->extra->amount);
    }

    public function testAUserWithoutFullAccessIsRefusedByShowAndPreviewBeforeAnythingIsRead(): void
    {
        $this->setPrice('1000');
        $this->register('00A0');
        $file = $this->file(['fixed_price' => '1800']);
        wp_set_current_user(self::factory()->user->create());

        foreach (['show' => [], 'preview' => ['file' => $file]] as $verb => $args) {
            \WP_CLI::reset();
            $this->printed = [];
            $this->fails(fn () => $this->command()->$verb([(string) $this->event], $args));

            self::assertSame([], $this->printed, $verb);
            self::assertSame([], array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'line'));
        }
    }

    public function testAUserWithFullAccessAppliesAndShowAndPreviewAreReads(): void
    {
        $this->setPrice('1000');
        $key = $this->register('00A0');
        $user = self::factory()->user->create();
        get_userdata($user)->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);
        $file = $this->file(['fixed_price' => '1800']);

        $this->command()->preview([(string) $this->event], ['file' => $file]);
        self::assertSame('1200', $this->balance($key));
        $this->command()->apply([(string) $this->event], ['file' => $file, 'yes' => true]);

        self::assertSame('2000', $this->balance($key));
    }

    public function testAFailedRegistrationIsReportedAndTheCommandEndsInAnErrorWithTheSettingsSaved(): void
    {
        $this->setPrice('1000');
        $good = $this->register('00A0');
        $this->register('00B0');
        $flaky = new FailingComponent('00B0');
        $flaky->failing = true;
        $this->useComponents($this->extra, $flaky);
        $file = $this->file(['fixed_price' => '1800']);

        $text = $this->fails(
            fn () => $this->command($flaky)->apply([(string) $this->event], ['file' => $file, 'yes' => true])
        );

        self::assertStringContainsString('The settings are saved, but some registrations failed', $text);
        self::assertContains(['line', 'Re-evaluated: 1. Failed: 1.'], \WP_CLI::$calls);
        $failed = array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'line'
            && str_starts_with($call[1], 'Failed 00B0: '));
        self::assertCount(1, $failed);
        $line = array_values($failed)[0][1];
        self::assertStringContainsString('Váratlan hiba', $line);
        self::assertStringContainsString('Hibaazonosító: ', $line);
        self::assertSame('2000', $this->balance($good));
        $this->command($flaky)->preview([(string) $this->event], ['file' => $file]);
        self::assertNotSame('', $this->printed[0][1][1]['problem']);
    }
}
