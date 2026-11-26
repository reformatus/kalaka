<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Registrations\Answer\AnswerRequest;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Ledger\ManualEntryTestCase;

/**
 * "Answer a request" end to end (the walkthrough's six cases, B1 to B3): one act for offers and aid, one answer
 * record per act, the answer as the source's own field value, a refusal freeing the seat, and the registration
 * evaluated in the same run (money, then confirmation, then mail). The "answered" mail and the due clock are later
 * slices.
 * The fee components and the source are test doubles for B0's offers and aid.
 */
final class AnswerRequestTest extends ManualEntryTestCase
{
    private const KEY = '00A0';

    private TestRequestSource $source;

    private Requests $requests;

    private RecordingSeats $seats;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function set_up(): void
    {
        parent::set_up();

        ActionCatalogue::record(new ActionCatalogue([
            'kalaka_record_money',
            'kalaka_view_money',
            Requests::ANSWER,
        ]));
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '30000', 'currency' => 'HUF']);
        // The fields the test source writes: the event declares them, source "extension".
        global $wpdb;
        foreach (['support_answer', 'aid_award'] as $position => $field) {
            $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
                'event_id' => $this->event,
                'field_key' => $field,
                'version' => 1,
                'type' => 'kalaka/text-field',
                'title' => 'Teszt mező',
                'source' => 'extension',
                'created_at' => '2026-11-13 08:00:00.000000',
                'updated_at' => '2026-11-13 08:00:00.000000',
                'position' => 100 + $position,
                'form_writable' => 0,
            ]);
        }
        $this->runner = $this->runnerAt('2026-12-01 10:00');
    }

    protected function runnerAt(string $local): Runner
    {
        $this->source ??= new TestRequestSource();
        $this->requests ??= new Requests([$this->source]);
        $this->seats ??= new RecordingSeats();
        $money = new Materializer(new CoreFeeComponents([
            new AnswerComponent('support', FeeStage::Support, 'support_answer', new Registrations(), '10000'),
            new AnswerComponent('aid', FeeStage::Aid, 'aid_award', new Registrations()),
        ]));
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true);

        return new Runner($this->clockAt($local), true, new Evaluation($rules, money: $money));
    }

    private function key(string $code = self::KEY): RegistrationKey
    {
        return new RegistrationKey($this->event, $code);
    }

    private function useCase(): AnswerRequest
    {
        return new AnswerRequest($this->requests, seats: $this->seats);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(string $request, string $answer, array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $this->event,
            'code' => self::KEY,
            'request' => $request,
            'answer' => $answer,
        ];
    }

    /** @return array<string, mixed> */
    private function answer(string $request, string $answer, array $overrides = [], ?UserActor $actor = null): array
    {
        // The tests give a note unless they say otherwise (a reason is optional).
        if ($request === TestRequestSource::OFFER && $answer === 'yes' && !array_key_exists('note', $overrides)) {
            $overrides['note'] = 'Teszt indok';
        }

        return $this->answerAsGiven($request, $answer, $overrides, $actor);
    }

    /** @return array<string, mixed> */
    private function answerAsGiven(
        string $request,
        string $answer,
        array $overrides = [],
        ?UserActor $actor = null
    ): array {
        $result = $this->exec($this->useCase(), $this->input($request, $answer, $overrides), $actor);
        self::assertIsArray($result);

        return $result;
    }

    private function pay(string $amount, string $day, string $key): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput([
            'amount' => $amount,
            'value_date' => $day,
            'idempotency_key' => $key,
        ]));
    }

    /** The participant's amounts as the money table reads them: charged, paid, still to pay. */
    private function money(): array
    {
        $summary = (new Ledger())->summaryOf($this->key())->of(Payer::PARTICIPANT, Currency::HUF);

        return [$summary->charged->amount(), $summary->paid->amount(), $summary->balance->amount()];
    }

    private static function huf(string $amount): string
    {
        return Money::of($amount, Currency::HUF)->amount();
    }

    private function confirmationState(): ?string
    {
        global $wpdb;
        $state = $wpdb->get_var($wpdb->prepare(
            "SELECT state FROM %i WHERE event_id = %d AND code = '00A0' AND kind = 'confirmation' ORDER BY id DESC"
            . ' LIMIT 1',
            $wpdb->prefix . 'kalaka_decisions',
            $this->event
        ));

        return $state === null ? null : (string) $state;
    }

    /** @return list<array<string, ?string>> */
    private function answerRows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE event_id = %d ORDER BY id',
            $wpdb->prefix . 'kalaka_answers',
            $this->event
        ), ARRAY_A);
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }

    private function value(string $field): ?string
    {
        return (new Registrations())->values($this->key())[$field] ?? null;
    }

    private function awaits(): bool
    {
        return $this->requests->awaits($this->key(), RegistrationStatus::Registered);
    }

    public function testCase1AnAidRequestAnsweredYesLowersWhatIsDueAndIsAFactWithItsRecord(): void
    {
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');
        self::assertTrue($this->awaits());
        $this->runner = $this->runnerAt('2026-12-04 10:00');

        $result = $this->answer(TestRequestSource::AID, 'yes', ['amount' => '12000', 'note' => 'Teszt megjegyzés']);

        self::assertTrue($result['changed']);
        self::assertFalse($this->awaits());
        self::assertSame([self::huf('18000'), self::huf('0'), self::huf('18000')], $this->money());
        self::assertSame(self::huf('12000'), $this->value('aid_award'));
        self::assertNull($this->confirmationState(), 'nothing is paid yet');
        $rows = $this->answerRows();
        self::assertCount(1, $rows);
        self::assertSame((string) $result['answer'], $rows[0]['id']);
        self::assertSame('aid', $rows[0]['request']);
        self::assertSame('1', $rows[0]['approved']);
        self::assertSame('2026-12-04 09:00:00.000000', $rows[0]['answered_at']);
        self::assertSame((string) $this->admin->userId, $rows[0]['answered_by']);
        self::assertSame('Teszt megjegyzés', $rows[0]['note']);
        self::assertSame($this->event . '/answer/' . $result['answer'], $this->mailKey((int) $result['answer']));
    }

    private function mailKey(int $id): string
    {
        $subject = (new \Kalaka\Registrations\Answer\AnswerRepository())->find($id)?->subject();
        self::assertNotNull($subject);

        return $subject->eventId . '/' . $subject->type . '/' . $subject->key;
    }

    public function testCase2AnAidRequestAnsweredNoIsAnAwardOfZeroAndTheAmountStaysFull(): void
    {
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');

        $result = $this->answer(TestRequestSource::AID, 'no', ['amount' => '5000']);

        self::assertTrue($result['changed']);
        self::assertFalse($this->awaits(), 'an award of zero is an answer');
        self::assertSame([self::huf('30000'), self::huf('0'), self::huf('30000')], $this->money());
        self::assertSame(self::huf('0'), $this->value('aid_award'));
        $rows = $this->answerRows();
        self::assertSame('0', $rows[0]['approved']);
        self::assertSame(self::huf('0'), $rows[0]['amount']);
    }

    public function testCase3APartnerApprovesAnOfferAndTheRegistrationIsConfirmedInTheSameRun(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->runner = $this->runnerAt('2026-12-03 10:00');
        $this->pay('20000', '2026-12-03', 'k1');
        self::assertNull($this->confirmationState(), 'still 10 000 Ft short');
        self::assertTrue($this->awaits());

        $partner = $this->plainUser();
        $this->grantOn($partner, [Requests::ANSWER]);
        $this->runner = $this->runnerAt('2026-12-05 10:00');
        $result = $this->answer(TestRequestSource::OFFER, 'yes', [], new UserActor($partner));

        self::assertTrue($result['changed']);
        self::assertSame('approved', $this->value('support_answer'));
        self::assertSame([self::huf('20000'), self::huf('20000'), self::huf('0')], $this->money());
        self::assertSame('confirmed', $this->confirmationState());
        self::assertSame((string) $partner, $this->answerRows()[0]['answered_by']);
        self::assertNotContains('release:' . TestRequestSource::POOL, $this->seats->calls);
    }

    /** The pass runs money, confirmation, mail: an answer that makes the payments enough confirms and queues the mail. */
    public function testAnAnswerThatConfirmsQueuesTheConfirmedMailInTheSamePass(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'confirmed',
            'version' => 1,
            'enabled' => 1,
            'layout' => 1,
        ]);
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->runner = $this->runnerAt('2026-12-03 10:00');
        $this->pay('20000', '2026-12-03', 'k1');
        self::assertNull($this->confirmationState());
        $deliveries = fn (): array => $wpdb->get_results($wpdb->prepare(
            "SELECT code, cause_type, cause_key FROM %i WHERE mail = 'confirmed' ORDER BY id",
            $wpdb->prefix . 'kalaka_deliveries'
        ), ARRAY_A);
        self::assertSame([], $deliveries());

        $this->runner = $this->runnerAt('2026-12-05 10:00');
        $this->answer(TestRequestSource::OFFER, 'yes');

        self::assertSame('confirmed', $this->confirmationState());
        $rows = $deliveries();
        self::assertCount(1, $rows);
        self::assertSame(self::KEY, $rows[0]['code']);
        self::assertSame('decision', $rows[0]['cause_type']);
    }

    public function testAnAwaitingRequestDoesNotBlockConfirmationAndALaterApprovalLeavesAnOverpayment(): void
    {
        // Case 4's last paragraph (Q10): she pays 30 000 Ft anyway.
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->runner = $this->runnerAt('2026-12-03 10:00');
        $this->pay('30000', '2026-12-02', 'k1');

        self::assertTrue($this->awaits());
        self::assertSame('confirmed', $this->confirmationState());

        $this->answer(TestRequestSource::OFFER, 'yes');

        self::assertSame([self::huf('20000'), self::huf('30000'), self::huf('-10000')], $this->money());
        self::assertSame('confirmed', $this->confirmationState());
    }

    public function testCase5ARefusalFreesTheSeatAndAChangeToYesTakesOneAgainWithTwoRecords(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->runner = $this->runnerAt('2026-12-03 10:00');
        $first = $this->answer(TestRequestSource::OFFER, 'no');

        self::assertSame('refused', $this->value('support_answer'));
        self::assertSame([self::huf('30000'), self::huf('0'), self::huf('30000')], $this->money());
        self::assertContains('release:' . TestRequestSource::POOL, $this->seats->calls);
        self::assertNotContains('take:' . TestRequestSource::POOL, $this->seats->calls);

        $this->runner = $this->runnerAt('2026-12-06 10:00');
        $second = $this->answer(TestRequestSource::OFFER, 'yes');

        self::assertNotSame($first['answer'], $second['answer'], 'a changed answer has its own record');
        self::assertFalse($second['over_limit']);
        self::assertContains('take:' . TestRequestSource::POOL, $this->seats->calls);
        self::assertSame('approved', $this->value('support_answer'));
        self::assertSame([self::huf('20000'), self::huf('0'), self::huf('20000')], $this->money());
        $rows = $this->answerRows();
        self::assertSame(['0', '1'], array_column($rows, 'approved'));
        self::assertSame(
            ['2026-12-03 09:00:00.000000', '2026-12-06 09:00:00.000000'],
            array_column($rows, 'answered_at')
        );
    }

    public function testB1AYesWhileThePoolIsFullStandsAndSaysTheyWentOverTheLimit(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->answer(TestRequestSource::OFFER, 'no');
        $this->seats->full = true;

        $result = $this->answer(TestRequestSource::OFFER, 'yes');

        self::assertTrue($result['changed']);
        self::assertTrue($result['over_limit']);
        self::assertSame('approved', $this->value('support_answer'));
    }

    public function testCase6ANoAfterConfirmationRemovesTheSupportFreesTheSeatAndTheConfirmationStays(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->runner = $this->runnerAt('2026-12-04 10:00');
        $this->pay('20000', '2026-12-03', 'k1');
        $this->answer(TestRequestSource::OFFER, 'yes');
        self::assertSame('confirmed', $this->confirmationState());
        $this->seats->calls = [];

        $this->runner = $this->runnerAt('2026-12-10 10:00');
        $this->answer(TestRequestSource::OFFER, 'no');

        self::assertSame([self::huf('30000'), self::huf('20000'), self::huf('10000')], $this->money());
        self::assertSame('confirmed', $this->confirmationState());
        self::assertSame(['lock:church', 'release:church'], $this->seats->calls);
        self::assertCount(2, $this->answerRows());
    }

    public function testAnIdenticalRepeatWritesNothingAndADifferentAmountIsANewAct(): void
    {
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');
        $this->answer(TestRequestSource::AID, 'yes', ['amount' => '12000']);

        $again = $this->answer(TestRequestSource::AID, 'yes', ['amount' => '12000.00']);
        self::assertSame(['changed' => false, 'answer' => null, 'over_limit' => false], $again);
        self::assertCount(1, $this->answerRows());

        $this->answer(TestRequestSource::AID, 'yes', ['amount' => '9000']);
        self::assertCount(2, $this->answerRows());
        self::assertSame(self::huf('9000'), $this->value('aid_award'));
    }

    public function testAbsenceOfAnAnswerIsNotAnAnswerOfZero(): void
    {
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');
        $state = $this->requests->states($this->key())[0];
        self::assertTrue($state->awaits());
        self::assertNull($this->value('aid_award'));

        $this->answer(TestRequestSource::AID, 'no');

        self::assertFalse($this->requests->states($this->key())[0]->awaits());
        self::assertSame(self::huf('0'), $this->value('aid_award'));
    }

    public function testAnAidYesNeedsAPositiveAmountAndAnOfferTakesNone(): void
    {
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');
        $this->addValue($this->event, self::KEY, 'support', 'church');

        foreach ([null, '0', '-5', 'abc'] as $amount) {
            try {
                $this->answer(TestRequestSource::AID, 'yes', ['amount' => $amount]);
                self::fail('An aid yes without a positive amount is refused.');
            } catch (InvalidInput) {
                self::assertSame([], $this->answerRows());
            }
        }
        $this->expectException(InvalidInput::class);
        $this->answer(TestRequestSource::OFFER, 'yes', ['amount' => '5000']);
    }

    public function testAnUnknownRequestIsNotFoundAndAWithdrawnRegistrationIsRefused(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        try {
            $this->answer(TestRequestSource::AID, 'yes', ['amount' => '1']);
            self::fail('The registration has no aid request.');
        } catch (NotFound) {
            self::assertSame([], $this->answerRows());
        }

        $this->addRegistration($this->event, '00B0', 'withdrawn');
        $this->addValue($this->event, '00B0', 'support', 'church');
        $this->expectException(RuleViolation::class);
        $this->answer(TestRequestSource::OFFER, 'yes', ['code' => '00B0']);
    }

    public function testAWithdrawnRegistrationAwaitsNothing(): void
    {
        $this->addRegistration($this->event, '00B0', 'withdrawn');
        $this->addValue($this->event, '00B0', 'support', 'church');

        self::assertFalse($this->requests->awaits($this->key('00B0'), RegistrationStatus::Withdrawn));
        self::assertTrue($this->requests->awaits($this->key('00B0'), RegistrationStatus::Waitlisted));
    }

    public function testAPartnerAnswersOnlyInsideTheirViewAndOnlyWithTheAction(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->addRegistration($this->event, '00B0', 'waitlisted');
        $this->addValue($this->event, '00B0', 'support', 'church');
        $partner = $this->plainUser();
        $this->grantOn($partner, [Requests::ANSWER]);

        // The grant's view holds the registered ones: the waitlisted registration is outside it.
        try {
            $this->answer(TestRequestSource::OFFER, 'yes', ['code' => '00B0'], new UserActor($partner));
            self::fail('A registration outside the partner view is refused.');
        } catch (PermissionDenied) {
            self::assertSame([], $this->answerRows());
        }

        $other = $this->plainUser();
        $this->grantOn($other, ['kalaka_view_money']);
        try {
            $this->answer(TestRequestSource::OFFER, 'yes', [], new UserActor($other));
            self::fail('A grant without the action is refused.');
        } catch (PermissionDenied) {
            self::assertSame([], $this->answerRows());
        }

        self::assertTrue($this->answer(TestRequestSource::OFFER, 'yes', [], new UserActor($partner))['changed']);
    }

    public function testAWaitlistedRegistrationIsInactiveSoItsRequestIsNotAnswered(): void
    {
        $this->addRegistration($this->event, '00B0', 'waitlisted');
        $this->addValue($this->event, '00B0', 'support', 'church');

        try {
            $this->answer(TestRequestSource::OFFER, 'yes', ['code' => '00B0']);
            self::fail('A waitlisted registration is not answered.');
        } catch (RuleViolation) {
            self::assertSame([], $this->answerRows());
            self::assertNull((new Registrations())->values($this->key('00B0'))['support_answer'] ?? null);
            self::assertSame(['lock:church'], $this->seats->calls, 'only the lock; no take, no release');
        }
    }

    public function testTheSameRegistrationIsAnswerableOnceAdmitted(): void
    {
        $this->addRegistration($this->event, '00B0', 'waitlisted');
        $this->addValue($this->event, '00B0', 'support', 'church');
        $this->admit('00B0');

        self::assertTrue($this->answer(TestRequestSource::OFFER, 'yes', ['code' => '00B0'])['changed']);
        self::assertSame('approved', (new Registrations())->values($this->key('00B0'))['support_answer']);
    }

    public function testAWaitlistedRegistrationStillAwaitsAnAnswerAsAFact(): void
    {
        $this->addRegistration($this->event, '00B0', 'waitlisted');
        $this->addValue($this->event, '00B0', 'support', 'church');

        self::assertTrue($this->requests->awaits($this->key('00B0'), RegistrationStatus::Waitlisted));
    }

    private function admit(string $code): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'registered', 'registered_at' => '2026-11-30 09:00:00.000000'],
            ['event_id' => $this->event, 'code' => $code],
        );
    }

    public function testAnAidRequestLocksNoSeatPool(): void
    {
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');

        $this->answer(TestRequestSource::AID, 'yes', ['amount' => '12000']);

        self::assertSame([], $this->seats->calls);
    }

    /** Owner 2026-11-26: a reason is never required, a "yes" to an offer included. */
    public function testAYesToAnOfferNeedsNoReasonAndNeitherDoARefusalOrAid(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        foreach (['', '   ', null] as $position => $note) {
            $overrides = $note === null ? [] : ['note' => $note];
            $this->answerAsGiven(TestRequestSource::OFFER, 'no');
            $yes = $this->answerAsGiven(TestRequestSource::OFFER, 'yes', $overrides);
            self::assertTrue($yes['changed'], 'A yes without a reason is recorded (' . $position . ').');
            $notes = array_column($this->answerRows(), 'note');
            self::assertNull($notes[count($notes) - 1]);
        }

        self::assertTrue($this->answerAsGiven(TestRequestSource::OFFER, 'no')['changed'], 'A refusal needs none.');
        $this->addValue($this->event, self::KEY, 'aid_asked', '12000');
        $aid = $this->answerAsGiven(TestRequestSource::AID, 'yes', ['amount' => '12000']);
        self::assertTrue($aid['changed'], 'Aid needs none.');
        $given = $this->answerAsGiven(TestRequestSource::OFFER, 'yes', ['note' => 'Telefonon megerősítve']);
        self::assertTrue($given['changed']);
        $notes = array_column($this->answerRows(), 'note');
        self::assertSame('Telefonon megerősítve', $notes[count($notes) - 1]);
    }

    public function testAnAnswerWhoseOnlyChangeIsANewNoteIsANewAct(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->answer(TestRequestSource::OFFER, 'yes', ['note' => 'Első']);

        $same = $this->answer(TestRequestSource::OFFER, 'yes', ['note' => 'Első']);
        self::assertFalse($same['changed'], 'the same note is a repeat');
        $none = $this->answerAsGiven(TestRequestSource::OFFER, 'yes', ['note' => null]);
        self::assertFalse($none['changed'], 'an empty note adds nothing');
        $noted = $this->answer(TestRequestSource::OFFER, 'yes', ['note' => 'Telefonon megerősítve']);

        self::assertTrue($noted['changed']);
        $rows = $this->answerRows();
        self::assertSame(['Első', 'Telefonon megerősítve'], array_column($rows, 'note'));
        self::assertSame(['1', '1'], array_column($rows, 'approved'));
    }

    public function testTheFieldValueNotTheAnswerRowDrivesTheMoney(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->answer(TestRequestSource::OFFER, 'yes');
        self::assertSame(self::huf('20000'), $this->money()[0]);

        // The fact is the field: set it to "refused" under the unchanged answer rows and let a payment re-price.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_field_values',
            ['value' => 'refused'],
            ['event_id' => $this->event, 'code' => self::KEY, 'field_key' => 'support_answer']
        );
        $this->pay('1000', '2026-12-01', 'k1');

        self::assertSame('1', $this->answerRows()[0]['approved']);
        self::assertSame(self::huf('30000'), $this->money()[0]);
    }

    public function testWriteValueNeedsTheRegistrationLockADeclaredFieldAndNotASystemField(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'decided',
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'Teszt mező',
            'source' => 'system',
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 200,
            'form_writable' => 0,
        ]);
        $write = function (string $field, bool $lock, string $declarer = TestRequestSource::DECLARER): void {
            $this->runner->run(
                new AdminProbe(function (array $input, Context $context) use ($field, $lock, $declarer): null {
                    if ($lock) {
                        (new Registrations())->lock($this->key(), $context);
                    }
                    (new Registrations())->writeValue($this->key(), $field, 'x', $context, $declarer);

                    return null;
                }),
                [],
                new SystemActor()
            );
        };

        foreach ([['support_answer', false], ['nincs_ilyen', true], ['decided', true]] as [$field, $lock]) {
            try {
                $write($field, $lock);
                self::fail('The write of ' . $field . ' is refused.');
            } catch (SystemFailure) {
                self::assertNull($this->value($field));
            }
        }

        try {
            $write('support_answer', true, '');
            self::fail('A write names its declarer.');
        } catch (SystemFailure) {
            self::assertNull($this->value('support_answer'));
        }

        $write('support_answer', true);
        self::assertSame('x', $this->value('support_answer'));
    }

    public function testTheAnswerIsAuditedOnItsRegistration(): void
    {
        $this->addValue($this->event, self::KEY, 'support', 'church');
        $this->answer(TestRequestSource::OFFER, 'yes');

        $found = null;
        foreach ($this->entries() as $entry) {
            if ($entry['action'] === 'kalaka/answer-request') {
                $found = $entry;
            }
        }
        self::assertNotNull($found);
        self::assertSame('succeeded', $found['outcome']);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($found['id']));
    }
}
