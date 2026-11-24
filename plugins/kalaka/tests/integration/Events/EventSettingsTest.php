<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\AccountBlockMissing;
use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\EventNotReady;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\UpdateEvent;
use Kalaka\Forms\BrokenForms;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/** The writer of the event's payment and account settings, in `kalaka/update-event` (go-live G2). */
final class EventSettingsTest extends FormsTestCase
{
    private const SETTINGS = [
        'account_mode' => 'off',
        'payment_due_days' => 7,
        'transfer_beneficiary' => 'Teszt Egyesület',
        'transfer_account' => '11111111-22222222-33333333',
        'transfer_reference_prefix' => 'TESZT26',
    ];

    /** @param array<string, mixed> $more */
    private function save(int $id, array $more): void
    {
        $this->events->update($more + $this->updateInput($id, $this->versionOf($id)), $this->admin);
    }

    public function testTheSettingsAreWrittenAndReadBackThroughTheApi(): void
    {
        $id = $this->filled();
        $before = $this->versionOf($id);

        $this->save($id, self::SETTINGS);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame($before + 1, $event->version);
        self::assertSame(AccountMode::Off, $event->accountMode);
        self::assertSame(7, $event->paymentDueDays);
        self::assertSame('Teszt Egyesület', $event->transferBeneficiary);
        self::assertSame('11111111-22222222-33333333', $event->transferAccount);
        self::assertSame('TESZT26', $event->transferReferencePrefix);
        // A change of the event is audited with its fields.
        $entries = $this->entries();
        $last = $entries[array_key_last($entries)];
        self::assertSame('kalaka/update-event', $last['action']);
        self::assertStringContainsString('transfer_account', (string) $last['changes']);
    }

    public function testAPreviewChecksEverythingAndSavesNothing(): void
    {
        $id = $this->filled();
        $version = $this->versionOf($id);
        $registration = ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'];
        $lacking = $this->insertForm($this->slotFields(), $registration);

        $this->save($id, ['preview' => true, 'payment_due_days' => 9]);
        self::assertSame($version, $this->versionOf($id));

        try {
            $this->save($id, ['preview' => true, 'account_mode' => 'mandatory']);
            self::fail('The preview did not refuse.');
        } catch (AccountBlockMissing $error) {
            self::assertSame([$lacking], $error->forms);
        }
        try {
            $this->save($id, ['preview' => true, 'version' => $version + 5]);
            self::fail('The preview did not refuse.');
        } catch (VersionConflict) {
            self::assertSame($version, $this->versionOf($id));
        }
    }

    public function testAKeyThatIsAbsentStaysAndNullOrAnEmptyTextClearsIt(): void
    {
        $id = $this->filled();
        $this->save($id, self::SETTINGS);

        $this->save($id, ['name' => 'Más név']);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame('Más név', $event->name);
        self::assertSame(7, $event->paymentDueDays);
        self::assertSame('TESZT26', $event->transferReferencePrefix);

        $this->save($id, ['payment_due_days' => null, 'transfer_account' => '   ', 'transfer_beneficiary' => null]);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertNull($event->paymentDueDays);
        self::assertNull($event->transferAccount);
        self::assertNull($event->transferBeneficiary);
        self::assertSame('TESZT26', $event->transferReferencePrefix);
    }

    public function testATextIsTrimmedAndZeroDaysIsAllowed(): void
    {
        $id = $this->filled();

        $this->save($id, ['transfer_reference_prefix' => '  TESZT26  ', 'payment_due_days' => 0]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame('TESZT26', $event->transferReferencePrefix);
        self::assertSame(0, $event->paymentDueDays);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badSettings(): iterable
    {
        yield 'negative days' => [['payment_due_days' => -1]];
        yield 'too many days' => [['payment_due_days' => 65536]];
        yield 'unknown mode' => [['account_mode' => 'required']];
        yield 'mode null' => [['account_mode' => null]];
        yield 'beneficiary too long' => [['transfer_beneficiary' => str_repeat('á', 192)]];
        yield 'account too long' => [['transfer_account' => str_repeat('1', 65)]];
        yield 'prefix too long' => [['transfer_reference_prefix' => str_repeat('a', 33)]];
        yield 'account as number' => [['transfer_account' => 12345]];
    }

    /**
     * @dataProvider badSettings
     * @param array<string, mixed> $settings
     */
    public function testABadSettingIsRefusedAndNothingChanges(array $settings): void
    {
        $id = $this->filled();
        $before = $this->row($id);

        try {
            $this->save($id, $settings);
            self::fail('A bad setting was accepted.');
        } catch (InvalidInput) {
            self::assertSame($before, $this->row($id));
        }
    }

    public function testTheColumnLimitsAreAllowedExactly(): void
    {
        $id = $this->filled();

        $this->save($id, [
            'transfer_beneficiary' => str_repeat('ő', 191),
            'transfer_account' => str_repeat('1', 64),
            'transfer_reference_prefix' => str_repeat('a', 32),
            'payment_due_days' => 65535,
        ]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(191, mb_strlen((string) $event->transferBeneficiary, 'UTF-8'));
        self::assertSame(65535, $event->paymentDueDays);
    }

    public function testTheModeChangesToEachValueWhenNoFormIsLive(): void
    {
        $id = $this->filled();

        foreach (['mandatory', 'optional', 'off'] as $mode) {
            $this->save($id, ['account_mode' => $mode]);
            $event = $this->events->find($id);
            self::assertNotNull($event);
            self::assertSame($mode, $event->accountMode->value);
        }
    }

    public function testAnAccountModeIsRefusedWhileAPublishedRegistrationFormLacksTheBlock(): void
    {
        $id = $this->filled();
        $registration = ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'];
        $form = $this->insertForm($this->slotFields(), $registration);
        // An additional form never needs the block; a draft is not published.
        $this->insertForm(
            $this->field('text', 'megjegyzes'),
            ['kalaka_event_id' => $id, 'kalaka_placement' => 'additional']
        );
        $this->insertForm(
            $this->slotFields(),
            ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'],
            ['post_status' => 'draft']
        );
        self::assertSame([$form], $this->forms->withoutAccountBlock($id, AccountMode::Mandatory));
        self::assertSame([], $this->forms->withoutAccountBlock($id, AccountMode::Off));

        foreach (['mandatory', 'optional'] as $mode) {
            try {
                $this->save($id, ['account_mode' => $mode]);
                self::fail('The mode was accepted.');
            } catch (AccountBlockMissing $e) {
                self::assertSame([$form], $e->forms);
                self::assertSame((new RuleViolation())->getMessage(), $e->getMessage());
                self::assertSame('kalaka_account_block_missing', $e->errorCode());
                $event = $this->events->find($id);
                self::assertNotNull($event);
                self::assertSame(AccountMode::Off, $event->accountMode);
            }
        }
        // Off is always allowed, and so is a form that holds the block.
        $this->save($id, ['account_mode' => 'off']);
        wp_update_post(['ID' => $form, 'post_content' => '<!-- wp:kalaka/account /-->' . $this->slotFields()]);
        $this->save($id, ['account_mode' => 'mandatory']);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Mandatory, $event->accountMode);
    }

    public function testAnUnchangedModeIsNotRefusedWhenTheFormLostItsBlockLater(): void
    {
        $id = $this->filled();
        $form = $this->insertForm(
            '<!-- wp:kalaka/account /-->' . $this->slotFields(),
            ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration']
        );
        $this->save($id, ['account_mode' => 'mandatory']);
        // Written around the save check, which would veto it.
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $this->slotFields()], ['ID' => $form]);
        clean_post_cache($form);
        $this->insertRevisionOf($form);
        self::assertSame([$form], $this->forms->withoutAccountBlock($id, AccountMode::Mandatory));

        $this->save($id, ['account_mode' => 'mandatory', 'payment_due_days' => 3]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Mandatory, $event->accountMode);
        self::assertSame(3, $event->paymentDueDays);
    }

    /** The form's latest revision as the post now is, as a save would have left it. */
    private function insertRevisionOf(int $form): void
    {
        wp_save_post_revision($form);
    }

    public function testADraftFormWithoutTheBlockDoesNotRefuseTheChange(): void
    {
        $id = $this->filled();
        $draft = $this->insertForm(
            $this->slotFields(),
            ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'],
            ['post_status' => 'draft']
        );

        self::assertSame([], $this->forms->withoutAccountBlock($id, AccountMode::Mandatory));
        $this->save($id, ['account_mode' => 'mandatory']);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Mandatory, $event->accountMode);
        self::assertSame('draft', get_post_status($draft));
    }

    public function testAFormWithoutARevisionIsLeftOutAndTheOthersDecide(): void
    {
        $id = $this->filled();
        $registration = ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'];
        $odd = $this->insertForm($this->slotFields(), $registration);
        foreach (wp_get_post_revisions($odd) as $revision) {
            wp_delete_post_revision($revision->ID);
        }
        self::assertSame([], wp_get_post_revisions($odd));
        $blockless = $this->insertForm($this->slotFields(), $registration);

        self::assertSame([$blockless], $this->forms->withoutAccountBlock($id, AccountMode::Mandatory));

        wp_update_post(['ID' => $blockless, 'post_content' => '<!-- wp:kalaka/account /-->' . $this->slotFields()]);
        $this->save($id, ['account_mode' => 'mandatory']);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Mandatory, $event->accountMode);
    }

    public function testTheUseCaseRefusesAModeChangeWhoseFormsWereNotRead(): void
    {
        $id = $this->filled();

        try {
            $input = ['account_mode' => 'mandatory'] + $this->updateInput($id, $this->versionOf($id));
            $this->exec(new UpdateEvent(), $input);
            self::fail('The mode was accepted without reading the forms.');
        } catch (KalakaException $e) {
            self::assertInstanceOf(SystemFailure::class, $e);
        }
    }

    public function testTheBrokenFormListIsRefreshedAfterAModeChange(): void
    {
        $id = $this->filled();
        $this->insertForm(
            '<!-- wp:kalaka/account /-->' . $this->slotFields(),
            ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration']
        );
        update_option(BrokenForms::OPTION, ['#0 stale: old'], false);

        $this->save($id, ['account_mode' => 'optional']);

        self::assertSame([], BrokenForms::stored());
    }

    public function testTheListOfEventsHoldsEveryState(): void
    {
        $draft = $this->create('Egy');
        $published = $this->published();

        $ids = array_map(static fn ($event): int => $event->id, $this->events->all());

        self::assertContains($draft, $ids);
        self::assertContains($published, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
    }

    public function testPublishingAnIncompleteEventNamesTheMissingFieldsInTheRefusal(): void
    {
        $id = $this->create();
        $this->update($id, ['capacity' => null, 'starts_at' => null, 'ends_at' => null]);

        try {
            $this->transition(new PublishEvent(), $id);
            self::fail('The event was published.');
        } catch (EventNotReady $e) {
            self::assertSame(['capacity', 'starts_at', 'ends_at'], $e->missing);
            self::assertSame((new RuleViolation())->getMessage(), $e->getMessage());
            self::assertSame('kalaka_event_not_ready', $e->errorCode());
            self::assertSame(422, $e->status());
            self::assertSame(['status' => 422], array_intersect_key(
                $e->toWpError()->get_error_data(),
                ['status' => 1]
            ));
        }
    }
}
