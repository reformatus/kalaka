<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Mail\Pause\MailBudgetInvalid;
use Kalaka\Mail\Pause\SetMailBudget;
use Kalaka\Mail\SendReport;

/** The hourly budget as a setting: bounds, write, audit and the sender using it. mail.md, "Sending". */
final class SetMailBudgetTest extends MailTestCase
{
    public function testItWritesTheBudgetMakingTheRowWhenThereIsNoneAndAuditsIt(): void
    {
        self::assertNull($this->stateRow());

        $user = new UserActor($this->fullAccessUser());
        self::assertTrue($this->exec(new SetMailBudget(), ['hourly_budget' => 600], $user));

        $state = $this->stateRow();
        self::assertIsArray($state);
        self::assertSame('600', $state['hourly_budget']);
        self::assertNull($state['paused_at']);
        $entries = $this->entriesOf('kalaka/set-mail-budget');
        self::assertCount(1, $entries);
        self::assertSame(['mail_state//1'], $this->subjectsOf($entries[0]['id'] ?? 0));
        self::assertContains('hourly_budget', $this->changedFields($entries[0]));
    }

    public function testAnUnchangedValueIsASuccessThatWritesNothing(): void
    {
        $user = new UserActor($this->fullAccessUser());
        self::assertFalse($this->exec(new SetMailBudget(), ['hourly_budget' => 240], $user), 'The default: no change.');
        self::assertNull($this->stateRow(), 'No row is made for it.');

        self::assertTrue($this->exec(new SetMailBudget(), ['hourly_budget' => 50], $user));
        self::assertFalse($this->exec(new SetMailBudget(), ['hourly_budget' => 50], $user));

        self::assertSame('1', $this->stateRow()['version'] ?? null);
    }

    public function testItLeavesThePauseAndTheErrorsAlone(): void
    {
        $this->storeState(['paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'errors', 'errors_in_row' => 2]);

        $this->exec(new SetMailBudget(), ['hourly_budget' => 10], new CliActor(null));

        $state = $this->stateRow();
        self::assertSame(['errors', '2', '10'], [$state['cause'], $state['errors_in_row'], $state['hourly_budget']]);
        self::assertNotNull($state['paused_at']);
    }

    public function testTheBoundsAreOneToTwoThousandAndAnythingElseIsTheTypedRefusal(): void
    {
        foreach ([1, 2000] as $edge) {
            self::assertTrue($this->exec(new SetMailBudget(), ['hourly_budget' => $edge]));
            self::assertSame((string) $edge, $this->stateRow()['hourly_budget'] ?? null);
        }
        foreach ([0, -5, 2001, 100000] as $bad) {
            try {
                $this->exec(new SetMailBudget(), ['hourly_budget' => $bad]);
                self::fail('Not refused: ' . $bad);
            } catch (RuleViolation $refused) {
                self::assertInstanceOf(MailBudgetInvalid::class, $refused);
                self::assertSame('kalaka_mail_budget_invalid', $refused->errorCode());
                self::assertStringContainsString('[UNAPPROVED T-UX]', $refused->getMessage());
            }
        }
        self::assertSame('2000', $this->stateRow()['hourly_budget'] ?? null, 'A refusal wrote nothing.');
    }

    public function testTheSenderUsesTheNewBudgetAtItsNextRunWithinTheSameHour(): void
    {
        $event = $this->published();
        foreach (['00A0', '00B0', '00K1', '00G1'] as $code) {
            $this->queue($event, $code);
        }
        wp_unschedule_hook('kalaka_send_mail');
        // 12 an hour is one for the slot from 09:00.
        $this->exec(new SetMailBudget(), ['hourly_budget' => 12]);
        self::assertEquals(new SendReport(1), $this->sender()->send());
        self::assertEquals(new SendReport(), $this->sender()->send(), 'The slot\'s share is used up.');

        // Raised in the same slot: the very next run claims the difference (24 gives 2 for the slot, 1 is made).
        $this->exec(new SetMailBudget(), ['hourly_budget' => 24]);

        self::assertEquals(new SendReport(1), $this->sender()->send());
    }
}
