<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SeatsFull;

/**
 * The one seat rule, on its own (`Api\Seats`): a seat is a fact taken inside the registration's own act under
 * the pool's row, freed by leaving, and never handed over. The pool here is a stand-in with a settable limit;
 * the real pools (an option's, the event's) are tested with their modules.
 */
final class SeatsTest extends RegistrationsTestCase
{
    private Seats $seats;

    private int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->seats = new Seats();
        $this->event = (int) $this->runner->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
    }

    /**
     * @param array{0: string, 1: list<int|string>}|null $row the pool's lock row; by default a row of its own key
     */
    private function pool(
        ?int $limit,
        string $key = 'tier.tamogatott',
        ?array $row = null,
        LockLevel $level = LockLevel::Quota,
    ): SeatPool {
        return new class ($this->event, $key, $limit, $row ?? ['kalaka_stand_in', [$key]], $level) implements SeatPool {
            public int $locks = 0;

            /** @param array{0: string, 1: list<int|string>} $row */
            public function __construct(
                private int $event,
                private string $key,
                private ?int $limit,
                private array $row,
                private LockLevel $level,
            ) {
            }

            public function lockRow(): array
            {
                return $this->row;
            }

            public function eventId(): int
            {
                return $this->event;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function lockLevel(): LockLevel
            {
                return $this->level;
            }

            public function limit(): ?int
            {
                return $this->limit;
            }

            public function lock(Context $context): ?int
            {
                $this->locks++;

                return $this->limit;
            }
        };
    }

    /**
     * Runs the work in one act on registration `$code` (a new `registered` one, so its row is held).
     *
     * @param \Closure(Context, RegistrationKey): mixed $work
     */
    private function act(string $code, \Closure $work): mixed
    {
        return $this->inRun(function (array $input, Context $context) use ($code, $work): mixed {
            $key = new RegistrationKey($this->event, $code);
            if ($this->registrations->find($key) === null) {
                $this->insertRegistration($this->newRegistration($this->event, $code), $context);
            } else {
                $this->registrations->findForUpdate($key, $context);
            }

            return $work($context, $key);
        });
    }

    /** The registration's own act that takes a seat in the pool by the given `Seats` method. */
    private function by(string $code, string $method, SeatPool $pool, SeatAct $act = SeatAct::Submit): mixed
    {
        return $this->act(
            $code,
            fn (Context $c, RegistrationKey $k): mixed => $this->seats->$method($pool, $k, $act, $c)
        );
    }

    public function testASeatIsTakenUnderTheLockOfThePoolAndCounted(): void
    {
        $pool = $this->pool(2);

        $this->by('00A0', 'take', $pool);
        self::assertSame(1, $this->seats->held($pool));
        self::assertSame(1, $this->seats->left($pool));
        self::assertTrue($this->seats->holds($pool, new RegistrationKey($this->event, '00A0')));
        self::assertSame(1, $pool->locks);
    }

    public function testAFullPoolRefusesATakeAndGivesNoSeatToATakeIfFree(): void
    {
        $pool = $this->pool(1);
        $this->by('00A0', 'take', $pool);

        try {
            $this->by('00A2', 'take', $pool);
            self::fail('A full pool should refuse.');
        } catch (SeatsFull $full) {
            self::assertSame('Ez a lehetőség időközben betelt. Válassz másikat.', $full->getMessage());
        }
        $gave = $this->by('00A2', 'takeIfFree', $pool, SeatAct::Restore);

        self::assertFalse($gave);
        self::assertSame(1, $this->seats->held($pool));
        self::assertFalse($this->seats->holds($pool, new RegistrationKey($this->event, '00A2')));
    }

    public function testStaffMayPlaceOverTheLimitAndThePoolStandsOverIt(): void
    {
        $pool = $this->pool(1);
        $this->by('00A0', 'take', $pool);
        $this->by('00A2', 'takeOver', $pool, SeatAct::Placement);

        self::assertSame(2, $this->seats->held($pool));
        self::assertSame(0, $this->seats->left($pool));
        self::assertSame(1, $this->seats->over($pool));
    }

    public function testALeaverFreesItsSeatAndNobodyMovesInByThemselves(): void
    {
        $pool = $this->pool(1);
        $this->by('00A0', 'take', $pool);
        $this->by('00A2', 'takeIfFree', $pool);

        $this->act('00A0', fn (Context $c, RegistrationKey $k) => $this->seats->release($pool, $k, $c));

        self::assertSame(0, $this->seats->held($pool));
        self::assertSame(1, $this->seats->left($pool));
        self::assertFalse($this->seats->holds($pool, new RegistrationKey($this->event, '00A2')));
        // The next act that asks takes it.
        self::assertTrue($this->by('00A2', 'takeIfFree', $pool, SeatAct::Restore));
        self::assertSame(['tier.tamogatott'], $this->seats->poolsOf(new RegistrationKey($this->event, '00A2')));
    }

    public function testAWithdrawalFreesEverySeatTheRegistrationHolds(): void
    {
        $tier = $this->pool(3, 'tier.a');
        $offer = $this->pool(3, 'offer.b');
        $other = $this->pool(3, 'tier.c');
        $this->act('00A0', function (Context $c, RegistrationKey $k) use ($tier, $offer): void {
            $this->seats->take($tier, $k, SeatAct::Submit, $c);
            $this->seats->take($offer, $k, SeatAct::Submit, $c);
        });
        $this->by('00A2', 'take', $other);

        $this->act('00A0', fn (Context $c, RegistrationKey $k) => $this->seats->releaseAll($k, $c));

        self::assertSame([], $this->seats->poolsOf(new RegistrationKey($this->event, '00A0')));
        self::assertSame(0, $this->seats->held($tier) + $this->seats->held($offer));
        self::assertSame(1, $this->seats->held($other));
    }

    public function testTakingASeatTwiceKeepsOne(): void
    {
        $pool = $this->pool(1);
        $this->act('00A0', function (Context $c, RegistrationKey $k) use ($pool): void {
            $this->seats->take($pool, $k, SeatAct::Submit, $c);
            $this->seats->take($pool, $k, SeatAct::Restore, $c);
        });

        self::assertSame(1, $this->seats->held($pool));
    }

    public function testNoLimitMeansNoRefusal(): void
    {
        $pool = $this->pool(null);
        foreach (['00A0', '00A2'] as $code) {
            $this->by($code, 'take', $pool);
        }

        self::assertSame(2, $this->seats->held($pool));
        self::assertNull($this->seats->left($pool));
        self::assertSame(0, $this->seats->over($pool));
    }

    public function testASeatChangesOnlyInsideAnActThatHoldsTheRegistration(): void
    {
        $this->insertRegistration($this->newRegistration($this->event, '00A0'));
        $pool = $this->pool(1);

        $this->expectException(SystemFailure::class);
        $this->inRun(fn (array $input, Context $c) => $this->seats->take(
            $pool,
            new RegistrationKey($this->event, '00A0'),
            SeatAct::Submit,
            $c
        ));
    }

    public function testTheEventsSeatsAreItsRegisteredRegistrationsAndNoRowIsWritten(): void
    {
        $this->inRun(function (array $input, Context $c): void {
            $this->registrations->upsert($this->newRegistration($this->event, '00A0'), $c);
            $waitlisted = $this->newRegistration($this->event, '00A2', RegistrationStatus::Waitlisted);
            $this->registrations->upsert($waitlisted, $c);
        });
        $event = $this->seats->eventPool($this->event);

        self::assertSame(1, $this->seats->held($event));
        self::assertSame(SeatPool::EVENT, $event->key());
        $this->setStatus(new RegistrationKey($this->event, '00A2'), RegistrationStatus::Registered);
        self::assertSame(2, $this->seats->held($event));
        $this->setStatus(new RegistrationKey($this->event, '00A0'), RegistrationStatus::Withdrawn);
        self::assertSame(1, $this->seats->held($event));

        $this->expectException(SystemFailure::class);
        $this->by('00A2', 'take', $event);
    }

    public function testTheEventPoolIsHeldByARegisteredRegistrationAndNoOther(): void
    {
        $this->inRun(function (array $input, Context $c): void {
            $this->registrations->upsert($this->newRegistration($this->event, '00A0'), $c);
            $waitlisted = $this->newRegistration($this->event, '00A2', RegistrationStatus::Waitlisted);
            $this->registrations->upsert($waitlisted, $c);
        });
        $event = $this->seats->eventPool($this->event);

        self::assertTrue($this->seats->holds($event, new RegistrationKey($this->event, '00A0')));
        self::assertFalse($this->seats->holds($event, new RegistrationKey($this->event, '00A2')));
        self::assertFalse($this->seats->holds($event, new RegistrationKey($this->event, '00A5')));
    }

    public function testClaimsAreOrderedAsTheRunnersGuardComparesRows(): void
    {
        $other = $this->pool(1, 'offer.1', ['kalaka_test_b', [1]]);
        $ten = $this->pool(1, 'tier.10', ['kalaka_test_a', [10]]);
        $nine = $this->pool(1, 'tier.9', ['kalaka_test_a', ['9']]);
        $option = $this->pool(1, 'option.1.a', ['kalaka_assignment_options', [1, 'a']], LockLevel::Other);
        $claims = [
            new SeatClaim('o', $option),
            new SeatClaim('b', $other),
            new SeatClaim('t10', $ten),
            new SeatClaim('t9', $nine),
        ];

        $ordered = array_map(
            static fn (SeatClaim $claim): string => $claim->fieldKey,
            $this->seats->inLockOrder($claims)
        );

        // Quota rows first, by table and then by number (9 before 10, though "tier.10" sorts first as text).
        self::assertSame(['t9', 't10', 'b', 'o'], $ordered);
    }
}
