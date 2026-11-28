<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Mail\Pause\BudgetSetting;
use Kalaka\Mail\Pause\MailBudgetInvalid;
use Kalaka\Mail\Pause\MailState;
use Kalaka\Mail\Pause\SetMailBudget;

/**
 * The hourly budget as a virtual WordPress setting over the sender's state row: what `options.php` does with
 * the group `kalaka_mail`, the value `get_option()` gives, the refusal as a settings error, and no option row
 * ever. [UNAPPROVED N1188]
 */
final class BudgetSettingTest extends MailAdminTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $GLOBALS['wp_settings_errors'] = [];
        BudgetSetting::register();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        unregister_setting(BudgetSetting::GROUP, BudgetSetting::OPTION);
        $GLOBALS['wp_settings_errors'] = [];

        parent::tear_down();
    }

    /**
     * What `wp-admin/options.php` does with a posted settings form of the group: the capability the group asks
     * for, then every option of the group updated with the trimmed, unslashed posted value, `null` when absent.
     *
     * @param array<string, mixed> $post
     * @return bool whether options.php lets the user save
     */
    private function postToOptionsPage(array $post): bool
    {
        $capability = apply_filters('option_page_capability_' . BudgetSetting::GROUP, 'manage_options');
        if (!current_user_can($capability)) {
            return false;
        }
        $allowed = apply_filters('allowed_options', []);
        self::assertIsArray($allowed);
        foreach ($allowed[BudgetSetting::GROUP] as $option) {
            $value = null;
            if (isset($post[$option])) {
                $value = is_array($post[$option]) ? $post[$option] : trim((string) $post[$option]);
                $value = wp_unslash($value);
            }
            update_option($option, $value);
        }

        return true;
    }

    private function optionRows(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", BudgetSetting::OPTION)
        );
    }

    /** @return list<array{setting: string, code: string, message: string, type: string}> */
    private function settingsErrors(): array
    {
        return array_values(get_settings_errors(BudgetSetting::GROUP));
    }

    public function testTheModuleRegistersTheSettingOnAdminInit(): void
    {
        self::assertIsInt(has_action('admin_init', [BudgetSetting::class, 'register']));

        $allowed = apply_filters('allowed_options', []);
        self::assertIsArray($allowed);
        self::assertSame([BudgetSetting::OPTION], $allowed[BudgetSetting::GROUP]);
        $registered = get_registered_settings();
        self::assertSame('integer', $registered[BudgetSetting::OPTION]['type']);
        self::assertFalse($registered[BudgetSetting::OPTION]['show_in_rest']);
        self::assertSame(['kalaka_mail', 'kalaka_mail_hourly_budget'], [BudgetSetting::GROUP, BudgetSetting::OPTION]);
    }

    public function testAValidValueChangesTheStateRowThroughTheUseCaseAndWritesNoOption(): void
    {
        $this->as($this->fullAccessUser());
        self::assertNull($this->stateRow(), 'No state row yet: the first save makes it.');

        self::assertTrue($this->postToOptionsPage([BudgetSetting::OPTION => ' 600 ']));

        $state = $this->stateRow();
        self::assertIsArray($state);
        self::assertSame('600', $state['hourly_budget']);
        $entries = $this->entriesOf('kalaka/set-mail-budget');
        self::assertCount(1, $entries);
        self::assertContains('hourly_budget', $this->changedFields($entries[0]));
        self::assertSame(0, $this->optionRows(), 'No option row, not even on the first save.');
        self::assertArrayNotHasKey(BudgetSetting::OPTION, wp_load_alloptions(true));
        self::assertSame([], $this->settingsErrors());
        self::assertSame(600, get_option(BudgetSetting::OPTION));

        // A second save of the same and of another value: still no row; each run is audited, as the CLI's is.
        $this->postToOptionsPage([BudgetSetting::OPTION => '600']);
        $this->postToOptionsPage([BudgetSetting::OPTION => '55']);
        self::assertSame('55', $this->stateRow()['hourly_budget'] ?? null);
        self::assertCount(3, $this->entriesOf('kalaka/set-mail-budget'));
        self::assertSame(0, $this->optionRows());
    }

    public function testAnOptionRowThatIsThereDoesNotChangeTheBudgetEither(): void
    {
        $this->as($this->fullAccessUser());
        $this->storeState(['hourly_budget' => 90]);
        // An option row some foreign code left: the setting still answers from the state row and never writes.
        global $wpdb;
        $wpdb->insert(
            $wpdb->options,
            ['option_name' => BudgetSetting::OPTION, 'option_value' => '7', 'autoload' => 'no']
        );
        wp_cache_delete(BudgetSetting::OPTION, 'options');

        self::assertSame(90, get_option(BudgetSetting::OPTION));
        $this->postToOptionsPage([BudgetSetting::OPTION => '95']);

        self::assertSame('95', $this->stateRow()['hourly_budget'] ?? null);
        self::assertSame('7', $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", BudgetSetting::OPTION)
        ), 'The row is left as it was.');
    }

    public function testAnOutOfRangeValueLeavesTheBudgetAndAddsTheRefusalAsASettingsError(): void
    {
        $this->as($this->fullAccessUser());
        $this->storeState(['hourly_budget' => 120]);

        foreach (['0', '2001', '-5', 'abc', '1.5', ''] as $bad) {
            $GLOBALS['wp_settings_errors'] = [];
            self::assertTrue($this->postToOptionsPage([BudgetSetting::OPTION => $bad]), $bad);

            self::assertSame('120', $this->stateRow()['hourly_budget'] ?? null, 'Unchanged after ' . $bad);
            $errors = $this->settingsErrors();
            self::assertCount(1, $errors, $bad);
            self::assertSame('kalaka_mail_budget_invalid', $errors[0]['code']);
            self::assertSame((new MailBudgetInvalid())->displayMessage(), $errors[0]['message']);
            self::assertStringEndsWith(MailBudgetInvalid::MESSAGE, $errors[0]['message']);
            self::assertSame('error', $errors[0]['type']);
            self::assertSame(120, get_option(BudgetSetting::OPTION));
        }
        // A missing field and an array are refused the same way.
        foreach ([[], [BudgetSetting::OPTION => ['9']]] as $post) {
            $GLOBALS['wp_settings_errors'] = [];
            $this->postToOptionsPage($post);
            self::assertSame('120', $this->stateRow()['hourly_budget'] ?? null);
            self::assertSame(
                (new MailBudgetInvalid())->displayMessage(),
                $this->settingsErrors()[0]['message'] ?? null
            );
        }
        self::assertSame(0, $this->optionRows());
    }

    public function testGetOptionGivesTheStateRowsBudgetAndTheDefaultWithoutARow(): void
    {
        $this->as($this->fullAccessUser());
        self::assertSame(MailState::DEFAULT_HOURLY_BUDGET, get_option(BudgetSetting::OPTION));

        $this->storeState(['hourly_budget' => 333]);
        self::assertSame(333, get_option(BudgetSetting::OPTION));

        // The sender's own use case moves it: the setting follows with no cache of its own.
        $this->exec(new SetMailBudget(), ['hourly_budget' => 12]);
        self::assertSame(12, get_option(BudgetSetting::OPTION));
        self::assertSame(0, $this->optionRows());
    }

    public function testTheValueIsNotGivenToAnyoneWithoutFullAccess(): void
    {
        $this->storeState(['hourly_budget' => 333]);

        foreach ([0, self::factory()->user->create(['role' => 'administrator'])] as $user) {
            $this->as($user);
            self::assertSame(MailState::DEFAULT_HOURLY_BUDGET, get_option(BudgetSetting::OPTION));
        }
    }

    public function testTheGroupAsksForFullAccessAndOptionsPhpRefusesAnyoneElse(): void
    {
        self::assertSame(
            AdminAccess::FULL_ACCESS,
            apply_filters('option_page_capability_' . BudgetSetting::GROUP, 'manage_options')
        );
        $this->storeState(['hourly_budget' => 120]);

        // Administrators have no full access by their role; a grant holder and a plain user neither.
        $event = $this->published();
        $users = [
            0,
            self::factory()->user->create(['role' => 'administrator']),
            self::factory()->user->create(['role' => 'subscriber']),
            $this->holder($event),
        ];
        foreach ($users as $user) {
            $this->as($user);
            self::assertFalse($this->postToOptionsPage([BudgetSetting::OPTION => '500']), 'User ' . $user);
        }
        self::assertSame('120', $this->stateRow()['hourly_budget'] ?? null);

        $this->as($this->fullAccessUser());
        self::assertTrue($this->postToOptionsPage([BudgetSetting::OPTION => '500']));
        self::assertSame('500', $this->stateRow()['hourly_budget'] ?? null);
    }

    public function testASaveByAUserWithoutFullAccessThatReachesTheSettingIsRefusedByTheUseCase(): void
    {
        $this->storeState(['hourly_budget' => 120]);
        $this->as(self::factory()->user->create(['role' => 'administrator']));

        update_option(BudgetSetting::OPTION, '500');

        self::assertSame('120', $this->stateRow()['hourly_budget'] ?? null);
        $errors = $this->settingsErrors();
        self::assertCount(1, $errors);
        self::assertSame('kalaka_permission_denied', $errors[0]['code']);
        self::assertSame(0, $this->optionRows());
    }
}
