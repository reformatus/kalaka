<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\PriceLadder\Api\PriceLadder;
use Kalaka\PriceLadder\PriceLadderComponent;
use Kalaka\PriceLadder\TierClaims;
use Kalaka\PriceLadder\TierSeatPool;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;

/**
 * A tier's optional limit of people (0 or more): the setting, the form variable served from the claim source's
 * pools, a limit added over registrations that already chose the tier, the refusals of a save (a chosen tier
 * removed, `price_tier` taken by another field) and the seat of a registration that is admitted or restored into a
 * full tier (O4: it keeps its tier and owes the tier's price). A tier with no limit is unchanged.
 */
final class TierQuotaTest extends PriceLadderTestCase
{
    private Seats $seats;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->seats = new Seats();
    }

    /** @return list<array<string, mixed>> */
    private static function tiers(?int $limit): array
    {
        return [
            self::tier('alap', 'Alap ár', '49500'),
            self::tier('tamogatott', 'Támogatott ár', '20000') + ($limit === null ? [] : ['limit' => $limit]),
        ];
    }

    private function pool(): TierSeatPool
    {
        return new TierSeatPool($this->event, 'tamogatott');
    }

    /** A registration that chose the tier, holding its seat the way a submit does (or over the limit). */
    private function holdSeat(RegistrationKey $key, bool $over = false): void
    {
        $this->inRun(function (array $input, Context $context) use ($key, $over): void {
            $this->seats->lock($this->pool(), $context);
            $this->lockRegistrations($context, $key);
            if ($over) {
                $this->seats->takeOver($this->pool(), $key, SeatAct::Placement, $context);

                return;
            }
            $this->seats->take($this->pool(), $key, SeatAct::Submit, $context);
        });
    }

    private function choosing(string $code, string $tier): RegistrationKey
    {
        $key = $this->register($code, RegistrationStatus::Waitlisted);
        $this->writeValue($key, PriceLadderComponent::FIELD, $tier);
        $this->setStatus($key, RegistrationStatus::Registered, '2026-11-30 08:00:00');

        return $key;
    }

    public function testALimitIsSavedReadBackAndShownInTheSettingsDocument(): void
    {
        $this->applyLadder(self::tiers(2));

        $tiers = $this->ladder->tiers($this->event);
        self::assertNull($tiers[0]->limit);
        self::assertSame(2, $tiers[1]->limit);
        self::assertSame(2, $this->pool()->limit());
        $document = (new PriceLadderComponent())->settingsToArray($this->event);
        self::assertSame(
            ['key' => 'tamogatott', 'label' => 'Támogatott ár', 'amount' => '20000', 'limit' => 2],
            $document['tiers'][1]
        );
        self::assertArrayNotHasKey('limit', $document['tiers'][0]);
    }

    public function testANegativeLimitIsRefusedAndZeroIsAllowed(): void
    {
        try {
            $this->ladder->propose($this->event, self::tiers(-1));
            self::fail('A limit of -1 was accepted.');
        } catch (InvalidInput) {
            self::assertSame([], $this->ladder->tiers($this->event));
        }

        $this->applyLadder(self::tiers(0));
        self::assertSame(0, $this->ladder->tiers($this->event)[1]->limit);
        self::assertSame(0, $this->pool()->limit());
        self::assertSame(0, $this->seats->left($this->pool()));
        self::assertTrue($this->seats->wouldOverfill($this->pool(), new RegistrationKey($this->event, '00A0')));
        $document = (new PriceLadderComponent())->settingsToArray($this->event);
        self::assertSame(0, $document['tiers'][1]['limit']);
    }

    public function testTheFormVariableIsServedFromThePoolsOfEveryTier(): void
    {
        $this->applyLadder(self::tiers(2));
        self::assertSame(
            ['seats_left.price_tier.alap' => null, 'seats_left.price_tier.tamogatott' => 2],
            $this->seatsLeft()
        );

        $this->holdSeat($this->choosing('00A0', 'tamogatott'));
        self::assertSame(1, $this->seatsLeft()['seats_left.price_tier.tamogatott']);

        $this->holdSeat($this->choosing('00A2', 'tamogatott'));
        $this->holdSeat($this->choosing('00A5', 'tamogatott'), over: true);
        self::assertSame(0, $this->seatsLeft()['seats_left.price_tier.tamogatott']);

        $this->applyLadder(self::tiers(null));
        self::assertSame(
            ['seats_left.price_tier.alap' => null, 'seats_left.price_tier.tamogatott' => null],
            $this->seatsLeft()
        );
    }

    public function testThePoolsAreNoneWhenTheEventHasNoLadder(): void
    {
        self::assertSame([], (new TierClaims())->pools($this->eventValue()));
    }

    public function testEveryChosenTierMakesAClaimLimitedOrNot(): void
    {
        $this->applyLadder(self::tiers(1));
        $claims = new TierClaims();
        $event = $this->eventValue();

        foreach (['tamogatott', 'alap'] as $chosen) {
            $made = $claims->claims($event, self::answers($chosen));
            self::assertCount(1, $made);
            self::assertSame('price_tier', $made[0]->fieldKey);
            self::assertSame('tier.' . $chosen, $made[0]->pool->key());
        }
        self::assertSame([], $claims->claims($event, self::answers('nincs_ilyen')));
        self::assertSame([], $claims->claims($event, new ValidatedAnswers([], [])));
    }

    /**
     * A tier with no limit holds its choosers' seat rows, so a limit added later counts them: nobody is
     * under-counted and the limit may be lower than the number already there (the count then shows over it).
     */
    public function testALimitAddedToATierItsChoosersAlreadyHoldCountsThemCorrectly(): void
    {
        $this->applyLadder(self::tiers(null));
        $anna = $this->choosing('00A0', 'tamogatott');
        $bela = $this->choosing('00A2', 'tamogatott');
        $this->holdSeat($anna);
        $this->holdSeat($bela);
        self::assertNull($this->seats->left($this->pool()));

        $this->applyLadder(self::tiers(3));
        self::assertSame(2, $this->seats->held($this->pool()));
        self::assertSame(1, $this->seats->left($this->pool()));

        $this->applyLadder(self::tiers(1));
        self::assertSame(2, $this->seats->held($this->pool()));
        self::assertSame(1, $this->seats->over($this->pool()));
        self::assertSame(0, $this->seats->left($this->pool()));
    }

    public function testATierAnOpenRegistrationChoseIsNotRemovedAndAnUnchosenOneIs(): void
    {
        $this->applyLadder(self::tiers(null));
        // Made while nobody had chosen the tier, applied after somebody has.
        $stale = new ProposedMoneySettings(components: [
            $this->ladder->propose($this->event, [self::tier('alap', 'Alap ár', '49500')]),
        ]);
        $anna = $this->choosing('00A0', 'tamogatott');

        // The preview refuses, and so does the apply, before anything is saved, and not by the re-price.
        try {
            $this->ladder->propose($this->event, [self::tier('alap', 'Alap ár', '49500')]);
            self::fail('The proposal went through.');
        } catch (RuleViolation) {
            self::assertCount(2, $this->ladder->tiers($this->event));
        }
        try {
            $this->settings->apply($this->event, $stale, new CliActor(null));
            self::fail('The apply went through.');
        } catch (RuleViolation) {
            self::assertCount(2, $this->ladder->tiers($this->event));
        }
        // Dropping every tier is the same removal.
        try {
            $this->ladder->propose($this->event, []);
            self::fail('The empty proposal went through.');
        } catch (RuleViolation) {
            self::assertCount(2, $this->ladder->tiers($this->event));
        }

        // A tier nobody chose goes; a registration that has left no longer holds its tier.
        $this->applyLadder([self::tier('tamogatott', 'Támogatott ár', '20000')]);
        self::assertCount(1, $this->ladder->tiers($this->event));
        $this->setStatus($anna, RegistrationStatus::Withdrawn);
        $this->applyLadder([self::tier('alap', 'Alap ár', '49500')]);
        self::assertSame(['alap'], array_map(static fn ($tier) => $tier->key, $this->ladder->tiers($this->event)));
    }

    public function testThePreviewRefusesWhenAnotherFieldHoldsPriceTier(): void
    {
        // A text field `price_tier` from the form: the declaration would refuse it, so the preview does.
        $this->declareParticipantField($this->event, 'price_tier');

        try {
            $this->ladder->propose($this->event, self::tiers(null));
            self::fail('The proposal went through.');
        } catch (RuleViolation) {
            self::assertSame([], $this->ladder->tiers($this->event));
        }
        // Nothing to declare for an event with no ladder, stored or proposed.
        self::assertSame([], $this->ladder->propose($this->event, [])->tiers);
    }

    /**
     * Stands in for the admission test of S23, which will go through the claim sources and the admission use
     * case: here the act is its seat step alone. [UNAPPROVED N3]
     */
    public function testAnAdmittedRegistrationWhoseTierIsFullKeepsItsTierAndTakesItsSeatOverTheLimit(): void
    {
        $this->applyLadder(self::tiers(1));
        $this->holdSeat($this->choosing('00A0', 'tamogatott'));
        $late = $this->choosing('00A2', 'tamogatott');

        // The admission: the pool first, then the registration, then the seat, over the limit as staff placing.
        $this->inRun(function (array $input, Context $context) use ($late): void {
            $this->seats->lock($this->pool(), $context);
            $this->lockRegistrations($context, $late);
            $this->seats->takeOver($this->pool(), $late, SeatAct::Admission, $context);
        });

        self::assertTrue($this->seats->holds($this->pool(), $late));
        self::assertSame(2, $this->seats->held($this->pool()));
        self::assertSame(1, $this->seats->over($this->pool()));
        self::assertSame(0, $this->seats->left($this->pool()));
        self::assertSame([self::line('20000')], $this->charges($late));
        self::assertSame([self::line('20000')], $this->charges(new RegistrationKey($this->event, '00A0')));
    }

    public function testAPriceChangeNeverMovesASeat(): void
    {
        $this->applyLadder(self::tiers(1));
        $anna = $this->choosing('00A0', 'tamogatott');
        $this->holdSeat($anna);

        $this->applyLadder([
            self::tier('alap', 'Alap ár', '49500'),
            self::tier('tamogatott', 'Támogatott ár', '25000') + ['limit' => 1],
        ]);

        self::assertTrue($this->seats->holds($this->pool(), $anna));
        // The re-price adds the difference as its own line; the seat stays where it was.
        self::assertSame(
            [self::line('20000'), 'price_ladder participant 5000 charge:price_ladder:participant:2'],
            $this->charges($anna)
        );
        self::assertSame(0, $this->seats->left($this->pool()));
    }

    public function testAHeldSeatIsReleasedByLeavingAndStaysFreeUntilAnActTakesIt(): void
    {
        $this->applyLadder(self::tiers(1));
        $anna = $this->choosing('00A0', 'tamogatott');
        $this->holdSeat($anna);
        $this->inRun(function (array $input, Context $context) use ($anna): void {
            $this->lockRegistrations($context, $anna);
            $this->seats->releaseAll($anna, $context);
        });

        self::assertSame(1, $this->seats->left($this->pool()));
        self::assertFalse($this->seats->holds($this->pool(), $anna));
        self::assertSame(1, $this->seatsLeft()['seats_left.price_tier.tamogatott']);
    }

    private function eventValue(): Event
    {
        return (new Events())->find($this->event) ?? throw new \LogicException();
    }

    /**
     * The form variable as the form host serves it: `seats_left.<field>.<option>` for each pool the claim source
     * names, the seats left (null: unlimited).
     *
     * @return array<string, int|null>
     */
    private function seatsLeft(): array
    {
        $left = [];
        foreach ((new TierClaims())->pools($this->eventValue()) as $entry) {
            $left['seats_left.' . $entry->fieldKey . '.' . $entry->optionValue] = $this->seats->left($entry->pool);
        }

        return $left;
    }

    private static function answers(string $tier): ValidatedAnswers
    {
        return new ValidatedAnswers(['price_tier' => $tier], ['price_tier' => $tier]);
    }

    private static function line(string $amount): string
    {
        return 'price_ladder participant ' . $amount . ' charge:price_ladder:participant:1';
    }
}
