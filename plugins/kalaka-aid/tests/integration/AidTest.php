<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Aid;

use Kalaka\Aid\AidComponent;
use Kalaka\Aid\AidRequests;
use Kalaka\Aid\Api\AidInUse;
use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComposition;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\AidAbovePrice;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Pricing\ClosureComponent;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Portal\Api\MoneyRead;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Pricing\Cli\SettingsDocument;
use Kalaka\Aid\Module;
use Kalaka\Aid\SetAid;

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

    /** @return list<string> the codes core's compiled condition says await an answer */
    private function awaitingBySql(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kalaka_field_values';
        [$condition, $args] = (new Requests([new AidRequests()]))->awaitingCondition($this->event, $table);
        $sql = 'SELECT r.code FROM %i r WHERE r.event_id = %d AND (' . $condition . ') ORDER BY r.code';

        return array_map('strval', $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test inspection; the text is the table placeholders and the condition's own placeholders
            $wpdb->prepare($sql, $wpdb->prefix . 'kalaka_registrations', $this->event, ...$args)
        ));
    }

    public function testTheAwaitingRuleMeansWhatTheRequestSourceMeans(): void
    {
        $this->applyAid();
        $this->registerAsking('00A0', '1000');
        $this->registerAsking('00A2', '2000');
        $this->answer($this->registerAsking('00A5', '3000'), 'yes', '2500');
        $this->answer($this->registerAsking('00A8', '4000'), 'no');
        $emptied = $this->registerAsking('00B0', '5000');
        $this->answer($emptied, 'yes', '1500');
        $this->writeValue($emptied, 'aid_can_pay', '');
        $this->registerAsking('00B3', '');
        $this->register('00B6');
        $awardOnly = $this->register('00B9');
        $this->writeValue($awardOnly, 'aid_award', '0');
        $this->writeValue($this->register('00E2', RegistrationStatus::Waitlisted), 'aid_can_pay', '1000');
        $this->writeValue($this->register('00E5', RegistrationStatus::Withdrawn), 'aid_can_pay', '1000');

        $bySql = $this->awaitingBySql();

        self::assertSame(['00A0', '00A2'], $bySql);
        $requests = new Requests([new AidRequests()]);
        $byStates = [];
        $byRule = [];
        $rule = (new AidRequests())->awaitingRule($this->event) ?? throw new \LogicException();
        foreach (['00A0', '00A2', '00A5', '00A8', '00B0', '00B3', '00B6', '00B9', '00E2', '00E5'] as $code) {
            $key = new RegistrationKey($this->event, $code);
            $status = (new Registrations())->find($key)->status ?? throw new \LogicException();
            if ($status === RegistrationStatus::Registered && $requests->awaits($key, $status)) {
                $byStates[] = $code;
            }
            if ($status === RegistrationStatus::Registered && $rule->matches((new Registrations())->values($key))) {
                $byRule[] = $code;
            }
        }
        self::assertSame($byStates, $bySql);
        self::assertSame($byRule, $bySql);
    }

    public function testAnEventWithoutAidHasNoAwaitingRule(): void
    {
        $this->applyAid(null);

        self::assertNull((new AidRequests())->awaitingRule($this->event));
    }

    public function testAnEmptiedCanPayAfterAnAwardLeavesTheRequestTheLineAndTheAnswerAct(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        $this->answer($key, 'yes', '12000');

        $this->writeValue($key, 'aid_can_pay', '');

        $values = (new Registrations())->values($key);
        $source = new AidRequests();
        $requests = $source->requestsOf($key, $values);
        self::assertSame(['aid'], array_map(static fn ($request): string => $request->id, $requests));
        self::assertTrue($source->isAnswered($requests[0], $values));
        self::assertFalse($this->awaits($key));
        self::assertSame(['aid:award participant -12000'], $this->aidLines($key));

        $this->answer($key, 'yes', '5000');
        self::assertSame('5000', (new Registrations())->values($key)['aid_award']);
        self::assertSame(['aid:award participant -5000'], $this->aidLines($key));
        self::assertSame('25000', $this->balance($key));
    }

    public function testNeitherFieldHoldingAValueIsNoRequestAndACanPayAloneAwaits(): void
    {
        $this->applyAid();
        $key = $this->register('00A0');
        $source = new AidRequests();

        self::assertSame([], $source->requestsOf($key, []));
        self::assertSame([], $source->requestsOf($key, ['aid_can_pay' => '', 'aid_award' => '']));
        self::assertCount(1, $source->requestsOf($key, ['aid_can_pay' => '', 'aid_award' => '0']));
        $asked = $source->requestsOf($key, ['aid_can_pay' => '1000', 'aid_award' => '']);
        self::assertCount(1, $asked);
        self::assertFalse($source->isAnswered($asked[0], ['aid_can_pay' => '1000', 'aid_award' => '']));
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

        // The act refuses an award above the part (N1132); an award that is above it later (the price fell, a
        // support came) is capped by the component: the value is stored as the field holds it here.
        $this->writeValue($key, 'aid_award', '50000');

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

        // 30 000 less support 10 000 and share 4 000 leaves 16 000: the most the answer can award (N1132).
        $request = new Request('aid', RequestKind::Aid, 'x');
        self::assertNotNull($this->components);
        $source = new AidRequests(composition: new FeeComposition($this->components));
        $terms = $source->terms($key, $request, [], $this->utc($this->now));
        self::assertNotNull($terms);
        self::assertSame('16000', PlainAmount::of($terms->fullPrice));
        self::assertSame('16000', PlainAmount::of($terms->maxAmount()));
        $this->answer($key, 'yes', '16000');

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

        // Through the act the price cap comes first; the field's own limit is the source's, checked directly.
        try {
            $this->answer($key, 'yes', '1000000000000');
            self::fail('Accepted.');
        } catch (AidAbovePrice) {
            self::assertTrue($this->awaits($key));
            self::assertArrayNotHasKey('aid_award', (new Registrations())->values($key));
        }
        $context = (new \ReflectionClass(Context::class))->newInstanceWithoutConstructor();
        try {
            (new AidRequests())->writeAnswer(
                $key,
                new Request(AidRequests::ID, RequestKind::Aid, 'Teszt ösztöndíj'),
                new Answer(true, Money::of('1000000000000', Currency::HUF)),
                $context
            );
            self::fail('Accepted.');
        } catch (InvalidInput) {
            self::assertArrayNotHasKey('aid_award', (new Registrations())->values($key));
        }
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
        // Aid off with an award is refused (N120); the stored setting is dropped here to read the preview of
        // putting it back over a registration that holds an award.
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_aid_settings', ['event_id' => $this->event]);
        $this->settings->apply($this->event, new ProposedMoneySettings(), new CliActor(null));
        self::assertSame('30000', $this->balance($key));

        $rows = $this->settings->preview(
            $this->event,
            new ProposedMoneySettings(components: [$this->aid->propose($this->event, 'Teszt ösztöndíj')]),
            new CliActor(null)
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

    public function testTheTermsAreTheFullPriceWhatTheyCanPayAndTheAidAskedAndAnAwardAbovePriceIsRefused(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');
        $request = new Request('aid', RequestKind::Aid, 'x');
        $source = new AidRequests();
        $now = $this->utc($this->now);

        $terms = $source->terms($key, $request, (new Registrations())->values($key), $now);
        self::assertNotNull($terms);
        self::assertSame('30000', PlainAmount::of($terms->fullPrice));
        self::assertSame('10000', PlainAmount::of($terms->canPay ?? throw new \LogicException()));
        self::assertSame('20000', PlainAmount::of($terms->asked() ?? throw new \LogicException()));
        self::assertSame('30000', PlainAmount::of($terms->maxAmount()));

        // They can pay more than the price: nothing asked.
        $more = $source->terms($key, $request, ['aid_can_pay' => '40000'], $now);
        self::assertSame('0', PlainAmount::of($more?->asked() ?? throw new \LogicException()));
        // No stated amount: none asked is known.
        self::assertNull($source->terms($key, $request, [], $now)?->asked());

        $this->answer($key, 'yes', '30000');
        $this->expectException(AidAbovePrice::class);
        $this->answer($key, 'yes', '30001');
    }

    public function testTheDetailReadCarriesTheAidContextOfTheRequest(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        $read = (new RegistrationDetail())->of(new UserActor($this->fullAccessUser()), $this->event, $key->code);

        self::assertSame(
            [
                'full_price' => '30000.00000000',
                'can_pay' => '10000.00000000',
                'asked' => '20000.00000000',
                'max_award' => '30000.00000000',
            ],
            $read['requests']['items'][0]['aid']
        );
    }

    public function testTurningTheAidOffIsRefusedWhileARequestAwaitsOrHoldsAnAward(): void
    {
        $this->applyAid();
        $key = $this->registerAsking('00A0', '10000');

        // An awaiting request.
        try {
            $this->applyAid(null);
            self::fail('Turned off with a request awaiting.');
        } catch (AidInUse $e) {
            self::assertSame('kalaka_aid_in_use', $e->errorCode());
            self::assertInstanceOf(RuleViolation::class, $e);
            self::assertSame(
                'A támogatás nem kapcsolható ki, amíg van megválaszolatlan vagy megítélt támogatási kérelem.',
                $e->publicMessage()
            );
        }
        self::assertSame('Teszt ösztöndíj', $this->aid->label($this->event));
        self::assertTrue($this->awaits($key));

        // The save refuses too, not only the proposal.
        try {
            $this->runner->run(new SetAid(), ['event_id' => $this->event, 'label' => null], new CliActor(null));
            self::fail('Turned off with a request awaiting.');
        } catch (AidInUse) {
            self::assertSame('Teszt ösztöndíj', $this->aid->label($this->event));
        }

        // An award.
        $this->answer($key, 'yes', '10000');
        try {
            $this->applyAid(null);
            self::fail('Turned off with an award.');
        } catch (AidInUse) {
            self::assertSame('20000', $this->balance($key));
        }
    }

    public function testTurningTheAidOffPassesWithNoRequestOrOnlyRefusalsAndTurningItOnReadsThemAgain(): void
    {
        $this->applyAid();
        $none = $this->register('00A0');
        $refused = $this->registerAsking('00A2', '10000');
        $this->answer($refused, 'no');

        self::assertSame([], $this->applyAid(null)->failed());

        self::assertNull($this->aid->label($this->event));
        self::assertSame('30000', $this->balance($refused));
        self::assertFalse($this->awaits($refused));
        self::assertSame('30000', $this->balance($none));

        $this->applyAid();
        self::assertFalse($this->awaits($refused), 'the refusal is stored and still an answer');
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
