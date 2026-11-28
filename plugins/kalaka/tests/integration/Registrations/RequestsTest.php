<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\Api\Events;
use Kalaka\Events\EventRecord;
use Kalaka\Events\EventRepository;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Answer\AnswerRepository;
use Kalaka\Registrations\Api\AnswerRead;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Requests;

/**
 * The reads behind "awaits an answer" (foundation.md, "Facts, decisions and computed values"): computed from the
 * requests a contributed source names and the answer fields it reads them from, never stored;
 * `registration.awaits_answer` in the fact source; the read's cost.
 */
final class RequestsTest extends RegistrationsTestCase
{
    private ?\Kalaka\Events\Api\Event $event = null;

    private function event(): \Kalaka\Events\Api\Event
    {
        if ($this->event === null) {
            $stored = $this->inRun(
                fn (array $input, Context $context): EventRecord
                    => (new EventRepository())->upsert(new EventRecord('Teszt tábor'), $context)
            );
            self::assertInstanceOf(EventRecord::class, $stored);
            $this->event = (new Events())->find($stored->id);
            self::assertNotNull($this->event);
        }

        return $this->event;
    }

    /** @return list<string> the SELECTs the database receives while the work runs */
    private function statementsOf(\Closure $work): array
    {
        $statements = [];
        $capture = static function (string $sql) use (&$statements): string {
            if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
                $statements[] = $sql;
            }

            return $sql;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $statements;
    }

    private function asking(string $code = '00A0', string $status = 'registered'): RegistrationKey
    {
        global $wpdb;
        $event = $this->event()->id;
        $this->insertRegistration($this->newRegistration(
            $event,
            $code,
            RegistrationStatus::from($status),
            '2026-11-30 08:00:00'
        ));
        $wpdb->insert($wpdb->prefix . 'kalaka_field_values', [
            'event_id' => $event,
            'code' => $code,
            'field_key' => 'support',
            'version' => 1,
            'value' => 'church',
        ]);

        return new RegistrationKey($event, $code);
    }

    /** The source's own answer field, the truth: written as the answer act's source writes it. */
    private function field(RegistrationKey $key, string $fieldKey, string $value): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'kalaka_field_values', [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'field_key' => $fieldKey,
            'version' => 1,
            'value' => $value,
        ]);
    }

    /** An answering act as the act leaves it: the record, and the answer in the source's field. */
    private function answer(RegistrationKey $key, bool $approved, string $at = '2026-12-04 09:00:00.000000'): void
    {
        global $wpdb;
        $this->field($key, 'support_answer', $approved ? 'approved' : 'refused');
        $wpdb->insert($wpdb->prefix . 'kalaka_answers', [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'request' => TestRequestSource::OFFER,
            'approved' => $approved ? 1 : 0,
            'answered_at' => $at,
        ]);
    }

    public function testARegistrationAwaitsAnAnswerUntilTheRequestHasOneWhateverItSays(): void
    {
        $key = $this->asking();
        $requests = new Requests([new TestRequestSource()]);

        self::assertTrue($requests->awaits($key, RegistrationStatus::Registered));
        self::assertNull($requests->lastAnsweredAt($key));

        $this->answer($key, false);
        self::assertFalse($requests->awaits($key, RegistrationStatus::Registered), 'A refusal is an answer.');
        self::assertSame(
            '2026-12-04 09:00:00',
            $requests->lastAnsweredAt($key)?->format('Y-m-d H:i:s')
        );
    }

    public function testTheAnswerFieldIsTheTruthAndTheRecordDecidesNothing(): void
    {
        $key = $this->asking();
        $requests = new Requests([new TestRequestSource()]);

        $this->field($key, 'support_answer', 'refused');
        self::assertFalse($requests->awaits($key, RegistrationStatus::Registered), 'The field alone answers.');
        self::assertTrue($requests->states($key)[0]->answered);
        self::assertNull($requests->states($key)[0]->answer, 'No record of the act.');

        $this->field($key, 'support_answer', '');
        $this->answer($key, true);
        $this->field($key, 'support_answer', '');
        self::assertTrue($requests->awaits($key, RegistrationStatus::Registered), 'A record alone answers nothing.');
        self::assertNotNull($requests->states($key)[0]->answer);
    }

    public function testARequestRestatedAfterAnAnswerReadsAsAnsweredUntilStaffAnswerAgain(): void
    {
        $key = $this->asking();
        $requests = new Requests([new TestRequestSource()]);
        $this->answer($key, true);

        $this->field($key, 'support', 'church');

        self::assertFalse($requests->awaits($key, RegistrationStatus::Registered));
    }

    public function testTheLatestAnswerWinsAndEachActIsItsOwnRecord(): void
    {
        $key = $this->asking();
        $this->answer($key, false, '2026-12-03 09:00:00.000000');
        $this->answer($key, true, '2026-12-06 09:00:00.000000');

        $state = (new Requests([new TestRequestSource()]))->states($key)[0];

        self::assertTrue($state->answer?->approved);
        self::assertSame('2026-12-06 09:00:00', $state->answer->answeredAt->format('Y-m-d H:i:s'));
        $keys = array_map(
            static fn (AnswerRead $a): string => $a->subject()->key,
            (new AnswerRepository())->forRegistration($key)
        );
        self::assertCount(2, array_unique($keys), 'Each answering act has its own key.');
    }

    public function testAWithdrawnOrRemovedRegistrationAwaitsNothingAndAWaitlistedOneDoes(): void
    {
        $key = $this->asking();
        $requests = new Requests([new TestRequestSource()]);

        self::assertFalse($requests->awaits($key, RegistrationStatus::Withdrawn));
        self::assertFalse($requests->awaits($key, RegistrationStatus::Removed));
        self::assertTrue($requests->awaits($key, RegistrationStatus::Waitlisted));
    }

    public function testNoSourcesMeansNoRequestsAndNoStatement(): void
    {
        $key = $this->asking();
        $requests = new Requests([]);

        $statements = $this->statementsOf(
            static fn (): bool => $requests->awaits($key, RegistrationStatus::Registered)
        );

        self::assertSame([], $statements);
        self::assertFalse($requests->awaits($key, RegistrationStatus::Registered));
    }

    public function testASourceTheBootCollectedIsTheDefault(): void
    {
        $key = $this->asking();
        $contributions = new Contributions();
        $contributions->requestSource(new TestRequestSource());
        Contributions::record($contributions);

        self::assertTrue((new Requests())->awaits($key, RegistrationStatus::Registered));
    }

    public function testTheFactSourceReadsYesWhileARequestAwaitsAndNoOtherwise(): void
    {
        $key = $this->asking();
        $contributions = new Contributions();
        $contributions->requestSource(new TestRequestSource());
        Contributions::record($contributions);
        $event = $this->event();

        $now = new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC'));
        $facts = (new Registrations())->factsOf($key, $event, $now);
        self::assertInstanceOf(RegistrationFacts::class, $facts);
        self::assertSame(['yes'], $facts->get(RegistrationFacts::AWAITS_ANSWER)->data);
        self::assertSame('', $facts->get(RegistrationFacts::AWAITS_ANSWER)->text);
        self::assertSame('rögzített', $facts->get(RegistrationFacts::STATUS)->text);

        $this->answer($key, true);
        $facts = (new Registrations())->factsOf($key, $event, $now);
        self::assertInstanceOf(RegistrationFacts::class, $facts);
        self::assertSame(['no'], $facts->get(RegistrationFacts::AWAITS_ANSWER)->data);
    }

    public function testTheReadsCostOneOrTwoStatementsAndNoneWithoutRequests(): void
    {
        $key = $this->asking();
        $requests = new Requests([new TestRequestSource()]);

        $selects = $this->statementsOf(static fn (): bool => $requests->awaits($key, RegistrationStatus::Registered));
        self::assertCount(1, $selects, 'The field values hold every answer.');
        $states = $this->statementsOf(static fn (): array => $requests->states($key));
        self::assertCount(2, $states, 'The field values and the answer records, one statement each.');

        $plain = $this->asking('00A2');
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->update(
            $wpdb->prefix . 'kalaka_field_values',
            ['value' => 'nincs'],
            ['event_id' => $plain->eventId, 'code' => '00A2']
        );
        $none = $this->statementsOf(static fn (): bool => $requests->awaits($plain, RegistrationStatus::Registered));
        self::assertCount(1, $none, 'No request: only the field values are read.');
    }

    public function testAnAmountIsStoredWithItsCurrency(): void
    {
        $key = $this->asking();
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_answers', [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'request' => 'aid',
            'approved' => 1,
            'amount' => '12000.00000000',
            'currency' => 'HUF',
            'answered_at' => '2026-12-04 09:00:00.000000',
        ]);

        $stored = (new AnswerRepository())->forRegistration($key)[0];

        self::assertTrue($stored->amount?->isEqualTo(Money::of('12000', Currency::HUF)));
    }
}
