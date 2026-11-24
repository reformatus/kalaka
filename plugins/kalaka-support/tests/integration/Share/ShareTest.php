<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Share;

use Kalaka\Aid\Api\Aid;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKindInput;
use Kalaka\Ledger\Api\PayerKindSource;
use Kalaka\Ledger\Api\PayerKinds;
use Kalaka\Ledger\RecordCharge;
use Kalaka\Ledger\RecordCorrection;
use Kalaka\Ledger\RecordDiscount;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Ledger\RecordRefund;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\SetManualConfirmation;
use Kalaka\Registrations\Answer\AnswerRequest;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Support\Module;
use Kalaka\Support\Share\SetShare;
use Kalaka\Support\Share\ShareComponent;
use Kalaka\Tests\Integration\Pricing\ClosureComponent;

/**
 * S29: the congregation share. A fee component at the share stage writes a zero-sum pair of `share` lines,
 * participant -X' and congregation +X', from the registrant's two answers; support composes before it and aid
 * after it; extras never move it; the congregation is its own payer and its part counts for confirmation.
 */
final class ShareTest extends ShareTestCase
{
    /** @return array<string, ParticipantField> */
    private function declared(): array
    {
        $fields = [];
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    public function testTurningItOnDeclaresTheTwoFieldsAndALaterOffKeepsThemAndTheirValues(): void
    {
        self::assertSame([], $this->declared());

        self::assertSame([], $this->applyShare()->failed());
        $fields = $this->declared();
        self::assertSame(['share_mode', 'share_amount'], array_keys($fields));
        self::assertSame('kalaka/choice-field', $fields['share_mode']->type);
        self::assertSame('Gyülekezeti hozzájárulás', $fields['share_mode']->title);
        self::assertSame(
            ['none', 'shared', 'full'],
            array_column($fields['share_mode']->options ?? [], 'value')
        );
        self::assertSame('kalaka/number-field', $fields['share_amount']->type);
        self::assertSame('Gyülekezeti hozzájárulás összege', $fields['share_amount']->title);
        foreach ($fields as $field) {
            self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source);
            self::assertTrue($field->formWritable);
        }
        self::assertTrue($this->share->isOn($this->event));

        // A re-save changes nothing.
        $before = $fields;
        self::assertSame([], $this->applyShare()->failed());
        self::assertEquals($before, $this->declared());

        $key = $this->registerSharing('00A0', 'full');
        self::assertSame(['congregation 44500', 'participant -44500'], $this->shareLines($key));

        // Off: the fields and their values stay, the pair is reversed.
        self::assertSame([], $this->applyShare(false)->failed());
        self::assertFalse($this->share->isOn($this->event));
        self::assertEquals($before, $this->declared());
        self::assertSame([], $this->shareLines($key));
        self::assertSame('44500', $this->balance($key));
        self::assertSame(['share_mode' => 'full'], array_intersect_key(
            (new \Kalaka\Registrations\Api\Registrations())->values($key),
            ['share_mode' => true]
        ));
    }

    public function testAKeyHeldAfterTheProposalRefusesTheWholeApplyAndNothingIsSaved(): void
    {
        $proposed = new ProposedMoneySettings(components: [
            $this->offers->propose($this->event, [self::offer('keret', '8000', limit: 2)]),
            $this->share->propose($this->event, true),
        ]);
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'share_mode',
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'Mód',
            'options' => null,
            'source' => 'staff',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 0,
        ]);

        try {
            $this->settings->apply($this->event, $proposed, new CliActor(null));
            self::fail('The apply should be refused whole.');
        } catch (RuleViolation) {
            self::assertFalse($this->share->isOn($this->event));
            self::assertSame([], $this->offers->offers($this->event));
        }
    }

    public function testAKeyHeldByAnotherFieldRefusesTheProposalAndTheSave(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'share_amount',
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'Összeg',
            'options' => null,
            'settings' => wp_json_encode(['decimals' => 0]),
            'source' => 'form',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
        ]);

        try {
            $this->share->propose($this->event, true);
            self::fail('The proposal should be refused.');
        } catch (RuleViolation) {
            self::assertFalse($this->share->isOn($this->event));
            self::assertArrayNotHasKey('share_mode', $this->declared());
        }
        // The save itself checks too, with nothing declared or saved.
        try {
            $this->runner->run(
                new \Kalaka\Support\Share\SetShare(),
                ['event_id' => $this->event, 'on' => true],
                new CliActor(null)
            );
            self::fail('The save should be refused.');
        } catch (RuleViolation) {
            self::assertFalse($this->share->isOn($this->event));
            self::assertArrayNotHasKey('share_mode', $this->declared());
        }
        // Turning it off needs no free keys.
        self::assertFalse($this->share->propose($this->event, false)->on);
    }

    public function testAFormsFieldOfTheRightTypeIsAdoptedAndTheSharesFieldsAreMoneyRelevantUnderTheModule(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'share_amount',
            'version' => 1,
            'type' => 'kalaka/number-field',
            'title' => 'Összeg',
            'options' => null,
            'settings' => wp_json_encode(['decimals' => 0]),
            'source' => 'form',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
        ]);

        $this->applyShare();

        $declared = $this->declared();
        foreach (['share_mode', 'share_amount'] as $key) {
            self::assertSame(ParticipantField::SOURCE_EXTENSION, $declared[$key]->source);
            self::assertSame('support', $declared[$key]->declaredBy);
            self::assertTrue($declared[$key]->moneyRelevant);
        }
        self::assertSame((new Module())->id(), SetShare::DECLARER);
    }

    public function testAKeyAnotherDeclarerHoldsRefusesTheShare(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'share_mode',
            'version' => 1,
            'type' => 'kalaka/choice-field',
            'title' => 'Mód',
            'options' => wp_json_encode([]),
            'source' => 'extension',
            'declared_by' => 'aid',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 0,
        ]);

        try {
            $this->share->propose($this->event, true);
            self::fail('The proposal should be refused.');
        } catch (RuleViolation) {
            self::assertFalse($this->share->isOn($this->event));
        }
        try {
            $this->runner->run(
                new \Kalaka\Support\Share\SetShare(),
                ['event_id' => $this->event, 'on' => true],
                new CliActor(null)
            );
            self::fail('The save should be refused.');
        } catch (RuleViolation) {
            self::assertFalse($this->share->isOn($this->event));
            self::assertArrayNotHasKey('share_amount', $this->declared());
        }
    }

    public function testAFormsShareModeFieldWithItsOwnOptionsIsAdoptedAndTheExtraOptionIsRetired(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'share_mode',
            'version' => 1,
            'type' => 'kalaka/choice-field',
            'title' => 'Saját mód',
            'options' => wp_json_encode([
                ['value' => 'none', 'label' => 'Saját nem', 'retired_at' => null],
                ['value' => 'shared', 'label' => 'Saját részben', 'retired_at' => null],
                ['value' => 'half', 'label' => 'Saját fél', 'retired_at' => null],
            ]),
            'source' => 'form',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
        ]);

        $this->applyShare();

        $mode = $this->declared()['share_mode'];
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $mode->source);
        self::assertSame('support', $mode->declaredBy);
        $options = array_column($mode->options, null, 'value');
        self::assertTrue($options['half']['retired']);
        foreach (['none', 'shared', 'full'] as $value) {
            self::assertFalse($options[$value]['retired']);
        }
        self::assertSame(
            [
                'none' => \Kalaka\Support\Share\SetShare::NONE_LABEL,
                'shared' => \Kalaka\Support\Share\SetShare::SHARED_LABEL,
                'full' => \Kalaka\Support\Share\SetShare::FULL_LABEL,
            ],
            array_combine(
                ['none', 'shared', 'full'],
                array_map(static fn (string $value): string => $options[$value]['label'], ['none', 'shared', 'full'])
            )
        );
    }

    public function testTheSettingsDocumentShowsAndRefusesOtherShapes(): void
    {
        $component = new ShareComponent();
        self::assertSame(['on' => false], $component->settingsToArray($this->event));
        $this->applyShare();
        self::assertSame(['on' => true], $component->settingsToArray($this->event));

        foreach ([[], ['on' => 'yes'], ['on' => true, 'mode' => 'full'], ['mode' => 'full']] as $shape) {
            try {
                $component->settingsFromArray($this->event, $shape);
                self::fail('The shape should be refused.');
            } catch (InvalidInput) {
                self::assertTrue($this->share->isOn($this->event));
            }
        }
        self::assertTrue($component->settingsFromArray($this->event, ['on' => false])->componentId() === 'share');
    }

    public function testThePreviewShowsEachPayersNowAndWouldWithoutWriting(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        self::assertSame(['congregation 20000', 'participant -20000'], $this->shareLines($key));
        $lines = $this->lineCount();

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [$this->share->propose($this->event, false)])
        );

        self::assertCount(1, $rows);
        self::assertSame('24500', PlainAmount::of($rows[0]->before['participant']));
        self::assertSame('20000', PlainAmount::of($rows[0]->before['congregation']));
        self::assertSame('44500', PlainAmount::of($rows[0]->after['participant']));
        self::assertSame('0', PlainAmount::of($rows[0]->after['congregation'] ?? $this->huf('0')));
        self::assertSame($lines, $this->lineCount());
    }

    public function testNoAnswerNoFieldOrNoneWritesNoPair(): void
    {
        $this->applyShare();

        $unanswered = $this->register('00A0');
        $none = $this->registerSharing('00A2', 'none', '20000');
        $this->evaluateNow($unanswered);

        self::assertSame([], $this->shareLines($unanswered));
        self::assertSame([], $this->shareLines($none));
        self::assertSame('44500', $this->balance($none));
    }

    public function testSharedAndFullWritePairsAndALargeAmountIsCappedAtTheBase(): void
    {
        $this->applyShare();

        $shared = $this->registerSharing('00A0', 'shared', '20000');
        $full = $this->registerSharing('00A2', 'full');
        $capped = $this->registerSharing('00A5', 'shared', '60000');

        self::assertSame(['congregation 20000', 'participant -20000'], $this->shareLines($shared));
        self::assertSame('24500', $this->balance($shared));
        self::assertSame('20000', $this->congregationBalance($shared));
        self::assertSame(['congregation 44500', 'participant -44500'], $this->shareLines($full));
        self::assertSame('0', $this->balance($full));
        self::assertSame(['congregation 44500', 'participant -44500'], $this->shareLines($capped));
    }

    public function testASharedAmountThatIsEmptyZeroOrNotANumberIsNoShare(): void
    {
        $this->applyShare();

        $amounts = ['00A0' => null, '00A2' => '0', '00A5' => '-5', '01A1' => 'sok', '01A3' => '', '01A9' => "20000\n"];
        foreach ($amounts as $code => $amount) {
            $key = $this->registerSharing($code, 'shared', $amount);
            self::assertSame([], $this->shareLines($key), $code);
            self::assertSame('44500', $this->balance($key), $code);
        }
    }

    public function testTheAmountIsReadOnlyWhenTheModeIsShared(): void
    {
        $this->applyShare();

        $none = $this->registerSharing('00A0', 'none', '20000');
        $full = $this->registerSharing('00A2', 'full', '5000');
        $unanswered = $this->registerSharing('00A5', null, '20000');

        self::assertSame([], $this->shareLines($none));
        self::assertSame(['congregation 44500', 'participant -44500'], $this->shareLines($full));
        self::assertSame([], $this->shareLines($unanswered));
    }

    public function testSupportLowersTheParticipantsPartFirstAndTheCongregationsOnlyBelowZero(): void
    {
        $this->setPrice('34500');
        $this->applyOffers([self::offer('seg1', '10000'), self::offer('seg2', '15000')]);
        $this->applyShare();

        // Shared 20 000 of 34 500, offer 10 000: the participant's part drops, X' is unchanged.
        $shared = $this->registerSharing('00A0', 'shared', '20000');
        $this->writeValue($shared, 'seg1', '1');
        self::assertSame(['congregation 20000', 'participant -20000'], $this->shareLines($shared));
        self::assertSame('4500', $this->balance($shared));

        // Full 34 500, offer 15 000: the congregation pays 19 500.
        $full = $this->registerSharing('00A2', 'full');
        $this->writeValue($full, 'seg2', '1');
        self::assertSame(['congregation 19500', 'participant -19500'], $this->shareLines($full));
        self::assertSame('0', $this->balance($full));
        self::assertSame('19500', $this->congregationBalance($full));

        // Shared 20 000 with both offers (25 000 of support): only 9 500 remain to share.
        $both = $this->registerSharing('00A5', 'shared', '20000');
        $this->writeValue($both, 'seg1', '1');
        $this->writeValue($both, 'seg2', '1');
        self::assertSame(['congregation 9500', 'participant -9500'], $this->shareLines($both));
        self::assertSame('0', $this->balance($both));
    }

    public function testAnOfferApprovedLaterShrinksTheCongregationsLineAndLeavesItOverpaid(): void
    {
        $this->setPrice('34500');
        $this->applyOffers([self::offer('seg1', '15000', true)]);
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'full');
        $this->writeValue($key, 'seg1', '1');
        self::assertSame(['congregation 34500', 'participant -34500'], $this->shareLines($key));
        $this->pay($key, '34500', ShareComponent::CONGREGATION);

        $this->answer($key, 'seg1', 'yes');

        self::assertSame(['congregation 19500', 'participant -19500'], $this->shareLines($key));
        self::assertSame('-15000', $this->congregationBalance($key));
        self::assertSame('0', $this->balance($key));
    }

    public function testAidIsCappedAtTheParticipantsPartAfterTheShareAndNeverTouchesTheCongregation(): void
    {
        $aid = new Aid();
        $proposed = new ProposedMoneySettings(components: [$aid->propose($this->event, 'Teszt ösztöndíj')]);
        self::assertSame([], $this->settings->apply($this->event, $proposed, new CliActor(null))->failed());
        $this->applyShare();

        // Shared 30 000 of 44 500: the participant's part is 14 500, and 20 000 of aid is capped there.
        $key = $this->registerSharing('00A0', 'shared', '30000');
        $this->writeValue($key, 'aid_can_pay', '5000');
        $this->runner->run(new AnswerRequest(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'request' => 'aid',
            'answer' => 'yes',
            'amount' => '20000',
        ], new UserActor($this->fullAccessUser()));

        self::assertSame(['congregation 30000', 'participant -30000'], $this->shareLines($key));
        self::assertSame('0', $this->balance($key));
        self::assertSame('30000', $this->congregationBalance($key));
    }

    public function testExtrasAndManualFeesAreNeverCoveredByTheShare(): void
    {
        $extras = new ClosureComponent(
            'teszt_extra',
            FeeStage::Extras,
            fn () => new FeeTargets(['participant' => $this->huf('8000')])
        );
        $this->useComponents($extras);
        $this->applyShare();

        $key = $this->registerSharing('00A0', 'full');

        self::assertSame(['congregation 44500', 'participant -44500'], $this->shareLines($key));
        self::assertSame('8000', $this->balance($key));
    }

    public function testOnlyARegisteredRegistrationHasAPairAndWithdrawalReversesItKeepingThePayments(): void
    {
        $this->applyShare();
        $waitlisted = $this->register('00A0', RegistrationStatus::Waitlisted);
        $this->answerShare($waitlisted, 'full');
        self::assertSame([], $this->shareLines($waitlisted));

        $this->setStatus($waitlisted, RegistrationStatus::Registered);
        self::assertSame(['congregation 44500', 'participant -44500'], $this->shareLines($waitlisted));

        $this->pay($waitlisted, '44500', ShareComponent::CONGREGATION);
        $this->setStatus($waitlisted, RegistrationStatus::Withdrawn);
        self::assertSame([], $this->shareLines($waitlisted));
        self::assertSame('-44500', $this->congregationBalance($waitlisted));
    }

    public function testTurningTheShareOffReversesEveryPair(): void
    {
        $this->applyShare();
        $one = $this->registerSharing('00A0', 'shared', '20000');
        $two = $this->registerSharing('00A2', 'full');

        self::assertSame([], $this->applyShare(false)->failed());

        foreach ([$one, $two] as $key) {
            self::assertSame([], $this->shareLines($key));
            self::assertSame('44500', $this->balance($key));
            self::assertSame('0', $this->congregationBalance($key));
        }
    }

    public function testConfirmationNeedsTheCongregationsPartAndALaterDropNeverUnconfirms(): void
    {
        $this->setPrice('34500');
        $this->applyOffers([self::offer('seg1', '15000', true)]);
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        $this->writeValue($key, 'seg1', '1');

        // The participant pays their part (14 500), the congregation has not paid its 20 000.
        $this->payIn($key, '14500');
        self::assertNotSame('confirmed', $this->confirmationState($key));

        $this->pay($key, '20000', ShareComponent::CONGREGATION);
        $this->evaluateNow($key);
        self::assertSame('confirmed', $this->confirmationState($key));

        // An approved offer lowers the participant's part: still confirmed.
        $this->answer($key, 'seg1', 'yes');
        $this->evaluateNow($key);
        self::assertSame('confirmed', $this->confirmationState($key));
    }

    public function testTheManualFlagCoversALateCongregation(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        $this->payIn($key, '24500');
        self::assertNotSame('confirmed', $this->confirmationState($key));

        $this->runner->run(
            new SetManualConfirmation(),
            ['event_id' => $key->eventId, 'code' => $key->code, 'reason' => 'Teszt indok'],
            new CliActor(null)
        );

        self::assertSame('confirmed', $this->confirmationState($key));
    }

    /** @return array<string, mixed> */
    private function congregationEntry(RegistrationKey $key, string $amount, string $id): array
    {
        $result = $this->runner->run(new RecordPayment(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'payer' => 'congregation',
            'amount' => $amount,
            'currency' => 'HUF',
            'method' => 'bank_transfer',
            'value_date' => '2026-11-29',
            'idempotency_key' => $id,
        ], new UserActor($this->fullAccessUser()));

        return is_array($result) ? $result : [];
    }

    public function testTheCongregationIsAPayerKindOnlyWhereTheShareIsOnAndRecordingAgainstItIsRefusedElsewhere(): void
    {
        $key = $this->register('00A0');
        // The share is off: the kind does not apply, and nobody may record against it.
        self::assertSame(['participant'], array_map(
            static fn ($kind): string => $kind->id,
            (new PayerKinds())->of($this->event)
        ));
        try {
            $this->congregationEntry($key, '1000', 'k_gyul_off');
            self::fail('A congregation payment should be refused where the share is off.');
        } catch (InvalidInput) {
            self::assertSame('0', $this->congregationBalance($key));
        }

        $this->applyShare();
        self::assertSame(['participant', 'congregation'], array_map(
            static fn ($kind): string => $kind->id,
            (new PayerKinds())->of($this->event)
        ));
        self::assertSame('Gyülekezet', (new PayerKinds())->label('congregation'));
    }

    public function testACongregationPaymentIsCreditedToTheCongregationAlone(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        $other = $this->register('01A6');
        $before = [$this->balance($other), $this->congregationBalance($other)];

        $this->congregationEntry($key, '20000', 'k_gyul_1');

        self::assertSame('0', $this->congregationBalance($key));
        self::assertSame('24500', $this->balance($key));
        self::assertSame($before, [$this->balance($other), $this->congregationBalance($other)]);
    }

    public function testAPayerWithLinesStaysVisibleAfterTheShareIsSwitchedOff(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        $this->congregationEntry($key, '20000', 'k_gyul_2');

        $this->applyShare(false);

        $money = (new \Kalaka\Admin\Api\RegistrationDetail())
            ->of(new \Kalaka\Foundation\Api\Actor\SystemActor(), $this->event, '00A0')['money'];
        $payers = array_column($money['payers'], 'label', 'payer');
        self::assertSame(['participant', 'congregation'], array_keys($payers));
        self::assertSame('Gyülekezet', $payers['congregation']);
        self::assertSame(['participant'], array_map(
            static fn ($kind): string => $kind->id,
            (new PayerKinds())->of($this->event)
        ));
    }

    public function testAPayerWithLinesIsStillAcceptedForEveryEntryAfterTheShareIsOffAndOneWithoutLinesIsRefused(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        $this->congregationEntry($key, '20000', 'k_gyul_4');
        $other = $this->register('01A6');
        $this->applyShare(false);
        $actor = new UserActor($this->fullAccessUser());

        // What the money tab shows (the congregation, with lines) is what the server accepts.
        $money = (new \Kalaka\Admin\Api\RegistrationDetail())->of($actor, $this->event, '00A0')['money'];
        self::assertSame(['participant', 'congregation'], array_column($money['payers'], 'payer'));
        $this->runner->run(new RecordRefund(), [
            'event_id' => $key->eventId, 'code' => $key->code, 'payer' => 'congregation', 'amount' => '5000',
            'currency' => 'HUF', 'method' => 'bank_transfer', 'value_date' => '2026-11-30',
            'idempotency_key' => 'k_gyul_refund',
        ], $actor);
        $this->runner->run(new RecordCorrection(), [
            'event_id' => $key->eventId, 'code' => $key->code, 'payer' => 'congregation', 'amount' => '1000',
            'currency' => 'HUF', 'direction' => 'in', 'reason' => 'Teszt indok', 'idempotency_key' => 'k_gyul_corr',
        ], $actor);
        self::assertSame('-16000', $this->congregationBalance($key));
        // A new payment on the same registration is accepted too: the payer has lines here.
        $this->congregationEntry($key, '2000', 'k_gyul_again');
        self::assertSame('-18000', $this->congregationBalance($key));
        // So are a new Díj and Kedvezmény: one rule for the whole dialog (RecordManualFee too).
        foreach ([[new RecordCharge(), 'k_gyul_fee'], [new RecordDiscount(), 'k_gyul_disc']] as [$useCase, $id]) {
            $this->runner->run($useCase, [
                'event_id' => $key->eventId, 'code' => $key->code, 'payer' => 'congregation', 'amount' => '1000',
                'currency' => 'HUF', 'title' => 'Teszt tétel', 'idempotency_key' => $id,
            ], $actor);
        }

        // A congregation with no lines on another registration is refused for every entry.
        try {
            $this->congregationEntry($other, '1000', 'k_gyul_5');
            self::fail('A payment to a payer without lines should be refused.');
        } catch (InvalidInput) {
            self::assertSame('0', $this->congregationBalance($other));
        }
        try {
            $this->runner->run(new RecordCharge(), [
                'event_id' => $other->eventId, 'code' => $other->code, 'payer' => 'congregation', 'amount' => '1000',
                'currency' => 'HUF', 'title' => 'Teszt díj', 'idempotency_key' => 'k_gyul_fee2',
            ], $actor);
            self::fail('A fee against a payer without lines should be refused.');
        } catch (InvalidInput) {
            self::addToAssertionCount(1);
        }
    }

    public function testAThrowingPayerKindSourceIsContainedInTheDetailReadAndRollsBackARecordUseCase(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');
        $before = Contributions::current();
        $with = clone $before;
        $with->payerKindSource(new class implements PayerKindSource {
            public function kind(): PayerKind
            {
                return new PayerKind('sponsor', 'Támogató');
            }

            public function appliesTo(PayerKindInput $input): bool
            {
                throw new \RuntimeException('The source is broken.');
            }
        });
        Contributions::record($with);
        $actor = new UserActor($this->fullAccessUser());

        try {
            // The read (outside a transaction) loads without the broken kind: it is logged and not applying.
            $money = (new \Kalaka\Admin\Api\RegistrationDetail())->of($actor, $this->event, '00A0')['money'];
            self::assertSame(['participant', 'congregation'], array_column($money['payers'], 'payer'));

            // A record use case is inside the transaction: the throw propagates and nothing is recorded.
            try {
                $this->congregationEntry($key, '1000', 'k_gyul_broken');
                self::fail('The use case should fail.');
            } catch (\Throwable) {
                self::assertSame('20000', $this->congregationBalance($key));
            }
        } finally {
            Contributions::record($before);
        }
    }

    public function testAMissingRegistrationIsNotFoundWhateverThePayerIs(): void
    {
        $actor = new UserActor($this->fullAccessUser());

        foreach (['participant', 'congregation'] as $payer) {
            try {
                $this->runner->run(new RecordPayment(), [
                    'event_id' => $this->event, 'code' => '02A2', 'payer' => $payer, 'amount' => '1000',
                    'currency' => 'HUF', 'method' => 'bank_transfer', 'value_date' => '2026-11-29',
                    'idempotency_key' => 'k_none_' . $payer,
                ], $actor);
                self::fail('The registration does not exist.');
            } catch (NotFound) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function setType(string $key, string $type): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['type' => $type],
            ['event_id' => $this->event, 'field_key' => $key]
        );
    }

    public function testAFieldThatIsNotOfTheDeclaredTypeOrAHugeAmountGivesNoPair(): void
    {
        $this->applyShare();

        // A 13-digit amount is not an amount.
        $huge = $this->registerSharing('00A0', 'shared', '1000000000000');
        self::assertSame([], $this->shareLines($huge));
        self::assertSame('44500', $this->balance($huge));

        // A form's field of that key that is not a choice (or not a number) is not read.
        $this->setType('share_mode', 'kalaka/text-field');
        $text = $this->registerSharing('00A2', 'full');
        self::assertSame([], $this->shareLines($text));
        $this->setType('share_mode', 'kalaka/choice-field');
        $this->setType('share_amount', 'kalaka/text-field');
        $amount = $this->registerSharing('00A5', 'shared', '20000');
        self::assertSame([], $this->shareLines($amount));
    }

    public function testBothLinesOfThePairAreNamedAlike(): void
    {
        $this->applyShare();
        $key = $this->registerSharing('00A0', 'shared', '20000');

        $sources = [];
        global $wpdb;
        foreach (
            (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT source_key FROM %i WHERE event_id = %d AND code = %s AND kind = 'charge'",
                $wpdb->prefix . 'kalaka_ledger_lines',
                $key->eventId,
                $key->code
            )) as $source
        ) {
            $sources[] = new \Kalaka\Foundation\Api\Audit\Subject(
                \Kalaka\Pricing\Api\Materializer::SOURCE,
                null,
                (string) $source
            );
        }
        $names = (new LineNames())->of($key, $sources);

        self::assertSame('Gyülekezeti hozzájárulás', $names['share']->label);
        self::assertSame('component', $names['share']->type);
        self::assertSame(FeeStage::Share, $names['share']->stage);
    }

    public function testSupportShareAndAidApplyTogetherFromOneSettingsDocument(): void
    {
        $document = [
            'support' => ['offers' => [self::offer('seg1', '10000')]],
            'share' => ['on' => true],
            'aid' => ['label' => 'Teszt ösztöndíj'],
        ];
        $components = [];
        foreach ((new \Kalaka\Pricing\CoreFeeComponents())->list() as $component) {
            if (
                $component instanceof \Kalaka\Pricing\Api\ConfigurableFeeComponent
                && isset($document[$component->id()])
            ) {
                $components[] = $component->settingsFromArray($this->event, $document[$component->id()]);
            }
        }
        self::assertCount(3, $components);

        $proposed = new ProposedMoneySettings(components: $components);
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));

        self::assertSame([], $report->failed());
        self::assertTrue($this->share->isOn($this->event));
        self::assertArrayHasKey('share_mode', $this->declared());
        self::assertArrayHasKey('aid_can_pay', $this->declared());
        self::assertArrayHasKey('seg1', $this->declared());
    }

    public function testAFormMayOfferASubsetOfTheModesButNeverAnAddedOne(): void
    {
        (new \Kalaka\Forms\Module())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
        $this->applyShare();
        $save = function (array $options): \WP_REST_Response {
            $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
            $request->set_body_params([
                'title' => 'Pótlap',
                'status' => 'draft',
                'content' => serialize_block([
                    'blockName' => 'kalaka/choice-field',
                    'attrs' => ['key' => 'share_mode', 'label' => 'Hozzájárulás', 'options' => $options],
                    'innerBlocks' => [],
                    'innerHTML' => '',
                    'innerContent' => [],
                ]),
                'meta' => ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'additional'],
            ]);
            $response = rest_do_request($request);
            self::assertInstanceOf(\WP_REST_Response::class, $response);

            return $response;
        };

        $subset = $save([['value' => 'none', 'label' => 'Nem'], ['value' => 'shared', 'label' => 'Részben']]);
        self::assertSame(201, $subset->get_status());

        $added = $save([['value' => 'none', 'label' => 'Nem'], ['value' => 'half', 'label' => 'Fele']]);
        self::assertSame(400, $added->get_status());
        self::assertStringContainsString('extension_option', (string) ($added->get_data()['message'] ?? ''));
    }
}
