<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Mail\ClearEventMail;
use Kalaka\Mail\ClearMailQueue;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Mail\Pause\PauseMail;
use Kalaka\Mail\Pause\ResumeMail;
use Kalaka\Mail\Pause\SetMailBudget;

/** The eight Mail use cases as abilities: one entry each, the same one the PHP entries and the command line run. */
final class MailAbilitiesTest extends MailAdminTestCase
{
    /** @return array<string, array{\Kalaka\Foundation\Api\UseCase\AdminUseCase, string, string}> */
    private static function abilities(): array
    {
        return [
            'update-action-mail' => [
                new UpdateActionMail(),
                'Levél beállítása',
                'Egy esemény valamelyik levelének be- vagy kikapcsolása, levélsablonjának kiválasztása '
                    . 'és annak beállítása, hogy a levélkeret körülvegye-e.',
            ],
            'set-event-mail-layout' => [
                new SetEventMailLayout(),
                'Levélkeret mentése',
                'Egy esemény levélkeretének mentése.',
            ],
            'pause-mail' => [
                new PauseMail(),
                'Levélküldés szüneteltetése',
                'A levélküldés leállítása; a levelek a küldési sorban gyűlnek.',
            ],
            'resume-mail' => [
                new ResumeMail(),
                'Levélküldés folytatása',
                'A szüneteltetett levélküldés folytatása.',
            ],
            'set-mail-budget' => [
                new SetMailBudget(),
                'Óránként küldhető levelek számának beállítása',
                'Annak beállítása, hogy óránként legfeljebb hány levelet küldhet a Kaláka.',
            ],
            'clear-mail-queue' => [
                new ClearMailQueue(),
                'Küldési sor ürítése',
                'Minden küldésre váró levél törlése a küldési sorból.',
            ],
            'clear-event-mail' => [
                new ClearEventMail(),
                'Esemény küldésre váró leveleinek törlése',
                'Egy nem közzétett esemény küldésre váró leveleinek törlése.',
            ],
            'send-manual-mail' => [
                new SendManualMail(),
                'Levél küldése jelentkezőknek',
                'Levél küldése egy levélsablonból a kiválasztott jelentkezőknek.',
            ],
        ];
    }

    public function testTheEightAreAbilitiesOfTheKalakaCategoryWithTheirInputSchema(): void
    {
        foreach (self::abilities() as $slug => [$useCase, $label, $description]) {
            $ability = $this->ability('kalaka/' . $slug);
            self::assertSame($useCase::name(), $ability->get_name());
            self::assertSame($label, $ability->get_label(), $slug);
            self::assertSame($description, $ability->get_description(), $slug);
            self::assertSame('kalaka', $ability->get_category(), $slug);
            self::assertSame($useCase->inputSchema(), $ability->get_input_schema(), $slug);
            self::assertTrue($ability->get_meta()['show_in_rest'], $slug);
        }
    }

    public function testTheUpdateGivesTheSameArrayThroughTheAbilityAndThroughThePhpEntry(): void
    {
        [$event, $body] = $this->ready();
        $other = $this->document($event, $this->paragraph('Más'), 'Másik');
        $this->as($this->admin->userId);

        $input = ['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $body, 'enabled' => true];
        $viaAbility = $this->ability('kalaka/update-action-mail')->execute($input);
        $viaEntry = (new ActionMailSettings())->update($input, $this->admin, $this->runner);

        self::assertIsArray($viaAbility);
        self::assertSame($viaEntry, $viaAbility);
        self::assertSame($body, $viaAbility['mails'][1]['body_id']);
        self::assertTrue($viaAbility['mails'][1]['enabled']);

        // The pre-read is the stored body when none is given: switching off needs no body in the input.
        $off = ['event_id' => $event, 'mail' => 'waitlisted', 'enabled' => false];
        self::assertSame(
            (new ActionMailSettings())->update($off, $this->admin, $this->runner),
            $this->ability('kalaka/update-action-mail')->execute($off)
        );
        // A body of this event that the mail does not use is read for the assignment, too.
        $assign = ['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $other];
        $assigned = $this->ability('kalaka/update-action-mail')->execute($assign);
        self::assertIsArray($assigned);
        self::assertSame($other, $assigned['mails'][1]['body_id']);
    }

    public function testTheSendGivesTheSameArrayThroughTheAbilityAndThroughThePhpEntry(): void
    {
        [$event, $body] = $this->ready();
        $this->as($this->admin->userId);

        $keyed = [
            'event_id' => $event, 'body_id' => $body, 'codes' => ['00A0', '00A2'], 'request_key' => 'tesztkulcs01',
        ];
        $first = $this->ability('kalaka/send-manual-mail')->execute($keyed);
        self::assertIsArray($first);
        self::assertFalse($first['repeat']);

        // The repeat path: one key through both entries returns one array, the first batch's.
        $viaAbility = $this->ability('kalaka/send-manual-mail')->execute($keyed);
        $viaEntry = (new ManualMails())->send($keyed, $this->admin, $this->runner);
        self::assertSame($viaEntry, $viaAbility);
        self::assertTrue($viaAbility['repeat']);
        self::assertSame($first['batch_id'], $viaAbility['batch_id']);

        // The fresh path: the same keys, the same values; only the batch is a new one.
        $fresh = ['event_id' => $event, 'body_id' => $body, 'codes' => ['00A0']];
        $freshAbility = $this->ability('kalaka/send-manual-mail')->execute($fresh);
        $freshEntry = (new ManualMails())->send($fresh, $this->admin, $this->runner);
        self::assertIsArray($freshAbility);
        self::assertSame(array_keys($freshEntry), array_keys($freshAbility));
        unset($freshAbility['batch_id'], $freshEntry['batch_id']);
        self::assertSame($freshEntry, $freshAbility);
    }

    public function testTheSendResultCarriesThePauseAndTheHoldOnEveryPath(): void
    {
        [$event, $body] = $this->ready();
        $input = ['event_id' => $event, 'body_id' => $body, 'codes' => ['00A0'], 'request_key' => 'tesztkulcs02'];

        $fresh = (new ManualMails())->send($input, $this->admin, $this->runner);
        self::assertFalse($fresh['paused']);
        self::assertFalse($fresh['held']);

        $this->exec(new PauseMail(), []);
        $this->transition(new \Kalaka\Events\UnpublishEvent(), $event);
        $repeat = (new ManualMails())->send($input, $this->admin, $this->runner);
        self::assertTrue($repeat['repeat']);
        self::assertTrue($repeat['paused']);
        self::assertTrue($repeat['held']);
        self::assertSame($fresh['batch_id'], $repeat['batch_id']);
    }

    public function testAGrantHolderPassesTheGateButIsRefusedTheSiteLevelAbilities(): void
    {
        [$event] = $this->ready();
        $this->as($this->holder($event));
        $before = count($this->entries());

        $inputs = [
            'update-action-mail' => ['event_id' => $event, 'mail' => 'registered', 'enabled' => false],
            'set-event-mail-layout' => ['event_id' => $event, 'html' => '{{content}}'],
            'pause-mail' => [],
            'resume-mail' => [],
            'set-mail-budget' => ['hourly_budget' => 100],
            'clear-mail-queue' => [],
            'clear-event-mail' => ['event_id' => $event],
        ];
        foreach ($inputs as $slug => $input) {
            $ability = $this->ability('kalaka/' . $slug);
            self::assertTrue($ability->check_permissions($input), $slug);
            $json = (string) wp_json_encode($input === [] ? new \stdClass() : $input);
            $response = $this->runAbility('kalaka/' . $slug, $json);
            self::assertSame(403, $response->get_status(), $slug);
        }
        self::assertNull($this->stateRow());
        // Only the refusals were audited, each as one entry that did not succeed.
        $refused = array_slice($this->entries(), $before);
        self::assertCount(count($inputs), $refused);
        foreach ($refused as $entry) {
            self::assertNotSame('succeeded', $entry['outcome']);
        }
    }

    public function testAGrantHolderSendsInsideTheViewAndLearnsThatTheMailWaits(): void
    {
        [$event, $body] = $this->ready();
        $this->as($this->holder($event));
        $this->transition(new \Kalaka\Events\UnpublishEvent(), $event);
        $this->exec(new PauseMail(), []);

        $result = $this->ability('kalaka/send-manual-mail')->execute(
            ['event_id' => $event, 'body_id' => $body, 'codes' => ['00A0', '00A2']]
        );

        self::assertIsArray($result);
        self::assertSame(2, $result['queued']);
        self::assertTrue($result['paused']);
        self::assertTrue($result['held']);
        self::assertCount(2, $this->deliveries());
    }

    public function testAnUnknownCodeAndACodeOutsideTheViewGiveTheSameRefusal(): void
    {
        [$event, $body] = $this->ready();
        $this->registration($event, '00A8', status: 'withdrawn');
        $this->as($this->holder($event));

        $refusals = [];
        foreach (['00A5', '00A8'] as $code) {
            $input = (string) wp_json_encode(['event_id' => $event, 'body_id' => $body, 'codes' => ['00A0', $code]]);
            $response = $this->runAbility('kalaka/send-manual-mail', $input);
            $data = $response->get_data();
            $refusals[] = [$response->get_status(), $data['code'] ?? null, $data['message'] ?? null];
        }

        self::assertSame(403, $refusals[0][0]);
        self::assertSame($refusals[0], $refusals[1]);
        self::assertSame([], $this->deliveries());
    }

    public function testThePauseTheResumeAndTheClearGiveTheirValuesThroughTheRunEndpoint(): void
    {
        $event = $this->published();
        $this->as($this->admin->userId);

        $paused = $this->runAbility('kalaka/pause-mail');
        self::assertSame(200, $paused->get_status());
        self::assertTrue($paused->get_data());
        self::assertFalse($this->runAbility('kalaka/pause-mail')->get_data());
        self::assertTrue($this->runAbility('kalaka/resume-mail')->get_data());
        self::assertFalse($this->runAbility('kalaka/resume-mail')->get_data());

        self::assertSame(0, $this->runAbility('kalaka/clear-mail-queue')->get_data());
        $this->queue($event, '00A0');
        $this->queue($event, '00A2');
        self::assertSame(2, $this->runAbility('kalaka/clear-mail-queue')->get_data());
    }

    public function testTheClearOfAPublishedEventIsTheTypedRefusal(): void
    {
        $event = $this->published();
        $this->as($this->admin->userId);

        $response = $this->runAbility('kalaka/clear-event-mail', (string) wp_json_encode(['event_id' => $event]));

        self::assertSame(422, $response->get_status());
        self::assertSame('kalaka_mail_not_held', $response->get_data()['code'] ?? null);
        self::assertSame(
            'A művelet nem hajtható végre. Csak nem közzétett esemény küldésre váró levelei törölhetők.',
            $response->get_data()['message'] ?? null
        );
    }

    /** Through the run endpoint, any wrong value is the typed refusal, never core's schema refusal. */
    public function testThroughTheRunEndpointAnyWrongValueIsTheTypedRefusalAndDigitsCount(): void
    {
        $this->as($this->admin->userId);
        foreach (['"1.5"', '""', '"abc"', '1.5', 'null', 'true', '0', '2001'] as $bad) {
            $response = $this->runAbility('kalaka/set-mail-budget', '{"hourly_budget":' . $bad . '}');
            self::assertSame(422, $response->get_status(), 'Status for ' . $bad);
            self::assertSame('kalaka_mail_budget_invalid', $response->get_data()['code'] ?? null, 'Code for ' . $bad);
        }
        self::assertTrue($this->runAbility('kalaka/set-mail-budget', '{"hourly_budget":"300"}')->get_data());
        self::assertSame('300', $this->stateRow()['hourly_budget'] ?? null);
    }
}
