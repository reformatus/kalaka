<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Tests\Integration\Admin\GrantTestCase;

/**
 * The manual mail's `selection` (owner ruling 2026-11-26): the listed codes, or the rows of a held view that the
 * grid's filters and search leave, across all pages, resolved under the viewer's held view and grant.
 */
final class ManualMailSelectionTest extends GrantTestCase
{
    private const ACTION = 'kalaka_manual_mail';

    private int $event;

    private int $user;

    private int $body;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        ActionCatalogue::record(new ActionCatalogue([self::EDIT, self::ACTION]));
        Access::forget();
        $this->event = $this->createEvent();
        $this->user = $this->plainUser();
        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'module', 'építő');
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'module', 'konyha');
        $this->addRegistration($this->event, '00A5', 'waitlisted');
        $this->addValue($this->event, '00A5', 'module', 'építő');
        $this->addRegistration($this->event, '00A8');
        $this->addValue($this->event, '00A8', 'module', 'építő');
        $id = wp_insert_post([
            'post_type' => 'kalaka_mail',
            'post_status' => 'publish',
            'post_title' => 'Levél',
            'post_content' => '<!-- wp:paragraph --><p>Szia</p><!-- /wp:paragraph -->',
            'meta_input' => ['kalaka_event_id' => $this->event],
        ]);
        self::assertIsInt($id);
        $this->body = $id;
    }

    /** @param array<string, mixed> $selection */
    private function preview(array $selection, ?Actor $actor = null): array
    {
        return (new ManualMails())->preview(
            ['event_id' => $this->event, 'selection' => $selection],
            $actor ?? $this->admin
        );
    }

    /** @param array<string, mixed> $selection */
    private function send(array $selection, ?Actor $actor = null): array
    {
        return (new ManualMails())->send(
            ['event_id' => $this->event, 'body_id' => $this->body, 'selection' => $selection],
            $actor ?? $this->admin,
            $this->runner
        );
    }

    /** @return list<string> */
    private static function sorted(array $codes): array
    {
        sort($codes);

        return $codes;
    }

    /** The grant holder of the `építő` module: a view of the code column, the module as its parameter. */
    private function holderGrant(array $actions = [self::ACTION]): int
    {
        return $this->saveGrant($this->event, $this->user, [
            'view_id' => $this->parameterView($this->event),
            'bound_value' => 'építő',
            'actions' => $actions,
        ]);
    }

    private function viewOfGrant(int $grant): int
    {
        foreach ((new Access())->heldViews(new UserActor($this->user), $this->event) as $held) {
            if ($held->grantId === $grant) {
                return (int) $held->viewId;
            }
        }
        self::fail('The grant holds no view.');
    }

    public function testTheCodesFormStaysAsItWasAndTheCountIsTheNumberOfCodes(): void
    {
        $plan = $this->preview(['codes' => ['00A0', '00A5', '00A3']]);

        self::assertSame(3, $plan['count']);
        // 7.1: a waitlisted registration is skipped and listed, not sent to.
        self::assertSame(['00A0'], $plan['queue']);
        self::assertSame([['code' => '00A5', 'reason' => 'waitlisted']], $plan['skipped']);
        self::assertSame(['00A3'], $plan['unknown']);
    }

    public function testTheViewFormWithoutFiltersTakesTheWholeView(): void
    {
        $plan = $this->preview(['view' => 0]);

        self::assertSame(4, $plan['count']);
        self::assertSame(['00A0', '00A2', '00A8'], self::sorted($plan['queue']));
        self::assertSame([['code' => '00A5', 'reason' => 'waitlisted']], $plan['skipped']);
        self::assertSame(4, $this->preview(['view' => 0, 'grant' => null, 'filters' => [], 'search' => ''])['count']);
    }

    public function testTheViewFormNarrowsByTheGridsFiltersAndSearch(): void
    {
        $registered = [['field' => 'status', 'operator' => 'isAny', 'value' => ['registered']]];

        $byFilter = $this->preview(['view' => 0, 'filters' => $registered]);
        self::assertSame(3, $byFilter['count']);
        self::assertSame(['00A0', '00A2', '00A8'], self::sorted($byFilter['queue']));

        $bySearch = $this->preview(['view' => 0, 'search' => 'konyha']);
        self::assertSame(['00A2'], $bySearch['queue']);
        self::assertSame(1, $bySearch['count']);

        $both = $this->preview(['view' => 0, 'filters' => $registered, 'search' => 'építő']);
        self::assertSame(['00A0', '00A8'], self::sorted($both['queue']));

        $none = $this->preview(['view' => 0, 'search' => 'nincsilyen']);
        self::assertSame(0, $none['count']);
        self::assertSame([], $none['queue']);
    }

    public function testTheSendTakesTheRowsTheViewHasAtSendTime(): void
    {
        $registered = [['field' => 'status', 'operator' => 'isAny', 'value' => ['registered']]];
        $selection = ['view' => 0, 'filters' => $registered];
        self::assertSame(3, $this->preview($selection)['count']);

        // The data moves after the preview: the send follows it.
        $this->addRegistration($this->event, ReferenceCode::fourCharacterCodes()[100]);
        $result = $this->send($selection);

        self::assertSame(4, $result['queued']);
        self::assertSame([], $result['skipped']);
    }

    public function testASendOfASelectionThatMatchesNothingIsRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->send(['view' => 0, 'search' => 'nincsilyen']);
    }

    public function testAGrantHoldersViewNeverReachesRowsTheViewHides(): void
    {
        $grant = $this->holderGrant();
        $view = $this->viewOfGrant($grant);
        $holder = new UserActor($this->user);

        $plan = $this->preview(['view' => $view, 'grant' => $grant], $holder);
        self::assertSame(['00A0', '00A8'], self::sorted($plan['queue']));
        self::assertSame(3, $plan['count']);
        // A search for a row of another module finds nothing: the bound value stays in force.
        self::assertSame(0, $this->preview(['view' => $view, 'grant' => $grant, 'search' => '00A2'], $holder)['count']);

        $result = $this->send(['view' => $view, 'grant' => $grant], $holder);
        self::assertSame(2, $result['queued']);
        self::assertSame([['code' => '00A5', 'reason' => 'waitlisted']], $result['skipped']);
    }

    public function testAGrantHolderCannotNameAViewOrGrantTheyDoNotHold(): void
    {
        $grant = $this->holderGrant();
        $view = $this->viewOfGrant($grant);
        $holder = new UserActor($this->user);

        foreach (
            [
                ['view' => 0, 'grant' => $grant],
                ['view' => $view],
                ['view' => $view, 'grant' => null],
                ['view' => $view, 'grant' => $grant + 1000],
            ] as $selection
        ) {
            try {
                $this->preview($selection, $holder);
                self::fail('The selection was allowed.');
            } catch (PermissionDenied) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAGrantHolderWhoseGrantLacksTheActionIsRefusedOnTheResolvedCodes(): void
    {
        $grant = $this->holderGrant([self::EDIT]);
        $view = $this->viewOfGrant($grant);

        $this->expectException(PermissionDenied::class);
        $this->send(['view' => $view, 'grant' => $grant], new UserActor($this->user));
    }

    public function testAFilterOnAColumnTheViewDoesNotListIsRefusedAsTheGridRefusesIt(): void
    {
        $grant = $this->holderGrant();
        $view = $this->viewOfGrant($grant);
        $holder = new UserActor($this->user);

        foreach (
            [
                [['field' => 'field:module', 'operator' => 'contains', 'value' => 'a']],
                [['field' => 'code', 'operator' => 'isAny', 'value' => ['a']]],
                '[{',
            ] as $filters
        ) {
            try {
                $this->preview(['view' => $view, 'grant' => $grant, 'filters' => $filters], $holder);
                self::fail('The filter was allowed.');
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidInput::class);
        $this->send(['view' => 0, 'filters' => [['field' => 'nincs', 'operator' => 'contains', 'value' => 'a']]]);
    }

    public function testAStoredViewIsOpenedByFullAccessAndAnotherEventsViewIsNotFound(): void
    {
        $view = $this->exec(new SaveView(), [
            'event_id' => $this->event,
            'name' => 'Nézet',
            'filter' => [['column' => 'status', 'in' => ['waitlisted']]],
            'columns' => [['column' => 'code', 'write' => false]],
        ]);
        self::assertIsInt($view);

        $waitlisted = $this->preview(['view' => $view]);
        self::assertSame([], $waitlisted['queue']);
        self::assertSame([['code' => '00A5', 'reason' => 'waitlisted']], $waitlisted['skipped']);

        $other = $this->createEvent('Másik');
        $this->expectException(NotFound::class);
        (new ManualMails())->preview(['event_id' => $other, 'selection' => ['view' => $view]], $this->admin);
    }

    public function testAMalformedSelectionIsRefused(): void
    {
        foreach (
            [
                [],
                ['codes' => []],
                ['codes' => ['00A0'], 'view' => 0],
                ['view' => -1],
                ['view' => 0, 'search' => str_repeat('a', 101)],
                ['view' => 0, 'extra' => 1],
            ] as $selection
        ) {
            try {
                $this->preview($selection);
                self::fail('The selection was allowed.');
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMoreThanAThousandCodesWorkInBothForms(): void
    {
        $codes = array_slice(ReferenceCode::fourCharacterCodes(), 0, 1100);
        foreach ($codes as $code) {
            if (!in_array($code, ['00A0', '00A2', '00A5', '00A8'], true)) {
                $this->addRegistration($this->event, $code);
            }
        }
        $codes = array_values(array_unique(array_merge($codes, ['00A0', '00A2', '00A5', '00A8'])));
        $total = count($codes);
        self::assertGreaterThan(1000, $total);

        self::assertSame($total, $this->preview(['codes' => $codes])['count']);
        self::assertSame($total, $this->preview(['view' => 0])['count']);

        $result = $this->send(['view' => 0]);
        // One of the registrations is waitlisted, and a waitlisted one is skipped (7.1).
        self::assertSame($total - 1, $result['queued']);
        self::assertSame([['code' => '00A5', 'reason' => 'waitlisted']], $result['skipped']);
    }
}
