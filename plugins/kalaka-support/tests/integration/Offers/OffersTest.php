<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Offers;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Support\Offers\SupportComponent;

/**
 * S30 on the topic-15 model: a selected offer is one participant-side `support:<key>` line, capped together at
 * the base price in the settings' order; an offer awaiting its answer, refused, or without its seat gives none;
 * the answer act approves or refuses through the one act and moves the seat; settings that would strip a seat are
 * refused.
 */
final class OffersTest extends OffersTestCase
{
    public function testASelectedOfferIsOneLineOfItsOwnAndAnUnselectedOneNone(): void
    {
        $report = $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000')]);
        self::assertSame([], $report->failed());

        $anna = $this->registerSelecting('00A0', 'diak');
        $bela = $this->register('00A2');
        $this->writeValue($bela, 'csalad', '0');

        self::assertSame(['support:diak participant -5000'], $this->lineSources($anna));
        self::assertSame('25000', $this->balance($anna));
        self::assertSame([], $this->lineSources($bela));
        self::assertSame('30000', $this->balance($bela));
    }

    public function testTheOffersAreCappedTogetherAtTheBasePriceInTheSettingsOrder(): void
    {
        $this->applyOffers([
            self::offer('egyik', '20000'),
            self::offer('masik', '15000'),
            self::offer('harmadik', '4000'),
        ]);

        $key = $this->registerSelecting('00A0', 'harmadik', 'masik', 'egyik');

        self::assertSame(
            ['support:egyik participant -20000', 'support:masik participant -10000'],
            $this->lineSources($key)
        );
        self::assertSame(
            ['support:egyik participant -20000', 'support:masik participant -10000'],
            $this->lineSources($this->registerSelecting('00A5', 'egyik', 'masik', 'harmadik'))
        );
        self::assertSame('0', $this->balance($key));
    }

    public function testAnOfferThatNeedsARequestGivesNoLineWhileItAwaitsAndNoPaymentIsAsked(): void
    {
        $this->applyOffers([self::offer('egyhaz', '10000', request: true)]);

        $key = $this->registerSelecting('00A0', 'egyhaz');

        self::assertTrue($this->awaits($key));
        self::assertSame([], $this->lineSources($key));
        self::assertSame('30000', $this->balance($key));
    }

    public function testApprovingGivesTheLineAndRefusingTakesItAwayAgainInTheSamePass(): void
    {
        $this->applyOffers([self::offer('egyhaz', '10000', request: true)]);
        $key = $this->registerSelecting('00A0', 'egyhaz');

        $result = $this->answer($key, 'egyhaz', 'yes');
        self::assertTrue($result['changed']);
        self::assertFalse($this->awaits($key));
        self::assertSame(['support:egyhaz participant -10000'], $this->lineSources($key));
        self::assertSame('20000', $this->balance($key));

        $this->answer($key, 'egyhaz', 'no');
        self::assertSame('30000', $this->balance($key));
        self::assertSame([], $this->lineSources($key));
        self::assertSame('refused', (new Registrations())->values($key)['egyhaz_answer']);
    }

    public function testALimitedOfferAppliesOnlyWhileTheRegistrationHoldsItsSeat(): void
    {
        $this->applyOffers([self::offer('keret', '8000', limit: 2)]);
        $withSeat = $this->registerSelecting('00A0', 'keret');
        $this->holdSeat($withSeat, 'keret');
        $this->writeValue($withSeat, 'keret', '1');
        $without = $this->registerSelecting('00A2', 'keret');

        self::assertSame('22000', $this->balance($withSeat));
        self::assertSame('30000', $this->balance($without));
    }

    public function testASelectionWithoutASeatInALimitedOfferThatNeedsARequestIsNoRequestAndOwesThePrice(): void
    {
        $this->applyOffers([self::offer('keret', '8000', request: true, limit: 1)]);
        $key = $this->registerSelecting('00A0', 'keret');

        self::assertFalse($this->awaits($key));
        self::assertSame('30000', $this->balance($key));
    }

    public function testAnswerActMovesTheSeatARefusalFreesItAndANewYesTakesOneEvenOverTheLimit(): void
    {
        $this->applyOffers([self::offer('keret', '8000', request: true, limit: 1)]);
        $anna = $this->register('00A0');
        $this->holdSeat($anna, 'keret');
        $this->writeValue($anna, 'keret', '1');
        self::assertTrue($this->awaits($anna));
        self::assertTrue($this->holds($anna, 'keret'));

        $this->answer($anna, 'keret', 'yes');
        self::assertSame('22000', $this->balance($anna));
        self::assertTrue($this->holds($anna, 'keret'));

        $this->answer($anna, 'keret', 'no');
        self::assertFalse($this->holds($anna, 'keret'));
        self::assertSame('30000', $this->balance($anna));
        // The refused request is still there to answer again.
        self::assertFalse($this->awaits($anna));

        $bela = $this->register('00A2');
        $this->holdSeat($bela, 'keret');
        $this->writeValue($bela, 'keret', '1');

        $again = $this->answer($anna, 'keret', 'yes');
        self::assertTrue($again['over_limit']);
        self::assertTrue($this->holds($anna, 'keret'));
        self::assertSame('22000', $this->balance($anna));
    }

    public function testALeavingRegistrationReleasesItsOfferSeat(): void
    {
        $this->applyOffers([self::offer('keret', '8000', limit: 1)]);
        $key = $this->register('00A0');
        $this->holdSeat($key, 'keret');
        self::assertTrue($this->holds($key, 'keret'));

        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->lockRegistrations($context, $key);
            $this->seats->releaseAll($key, $context);
        });

        self::assertFalse($this->holds($key, 'keret'));
        self::assertSame(['seats_left.keret.1' => 1], $this->offers->seatsLeft($this->event));
    }

    public function testTheSeatsLeftOfALimitedOfferAreTheFormVariable(): void
    {
        $this->applyOffers([self::offer('keret', '8000', limit: 2), self::offer('szabad', '1000')]);
        self::assertSame(['seats_left.keret.1' => 2], $this->offers->seatsLeft($this->event));

        $this->holdSeat($this->register('00A0'), 'keret');

        self::assertSame(['seats_left.keret.1' => 1], $this->offers->seatsLeft($this->event));
    }

    public function testASelectedOfferCannotBeRemovedNorGivenALimitButAnUnselectedOneCan(): void
    {
        $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000')]);
        $this->registerSelecting('00A0', 'diak');

        foreach (
            [
                [self::offer('csalad', '3000')],
                [self::offer('diak', '5000', limit: 3), self::offer('csalad', '3000')],
            ] as $offers
        ) {
            try {
                $this->applyOffers($offers);
                self::fail('The save went through.');
            } catch (RuleViolation) {
                self::assertCount(2, $this->offers->offers($this->event));
            }
        }

        $report = $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000', limit: 4)]);
        self::assertSame([], $report->failed());
        self::assertSame(
            [null, 4],
            array_map(static fn ($offer): ?int => $offer->limit, $this->offers->offers($this->event))
        );
    }

    public function testTheSaveDeclaresTheOffersConsentFieldAndItsAnswerFieldByKey(): void
    {
        $this->applyOffers([self::offer('egyhaz', '10000', request: true), self::offer('diak', '5000')]);

        $fields = [];
        foreach ((new ParticipantFields())->of($this->event) as $field) {
            $fields[$field->key] = $field;
        }
        self::assertSame('extension', $fields['diak']->source);
        self::assertSame('extension', $fields['egyhaz_answer']->source);
        self::assertSame('kalaka/consent-field', $fields['egyhaz']->type);
        self::assertTrue($fields['egyhaz']->formWritable);
        self::assertSame('Teszt támogatás egyhaz', $fields['egyhaz']->title);
        self::assertSame('kalaka/choice-field', $fields['egyhaz_answer']->type);
        self::assertFalse($fields['egyhaz_answer']->formWritable);
        self::assertSame(
            ['approved', 'refused'],
            array_map(static fn (array $option): string => $option['value'], $fields['egyhaz_answer']->options)
        );
        self::assertArrayNotHasKey('diak_answer', $fields);
    }

    public function testTheSaveRefusesBadOffers(): void
    {
        $bad = [
            'a duplicate key' => [self::offer('diak', '5000'), self::offer('diak', '1000')],
            'a key over forty characters' => [self::offer(str_repeat('a', 41), '5000')],
            'a malformed key' => [self::offer('Diak', '5000')],
            'an unknown node' => [self::offer('diak', '5000', node: 'NINCS')],
            'a zero amount' => [self::offer('diak', '0')],
            'a limit of zero' => [self::offer('diak', '5000', limit: 0)],
            'no label' => [['label' => ''] + self::offer('diak', '5000')],
            'an unknown property' => [self::offer('diak', '5000') + ['stackable' => true]],
            'more than fifty' => array_map(
                static fn (int $n): array => self::offer('o' . $n, '100'),
                range(1, 51)
            ),
        ];
        foreach ($bad as $what => $offers) {
            try {
                $this->applyOffers($offers);
                self::fail('Accepted: ' . $what);
            } catch (InvalidInput) {
                self::assertSame([], $this->offers->offers($this->event), $what);
            }
        }
        // A key of exactly forty characters is the longest.
        self::assertSame([], $this->applyOffers([self::offer(str_repeat('a', 40), '5000')])->failed());
    }

    public function testTheSettingsDocumentRoundTrips(): void
    {
        $offers = [self::offer('egyhaz', '10000', request: true, limit: 5), self::offer('diak', '5000.5')];
        $this->applyOffers($offers);

        $component = new SupportComponent();
        $shown = $component->settingsToArray($this->event);
        self::assertSame(['offers'], array_keys($shown));
        self::assertSame(
            [['egyhaz', 'TESZT-1', '10000', true, 5], ['diak', 'TESZT-1', '5000.5', false, null]],
            array_map(
                static fn (array $offer): array => [
                    $offer['key'], $offer['node'], $offer['amount'], $offer['request'], $offer['limit'],
                ],
                $shown['offers']
            )
        );
        $component->settingsFromArray($this->event, $shown);
        $this->expectException(InvalidInput::class);
        $component->settingsFromArray($this->event, ['offers' => [], 'x' => 1]);
    }

    public function testAWaitlistedRegistrationOfALimitedOfferGetsNoLineNorRequest(): void
    {
        $this->applyOffers([self::offer('keret', '8000', request: true, limit: 1)]);
        $key = $this->register('00A0', RegistrationStatus::Waitlisted);
        $this->writeValue($key, 'keret', '1');

        self::assertFalse($this->awaits($key));
        self::assertSame([], $this->lineSources($key));
    }

    /** @return list<string> the net of each support item's charge lines as "source payer amount", nets of zero left out */
    private function lineSources(RegistrationKey $key): array
    {
        $net = [];
        foreach ($this->charges($key) as $line) {
            [$source, $payer, $amount] = explode(' ', $line);
            if (str_starts_with($source, 'support:')) {
                $net[$source . ' ' . $payer] = ($net[$source . ' ' . $payer] ?? 0) + (int) $amount;
            }
        }
        $lines = [];
        foreach ($net as $item => $amount) {
            if ($amount !== 0) {
                $lines[] = $item . ' ' . $amount;
            }
        }
        sort($lines);

        return $lines;
    }
}
