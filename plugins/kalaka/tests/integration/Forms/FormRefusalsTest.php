<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;

require_once __DIR__ . '/FormsTestCase.php';

/** AC5: what keeps a form from being used right now. */
final class FormRefusalsTest extends FormsTestCase
{
    /** @return iterable<string, array{string}> */
    public static function zones(): iterable
    {
        yield 'Europe/Budapest' => ['Europe/Budapest'];
        yield 'UTC' => ['UTC'];
    }

    /**
     * @param list<string> $roles
     * @return list<string> the codes of the refusals
     */
    private function refusalCodes(int $formId, int $eventId, \DateTimeImmutable $now, array $roles = [], bool $password = false): array
    {
        $event = $this->events->find($eventId);
        self::assertNotNull($event);

        return array_map(
            static fn (AccessRefusal $refusal): string => $refusal->value,
            $this->forms->refusals($this->readForm($formId), $event, $now, $roles, $password)
        );
    }

    private function additionalForm(): int
    {
        return $this->insertForm($this->field('text', 'note'), ['kalaka_placement' => 'additional']);
    }

    /** @dataProvider zones */
    public function testTheSlotFormIsOpenToTheEndOfTheClosingDayAndRefusedFromTheNextMidnight(string $zone): void
    {
        update_option('timezone_string', $zone);
        $event = $this->published();
        $slot = $this->slotForm();

        self::assertSame([], $this->refusalCodes($slot, $event, $this->local('2026-12-15 23:59:59', $zone)));
        self::assertSame([], $this->refusalCodes($slot, $event, $this->local('2026-11-30 00:00:00', $zone)));
        self::assertSame(
            ['outside_registration_window'],
            $this->refusalCodes($slot, $event, $this->local('2026-12-16 00:00:00', $zone))
        );
        self::assertSame(
            ['outside_registration_window'],
            $this->refusalCodes($slot, $event, $this->local('2026-11-29 23:59:59', $zone))
        );
    }

    /** @dataProvider zones */
    public function testAnAdditionalFormIsNotBoundToTheRegistrationWindow(string $zone): void
    {
        update_option('timezone_string', $zone);
        $event = $this->published();
        $additional = $this->additionalForm();

        self::assertSame([], $this->refusalCodes($additional, $event, $this->local('2026-12-16 00:00:00', $zone)));
        self::assertSame([], $this->refusalCodes($additional, $event, $this->local('2026-01-01 00:00:00', $zone)));
    }

    /** @return iterable<string, array{string}> */
    public static function eventsNotOpen(): iterable
    {
        yield 'a draft' => ['filled'];
        yield 'an unpublished event' => ['unpublished'];
        yield 'an archived event' => ['archived'];
    }

    /** @dataProvider eventsNotOpen */
    public function testAnEventThatIsNotPublishedRefusesWithThatAloneEvenWithOtherRequirementsUnmet(string $reach): void
    {
        $event = $this->{$reach}();
        $now = $this->local('2026-12-01 12:00:00');
        $slot = $this->insertForm($this->slotFields(), ['kalaka_required_role' => 'editor'], ['post_password' => 'titok']);
        $additional = $this->additionalForm();

        self::assertSame(['event_not_open'], $this->refusalCodes($slot, $event, $now));
        self::assertSame(['event_not_open'], $this->refusalCodes($slot, $event, $this->local('2027-03-01 12:00:00')));
        self::assertSame(['event_not_open'], $this->refusalCodes($additional, $event, $now));
    }

    public function testAClosedSiteRefusesWithThatAlone(): void
    {
        $event = $this->published();
        $slot = $this->insertForm($this->slotFields(), [], ['post_password' => 'titok']);
        $additional = $this->additionalForm();
        $now = $this->local('2026-12-01 12:00:00');
        self::assertSame(['password_required'], $this->refusalCodes($slot, $event, $now));

        try {
            LoadState::record([new LoadFailure('x', 'y')]);

            self::assertSame(['event_not_open'], $this->refusalCodes($slot, $event, $now));
            self::assertSame(['event_not_open'], $this->refusalCodes($additional, $event, $now));
        } finally {
            LoadState::record([]);
        }

        self::assertSame(['password_required'], $this->refusalCodes($slot, $event, $now));
    }

    public function testAFormWithAPostPasswordNeedsItGiven(): void
    {
        $event = $this->published();
        $now = $this->local('2026-12-01 12:00:00');
        $form = $this->insertForm($this->slotFields(), [], ['post_password' => 'titok']);

        self::assertSame(['password_required'], $this->refusalCodes($form, $event, $now, [], false));
        self::assertSame([], $this->refusalCodes($form, $event, $now, [], true));
        self::assertSame([], $this->refusalCodes($this->slotForm(), $event, $now, [], false));
    }

    public function testAFormWithARequiredRoleNeedsIt(): void
    {
        $event = $this->published();
        $now = $this->local('2026-12-01 12:00:00');
        $form = $this->insertForm($this->slotFields(), ['kalaka_required_role' => 'editor']);

        self::assertSame(['role_required'], $this->refusalCodes($form, $event, $now, ['subscriber']));
        self::assertSame(['role_required'], $this->refusalCodes($form, $event, $now, []));
        self::assertSame([], $this->refusalCodes($form, $event, $now, ['editor']));
        self::assertSame([], $this->refusalCodes($form, $event, $now, ['subscriber', 'editor']));
    }

    public function testSeveralUnmetRequirementsAreAllReturnedInTheOrderOfTheDecision(): void
    {
        $event = $this->published();
        $form = $this->insertForm($this->slotFields(), ['kalaka_required_role' => 'editor'], ['post_password' => 'titok']);

        self::assertSame(
            ['outside_registration_window', 'password_required', 'role_required'],
            $this->refusalCodes($form, $event, $this->local('2026-12-16 00:00:00'), ['subscriber'], false)
        );
        self::assertSame(
            ['outside_registration_window', 'role_required'],
            $this->refusalCodes($form, $event, $this->local('2026-12-16 00:00:00'), [], true)
        );
        self::assertSame(
            ['password_required', 'role_required'],
            $this->refusalCodes($form, $event, $this->local('2026-12-01 12:00:00'), [], false)
        );
    }

    public function testReadingRefusalsReadsNoUserCookieOrRequestAndTouchesNoDatabase(): void
    {
        $event = $this->published();
        $form = $this->readForm($this->insertForm($this->slotFields(), ['kalaka_required_role' => 'editor']));
        $eventRead = $this->events->find($event);
        self::assertNotNull($eventRead);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $_COOKIE['wp-postpass_' . COOKIEHASH] = 'anything';

        $statements = $this->statementsDuring(function () use ($form, $eventRead): void {
            $codes = array_map(
                static fn (AccessRefusal $refusal): string => $refusal->value,
                $this->forms->refusals($form, $eventRead, $this->local('2026-12-01 12:00:00'), [], false)
            );
            self::assertSame(['role_required'], $codes);
        });

        self::assertSame([], $statements);
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    }
}
