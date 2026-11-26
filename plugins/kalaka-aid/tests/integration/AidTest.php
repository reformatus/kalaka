<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Aid;

use Kalaka\Aid\AidComponent;
use Kalaka\Aid\AidRequests;
use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Tests\Integration\Pricing\ClosureComponent;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Portal\Api\MoneyRead;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Pricing\Cli\SettingsDocument;
use Kalaka\Aid\Module;
use Kalaka\Aid\SetAid;
use Kalaka\Registrations\Api\Registrations;

/**
 * S32 on the topic-15 model: the aid request exists while `aid_can_pay` holds a value; the one answer act writes
 * the award `aid_award` through the aid's request source; the award is one participant-side `aid:award` line,
 * capped at what the participant still owes before the extras; awaiting an answer gives no line and no due date.
 */
final class AidTest extends AidTestCase
{
    public function testAnAidRequestAwaitsItsAnswerWithNoLineAndTheFullPriceDue(): void
    {
        self::assertSame([], $this->applyAid()->failed());

        $key = $this->registerAsking('00A0', '10000');

        self::assertTrue($this->awaits($key));
        self::assertSame([], $this->aidLines($key));
        self::assertSame('30000', $this->balance($key));
        self::assertArrayNotHasKey('aid_award', (new Registrations())->values($key));
    }

    public function testNoStatedAmountOrNoAidOnTheEventIsNoRequest(): void
    {
        $this->applyAid();
        $without = $this->register('00A0');
        self::assertFalse($this->awaits($without));

        $source = new AidRequests();
        $asked = $source->requestsOf($without, ['aid_can_pay' => '1000']);
        self::assertSame(['aid'], array_map(static fn ($request): string => $request->id, $asked));
        self::assertSame('Teszt ösztöndíj', $asked[0]->label);
        self::assertSame(['aid_can_pay'], $asked[0]->askFields);
        self::assertSame('aid_award', $asked[0]->answerField);
        self::assertSame([], $source->requestsOf($without, ['aid_can_pay' => '']));

        // An event without aid: a stated amount is not a request.
        $this->applyAid(null);
        self::assertSame([], $source->requestsOf($without, ['aid_can_pay' => '1000']));
    }

    public function testAYesWithAnAmountWritesTheAwardAndOneLineForTheParticipant(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        $result = $this->answer($key, 'yes', '12000');

        self::assertTrue($result['changed']);
        self::assertFalse($this->awaits($key));
        self::assertSame('12000', (new Registrations())->values($key)['aid_award']);
        self::assertSame(['aid:award participant -12000'], $this->aidLines($key));
        self::assertSame('18000', $this->balance($key));
    }

    public function testAnAwardAboveTheParticipantsPartIsCappedAndTheExtrasComeAfterIt(): void
    {
        $this->applyAid();
        $this->declareNumberField('adomany');
        $key = $this->registerAsking('00A0', '0');
        $this->writeValue($key, 'adomany', '5000');

        $this->answer($key, 'yes', '50000');

        self::assertSame('50000', (new Registrations())->values($key)['aid_award']);
        self::assertSame(['aid:award participant -30000'], $this->aidLines($key));
        // The donation is an extra: the aid never pays for it.
        self::assertSame('5000', $this->balance($key));
    }

    public function testANoIsAnAwardOfZeroAndWritesNoLine(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '30000');

        $this->answer($key, 'no');

        self::assertFalse($this->awaits($key));
        self::assertSame('0', (new Registrations())->values($key)['aid_award']);
        self::assertSame([], $this->aidLines($key));
        self::assertSame('30000', $this->balance($key));
    }

    public function testAYesNeedsAnAmountAboveZero(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        foreach ([null, '0', 'abc', '-5'] as $amount) {
            try {
                $this->answer($key, 'yes', $amount);
                self::fail('Accepted: ' . var_export($amount, true));
            } catch (InvalidInput) {
                self::assertTrue($this->awaits($key));
            }
        }
        self::assertSame([], $this->aidLines($key));
    }

    public function testTheCapReadsThePartAfterSupportAndShareAndLeavesTheCongregationsAmountAlone(): void
    {
        $this->useComponents(
            new ClosureComponent('support_probe', FeeStage::Support, static fn (): FeeTargets => FeeTargets::ofItems(
                ['standin' => [Payer::PARTICIPANT => Money::of('-10000', Currency::HUF)]]
            )),
            new ClosureComponent('share_probe', FeeStage::Share, static fn (): FeeTargets => FeeTargets::ofItems(
                ['standin' => [
                    Payer::PARTICIPANT => Money::of('-4000', Currency::HUF),
                    'congregation' => Money::of('4000', Currency::HUF),
                ]]
            )),
        );
        $this->applyAid();
        $key = $this->registerAsking('00A0', '0');

        $this->answer($key, 'yes', '50000');

        // 30 000 less support 10 000 and share 4 000 leaves 16 000 for the aid to take.
        self::assertSame(['aid:award participant -16000'], $this->aidLines($key));
        self::assertContains('share_probe:standin congregation 4000', array_map(
            static fn (string $line): string => implode(' ', array_slice(explode(' ', $line), 0, 3)),
            $this->charges($key)
        ));
        self::assertSame('0', $this->balance($key));
    }

    public function testAnAwardTheNumberFieldCannotHoldIsRefusedAndTheRequestStaysOpen(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        try {
            $this->answer($key, 'yes', '1000000000000');
            self::fail('Accepted.');
        } catch (InvalidInput) {
            self::assertTrue($this->awaits($key));
            self::assertArrayNotHasKey('aid_award', (new Registrations())->values($key));
        }

        $this->answer($key, 'yes', '999999999999');
        self::assertSame(['aid:award participant -30000'], $this->aidLines($key));
    }

    public function testTheRequestSourceRefusesAYesWithoutAnAmountAboveZeroOnItsOwn(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        $request = new Request(AidRequests::ID, RequestKind::Aid, 'Teszt ösztöndíj');
        $context = (new \ReflectionClass(Context::class))->newInstanceWithoutConstructor();
        $source = new AidRequests();

        foreach ([null, Money::zero(Currency::HUF)] as $amount) {
            try {
                $source->writeAnswer($key, $request, new Answer(true, $amount), $context);
                self::fail('Accepted.');
            } catch (InvalidInput) {
                self::assertArrayNotHasKey('aid_award', (new Registrations())->values($key));
            }
        }
    }

    public function testThePreviewShowsTheProposedAidForAnAnsweredRegistration(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        $this->answer($key, 'yes', '12000');
        $this->applyAid(null);
        self::assertSame('30000', $this->balance($key));

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [$this->aid->propose($this->event, 'Teszt ösztöndíj')])
        );

        self::assertCount(1, $rows);
        self::assertSame('30000', PlainAmount::of($rows[0]->before[Payer::PARTICIPANT]));
        self::assertSame('18000', PlainAmount::of($rows[0]->after[Payer::PARTICIPANT]));
    }

    public function testAChangedAnswerReprices(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        $this->answer($key, 'yes', '12000');
        self::assertSame('18000', $this->balance($key));

        $this->answer($key, 'yes', '20000');
        self::assertSame(['aid:award participant -20000'], $this->aidLines($key));
        self::assertSame('10000', $this->balance($key));

        $this->answer($key, 'no');
        self::assertSame([], $this->aidLines($key));
        self::assertSame('30000', $this->balance($key));
        self::assertFalse($this->awaits($key));

        $this->answer($key, 'yes', '1000');
        self::assertSame('29000', $this->balance($key));
    }

    public function testWhileTheRequestAwaitsNoDueDateIsShownAndTheAnswerBringsItBack(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['payment_due_days' => 7], ['id' => $this->event]);
        $read = new StatusRead();

        $awaiting = $read->of($key)?->payment;
        self::assertTrue($awaiting?->awaitsAnswer);
        self::assertNull($awaiting->paymentDue);

        $this->answer($key, 'yes', '10000');

        $answered = $read->of($key)?->payment;
        self::assertFalse($answered?->awaitsAnswer);
        self::assertNotNull($answered->paymentDue);
    }

    public function testTheAnswerConfirmsTheRegistrationInTheSamePassWhenItReachesTheTarget(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        $this->payIn($key, '20000');

        // While the aid awaits its answer the full price stands, so 20 000 of 30 000 is not enough.
        self::assertNotSame('confirmed', $this->confirmationState($key));

        $this->answer($key, 'yes', '10000');

        self::assertSame('confirmed', $this->confirmationState($key));
        self::assertSame('0', $this->balance($key));
    }

    public function testTurningTheAidOffTakesTheLineAwayAtTheApplyAndKeepsTheAnswer(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        $this->answer($key, 'yes', '10000');
        self::assertSame('20000', $this->balance($key));

        self::assertSame([], $this->applyAid(null)->failed());

        self::assertNull($this->aid->label($this->event));
        self::assertSame('30000', $this->balance($key));
        self::assertFalse($this->awaits($key));
        self::assertSame('10000', (new Registrations())->values($key)['aid_award']);
    }

    public function testTurningTheAidOffReleasesAnUnansweredRequestAndTurningItOnAwaitsItAgain(): void
    {
        $this->applyAid();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['payment_due_days' => 7], ['id' => $this->event]);
        $waiting = $this->registerAsking('00A0', '10000');
        $answered = $this->registerAsking('00A2', '10000');
        $this->answer($answered, 'yes', '10000');
        $read = new StatusRead();
        self::assertTrue($this->awaits($waiting));
        self::assertNull($read->of($waiting)?->payment->paymentDue);

        $this->applyAid(null);

        // The request stops awaiting at once: the full price and the due date from the registration apply.
        self::assertFalse($this->awaits($waiting));
        self::assertSame('30000', $this->balance($waiting));
        self::assertNotNull($read->of($waiting)?->payment->paymentDue);
        self::assertSame('30000', $this->balance($answered));

        $this->applyAid();

        // The stored answer's line is back, and the unanswered request awaits again.
        self::assertTrue($this->awaits($waiting));
        self::assertNull($read->of($waiting)?->payment->paymentDue);
        self::assertSame(['aid:award participant -10000'], $this->aidLines($answered));
        self::assertSame('20000', $this->balance($answered));
    }

    public function testTheSaveDeclaresTheTwoFieldsOnlyTheRequestFormWritable(): void
    {
        $this->applyAid();

        $fields = [];
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            $fields[$field->key] = $field;
        }
        foreach ([AidRequests::CAN_PAY_FIELD => true, AidComponent::AWARD_FIELD => false] as $key => $writable) {
            self::assertArrayHasKey($key, $fields);
            self::assertSame(ParticipantField::SOURCE_EXTENSION, $fields[$key]->source);
            self::assertSame('kalaka/number-field', $fields[$key]->type);
            self::assertSame($writable, $fields[$key]->formWritable);
        }
        self::assertSame('Teszt ösztöndíj', $this->aid->label($this->event));
    }

    public function testAKeyAnotherFieldHoldsIsRefusedWhileTheAidIsProposedByTheOneDeclarableCheck(): void
    {
        $this->declareParticipantField($this->event, 'aid_award');

        try {
            $this->aid->propose($this->event, 'Teszt ösztöndíj');
            self::fail('Accepted.');
        } catch (RuleViolation) {
            self::assertNull($this->aid->label($this->event));
        }
    }

    public function testAKeyAnotherDeclarerHoldsIsRefusedAndAFormsNumberFieldIsAdopted(): void
    {
        global $wpdb;
        $row = [
            'event_id' => $this->event,
            'version' => 1,
            'type' => 'kalaka/number-field',
            'title' => 'Teszt mező',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 0,
            'form_writable' => 0,
        ];
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', $row + [
            'field_key' => 'aid_award',
            'source' => 'extension',
            'declared_by' => 'support',
        ]);
        try {
            $this->aid->propose($this->event, 'Teszt ösztöndíj');
            self::fail('Accepted.');
        } catch (RuleViolation) {
            self::assertNull($this->aid->label($this->event));
        }
        $table = $wpdb->prefix . 'kalaka_participant_fields';
        $wpdb->delete($table, ['event_id' => $this->event, 'field_key' => 'aid_award']);

        $wpdb->insert($table, $row + ['field_key' => 'aid_can_pay', 'source' => 'form']);
        $this->applyAid();

        $fields = [];
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            $fields[$field->key] = $field;
        }
        foreach ([AidRequests::CAN_PAY_FIELD, AidComponent::AWARD_FIELD] as $key) {
            self::assertSame(ParticipantField::SOURCE_EXTENSION, $fields[$key]->source);
            self::assertSame('aid', $fields[$key]->declaredBy);
            self::assertTrue($fields[$key]->moneyRelevant);
        }
        self::assertSame((new Module())->id(), SetAid::DECLARER);
    }

    public function testTheAwardFieldIsTheTruthEmptyAwaitsZeroIsRefusedAnAmountIsGranted(): void
    {
        $source = new AidRequests();
        $request = new Request(AidRequests::ID, RequestKind::Aid, 'Teszt ösztöndíj');

        self::assertFalse($source->isAnswered($request, ['aid_can_pay' => '10000']));
        self::assertFalse($source->isAnswered($request, ['aid_can_pay' => '10000', 'aid_award' => '']));
        self::assertTrue($source->isAnswered($request, ['aid_can_pay' => '10000', 'aid_award' => '0']));
        self::assertTrue($source->isAnswered($request, ['aid_can_pay' => '10000', 'aid_award' => '12000']));
    }

    public function testAnotherDeclarerCannotWriteTheAwardField(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        try {
            $this->inRun(function (array $input, Context $context) use ($key): void {
                $this->lockRegistrations($context, $key);
                (new Registrations())->writeValue($key, 'aid_award', '5', $context, 'support');
            });
            self::fail('Another declarer wrote the award.');
        } catch (SystemFailure) {
            self::assertArrayNotHasKey('aid_award', (new Registrations())->values($key));
        }
    }

    public function testTheAidLineIsNamedByTheEventsAidLabelInBothMoneyReads(): void
    {
        $this->applyAid('Teszt ösztöndíj');
        $key = $this->registerAsking('00A0', '10000');
        $this->answer($key, 'yes', '12000');

        $names = new LineNames();
        $owed = (new MoneyRead(new Ledger(), $names))->of($key, Currency::HUF)->owed;
        $labels = array_map(static fn ($row): string => $row->label, $owed);
        self::assertContains('Teszt ösztöndíj', $labels);
        self::assertNotContains('Díj', $labels);

        $detail = new RegistrationDetail(lineNames: $names);
        $lines = $detail->of(new SystemActor(), $this->event, '00A0')['money']['lines'];
        $aid = array_values(array_filter($lines, static fn (array $line): bool => $line['amount'] < 0));
        self::assertNotSame([], $aid);
        foreach ($aid as $line) {
            self::assertSame('Teszt ösztöndíj', $line['kind_label']);
            self::assertSame('component', $line['type']);
        }
    }

    public function testTheLabelKedvezmenyIsRefusedInAnyCaseAtTheProposalAndTheSave(): void
    {
        foreach (['Kedvezmény', ' kedvezmény ', 'KEDVEZMÉNY'] as $label) {
            try {
                $this->aid->propose($this->event, $label);
                self::fail('Proposed.');
            } catch (InvalidInput) {
                self::addToAssertionCount(1);
            }
            try {
                $this->runner->run(new SetAid(), ['event_id' => $this->event, 'label' => $label], new CliActor(null));
                self::fail('Saved.');
            } catch (InvalidInput) {
                self::assertNull($this->aid->label($this->event));
            }
        }
        self::assertSame('Kedvezményes', $this->aid->propose($this->event, 'Kedvezményes')->label);
    }

    public function testALabelOutsideOneTo191CharactersIsRefused(): void
    {
        foreach (['', '   ', str_repeat('á', 192)] as $label) {
            try {
                $this->aid->propose($this->event, $label);
                self::fail('Accepted.');
            } catch (InvalidInput) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheModuleContributesItsComponentAndSource(): void
    {
        $contributions = Contributions::current();

        self::assertContains(AidComponent::ID, array_map(
            static fn ($component): string => $component->id(),
            $contributions->feeComponents()
        ));
        self::assertNotSame([], array_filter(
            $contributions->requestSources(),
            static fn ($source): bool => $source instanceof AidRequests
        ));
    }

    public function testTheAidIsPartOfOneApplyThroughTheSettingsDocument(): void
    {
        $document = new SettingsDocument();
        $proposed = $document->proposed($this->event, [
            'fixed_price' => '25000',
            'components' => ['aid' => ['label' => 'Ösztöndíj']],
        ]);
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));
        self::assertSame([], $report->failed());

        $shown = $document->show($this->event);
        self::assertSame(['label' => 'Ösztöndíj'], $shown['components']['aid']);
        $key = $this->registerAsking('00A0', '5000');
        $this->answer($key, 'yes', '5000');
        self::assertSame('20000', $this->balance($key));
    }
}
