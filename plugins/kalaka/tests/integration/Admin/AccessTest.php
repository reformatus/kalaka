<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** S36-7, S36-9 and S36-11: the read seam for the screens and the fine check. */
final class AccessTest extends GrantTestCase
{
    private int $event;

    private int $user;

    private Access $access;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        Access::forget();
        $this->access = new Access();
        $this->event = $this->createEvent();
        $this->user = $this->plainUser();
        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'module', 'építő');
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'module', 'konyha');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Access::forget();

        parent::tear_down();
    }

    public function testFullAccessIsTheSystemTheCommandLineWithoutAUserAndAHolder(): void
    {
        self::assertTrue($this->access->isFull(new SystemActor()));
        self::assertTrue($this->access->isFull(new CliActor(null)));
        self::assertTrue($this->access->isFull($this->admin));
        self::assertFalse($this->access->isFull(new UserActor($this->user)));
        self::assertFalse($this->access->isFull(new CliActor($this->user)));
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        self::assertFalse($this->access->isFull(new UserActor($administrator)));
    }

    public function testTheHeldViewsAreTheUsersGrantsOnTheEventThatHoldSomething(): void
    {
        $view = $this->parameterView($this->event);
        $first = $this->saveGrant($this->event, $this->user, ['view_id' => $view, 'bound_value' => 'építő']);
        $master = $this->saveGrant($this->event, $this->user);
        $this->saveGrant($this->event, $this->plainUser());
        $other = $this->createEvent('Másik');
        $this->saveGrant($other, $this->user);
        // A grant whose view is gone holds nothing.
        $plain = $this->plainView($this->event);
        $this->saveGrant($this->event, $this->user, ['view_id' => $plain]);
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_views', ['id' => $plain]);

        $held = $this->access->heldViews(new UserActor($this->user), $this->event);

        self::assertSame([$first, $master], array_map(static fn (HeldView $view): ?int => $view->grantId, $held));
        self::assertSame([$view, null], array_map(static fn (HeldView $view): ?int => $view->viewId, $held));
        self::assertSame(['Nézet', null], array_map(static fn (HeldView $view): ?string => $view->name, $held));
        self::assertFalse($held[0]->isMaster());
        self::assertTrue($held[1]->isMaster());
        self::assertSame([$this->event, $other], $this->access->grantedEventIds(new UserActor($this->user)));
        self::assertSame([], $this->access->grantedEventIds(new SystemActor()));
        self::assertSame([], $this->access->heldViews(new SystemActor(), $this->event));
    }

    /** S36-9: a grant holder counts and reads only their own view's rows. */
    public function testAHeldViewCountsAndReadsOnlyWhatItsFilterMatches(): void
    {
        $this->saveGrant($this->event, $this->user, [
            'view_id' => $this->parameterView($this->event),
            'bound_value' => 'konyha',
        ]);
        [$held] = $this->access->heldViews(new UserActor($this->user), $this->event);
        $query = new ViewQuery();

        self::assertSame(1, $query->count($held));
        $page = $query->page($held);
        self::assertSame(1, $page->total);
        self::assertSame('00A2', $page->rows[0]->key->code);
        self::assertSame(2, $query->count($this->access->master($this->admin, $this->event)));
    }

    public function testOnlyFullAccessGetsTheMasterView(): void
    {
        $this->saveGrant($this->event, $this->user);

        self::assertTrue($this->access->master($this->admin, $this->event)->isMaster());
        $this->expectException(PermissionDenied::class);
        $this->access->master(new UserActor($this->user), $this->event);
    }

    /** S36-2: full access or any grant reaches the admin side. */
    public function testWhoMayReachTheAdminSide(): void
    {
        self::assertTrue(Access::mayReachAdmin($this->admin->userId));
        self::assertFalse(Access::mayReachAdmin($this->user));
        self::assertFalse(Access::mayReachAdmin(0));

        $holder = $this->plainUser();
        $this->saveGrant($this->event, $holder);
        self::assertTrue(Access::mayReachAdmin($holder));
    }

    /** S36-11: the fine check reads the filter under the registration's lock. */
    public function testTheFineCheckNeedsALockedRegistrationAndAReadWriteColumnOfAViewThatHoldsIt(): void
    {
        $view = $this->exec(new \Kalaka\Admin\View\SaveView(), [
            'event_id' => $this->event,
            'name' => 'Modul',
            'filter' => [['column' => 'field:module', 'parameter' => true]],
            'columns' => [
                ['column' => 'field:module', 'write' => false],
                ['column' => 'field:name', 'write' => true],
            ],
        ]);
        $this->saveGrant($this->event, $this->user, ['view_id' => $view, 'bound_value' => 'építő']);
        $actor = new UserActor($this->user);
        $a001 = new RegistrationKey($this->event, '00A0');
        $a002 = new RegistrationKey($this->event, '00A2');

        $body = function (array $input, Context $context) use ($actor, $a001, $a002): array {
            $this->lockRegistrations($context, $a001, $a002);

            return [
                $this->access->mayWrite($actor, $a001, 'field:name', $context),
                $this->access->mayWrite($actor, $a001, 'field:module', $context),
                $this->access->mayWrite($actor, $a002, 'field:name', $context),
                $this->access->mayWrite($this->admin, $a002, 'field:name', $context),
            ];
        };
        $result = $this->runner->run(new AdminProbe($body), [], $this->admin);

        self::assertSame([true, false, false, true], $result);

        $this->expectException(SystemFailure::class);
        $this->runner->run(new AdminProbe(
            fn (array $input, Context $context): bool => $this->access->mayWrite($actor, $a001, 'field:name', $context)
        ), [], $this->admin);
    }
}
