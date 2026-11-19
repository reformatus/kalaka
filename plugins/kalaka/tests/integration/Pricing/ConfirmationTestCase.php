<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\EvaluateRegistration;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\ConfirmationThreshold;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\RevokeManualConfirmation;
use Kalaka\Pricing\SetManualConfirmation;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * The base of the confirmation tests: the Pricing base with core's confirmation rule registered as Foundation's
 * boot registers it (over the materializer's own composition), and a clock that advances one second on every
 * run, so each run has its own moment, as in production.
 */
abstract class ConfirmationTestCase extends PricingTestCase
{
    protected DecisionRules $rules;

    protected ?ConfirmationThreshold $threshold = null;

    private int $lineCount = 0;

    protected function useComponents(FeeComponent ...$more): void
    {
        $this->useRules(static fn (DecisionRules $rules): null => null, ...$more);
    }

    /**
     * The runner with the given components, and the rules: confirmation, plus what `$also` adds.
     *
     * @param \Closure(DecisionRules): mixed $also
     */
    protected function useRules(\Closure $also, FeeComponent ...$more): void
    {
        $components = new CoreFeeComponents($more);
        $money = new Materializer($components);
        $this->rules = new DecisionRules();
        $also($this->rules);
        $this->rules->add(new ConfirmationRule($money->composition(), $this->threshold), true, true);
        $this->runner = new Runner(
            new TestClock($this->utc('2026-11-30 08:00:00'), true),
            true,
            new Evaluation($this->rules, money: $money)
        );
        $this->settings = new MoneySettings($this->runner, $components);
    }

    /** A manual payment with its value date (a day). */
    protected function payOn(
        RegistrationKey $key,
        string $amount,
        string $day,
        string $payer = Payer::PARTICIPANT,
    ): void {
        $this->line($key, LineKind::ManualPayment, '-' . $amount, $payer, $day);
    }

    /** A manual refund with its value date (a day). */
    protected function refundOn(
        RegistrationKey $key,
        string $amount,
        string $day,
        string $payer = Payer::PARTICIPANT,
    ): void {
        $this->line($key, LineKind::ManualRefund, $amount, $payer, $day);
    }

    /** A payment moved away to another registration (`transfer`, positive), with its value date (a day). */
    protected function moveAway(RegistrationKey $key, string $amount, string $day): void
    {
        $this->line($key, LineKind::Transfer, $amount, Payer::PARTICIPANT, $day);
    }

    /** An online payment with its paid time (UTC). */
    protected function payOnline(RegistrationKey $key, string $amount, string $paidAt): void
    {
        $this->line($key, LineKind::Payment, '-' . $amount, Payer::PARTICIPANT, null, $this->utc($paidAt));
    }

    private function line(
        RegistrationKey $key,
        LineKind $kind,
        string $amount,
        string $payer,
        ?string $day,
        ?\DateTimeImmutable $paidAt = null,
    ): void {
        $line = new NewLine(
            $key,
            $kind,
            $payer,
            $this->huf($amount),
            new Subject('test_payment', null, 'p'),
            'test:' . (++$this->lineCount),
            $day,
            $paidAt,
        );
        $work = function (array $input, Context $context) use ($line): void {
            $this->lockRegistrations($context, $line->registration);
            $this->ledger->record($line, $context);
        };
        $this->inRun($work);
    }

    protected function setFlag(RegistrationKey $key, string $reason = 'Teszt indok'): mixed
    {
        return $this->runner->run(
            new SetManualConfirmation(),
            ['event_id' => $key->eventId, 'code' => $key->code, 'reason' => $reason],
            new CliActor(null)
        );
    }

    protected function revokeFlag(RegistrationKey $key, string $reason = 'Teszt visszavonás'): mixed
    {
        return $this->runner->run(
            new RevokeManualConfirmation(),
            ['event_id' => $key->eventId, 'code' => $key->code, 'reason' => $reason],
            new CliActor(null)
        );
    }

    protected function evaluate(RegistrationKey $key): void
    {
        $this->runner->run(
            new EvaluateRegistration(),
            ['event_id' => $key->eventId, 'code' => $key->code],
            new CliActor(null)
        );
    }

    /**
     * The registration's confirmation records, oldest first, as "state effective-at cause", times in UTC.
     *
     * @return list<string>
     */
    protected function confirmations(RegistrationKey $key): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT state, effective_at, cause_type, cause_key FROM %i'
            . " WHERE event_id = %d AND code = %s AND kind = 'confirmation' ORDER BY id",
            $wpdb->prefix . 'kalaka_decisions',
            $key->eventId,
            $key->code
        ), ARRAY_A);
        self::assertIsArray($rows);

        return array_map(
            static fn (array $row): string => $row['state'] . ' ' . substr((string) $row['effective_at'], 0, 19)
                . ' ' . $row['cause_type'],
            $rows
        );
    }

    /** The state of the registration's latest confirmation record, or null. */
    protected function confirmationState(RegistrationKey $key): ?string
    {
        $records = $this->confirmations($key);
        if ($records === []) {
            return null;
        }

        return explode(' ', $records[count($records) - 1])[0];
    }
}
