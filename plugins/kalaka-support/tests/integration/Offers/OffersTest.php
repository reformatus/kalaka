<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Offers;

use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\MoneyRead;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\ItemChosen;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Pricing\Cli\SettingsDocument;
use Kalaka\Support\Offers\OfferClaims;
use Kalaka\Support\Offers\OfferRequests;
use Kalaka\Support\Offers\OfferSeatPool;
use Kalaka\Support\Offers\OffersInput;
use Kalaka\Support\Offers\SetSupportOffers;
use Kalaka\Support\Offers\SupportComponent;
use Kalaka\Support\Module;

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

    public function testARefusedOfferClaimsNoSeatWhenTheStoredValuesAreAskedAgain(): void
    {
        $this->applyOffers([self::offer('egyhaz', '10000', request: true, limit: 1)]);
        $key = $this->registerSelecting('00A0', 'egyhaz');
        $this->holdSeat($key, 'egyhaz');
        $event = (new Events())->find($key->eventId) ?? throw new \LogicException();
        $claims = static fn (array $values): array => (new OfferClaims())->claims(
            $event,
            new ValidatedAnswers($values, $values)
        );

        // Entitled while it awaits its answer or is approved.
        self::assertCount(1, $claims((new Registrations())->values($key)));
        $this->answer($key, 'egyhaz', 'yes');
        self::assertCount(1, $claims((new Registrations())->values($key)));

        // Refused: the seat went back, and a restore that asks the sources again claims nothing.
        $this->answer($key, 'egyhaz', 'no');
        $values = (new Registrations())->values($key);
        self::assertSame('refused', $values['egyhaz_answer']);
        self::assertSame([], $claims($values));
        self::assertFalse($this->holds($key, 'egyhaz'));
        self::assertSame(1, $this->seats->left(new OfferSeatPool($key->eventId, 'egyhaz')));
    }

    public function testAnswerYesConfirmsTheRegistrationInTheSamePass(): void
    {
        $this->applyOffers([self::offer('egyhaz', '10000', request: true)]);
        $key = $this->registerSelecting('00A0', 'egyhaz');
        $this->payIn($key, '20000');

        // While the offer awaits its answer the full price stands, so 20 000 of 30 000 is not enough.
        self::assertNotSame('confirmed', $this->confirmationState($key));

        $this->answer($key, 'egyhaz', 'yes');

        self::assertSame('confirmed', $this->confirmationState($key));
        self::assertSame('0', $this->balance($key));
    }

    public function testTheSupportComponentIsPartOfOneApplyThroughTheSettingsDocument(): void
    {
        $document = new SettingsDocument();
        $proposed = $document->proposed($this->event, [
            'fixed_price' => '25000',
            'components' => ['support' => ['offers' => [self::offer('diak', '5000')]]],
        ]);
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));
        self::assertSame([], $report->failed());

        $shown = $document->show($this->event);
        self::assertSame('25000', $shown['fixed_price']);
        self::assertSame('diak', $shown['components']['support']['offers'][0]['key']);
        $key = $this->registerSelecting('00A0', 'diak');
        self::assertSame('20000', $this->balance($key));
    }

    public function testTheModuleContributesItsComponentSourceAndClaims(): void
    {
        $contributions = Contributions::current();

        self::assertContains(SupportComponent::ID, array_map(
            static fn ($component): string => $component->id(),
            $contributions->feeComponents()
        ));
        self::assertNotSame([], array_filter(
            $contributions->requestSources(),
            static fn ($source): bool => $source instanceof OfferRequests
        ));
        self::assertNotSame([], array_filter(
            $contributions->seatClaimSources(),
            static fn ($source): bool => $source instanceof OfferClaims
        ));
    }

    public function testAKeyAnotherOfferHoldsIsRefusedWhileTheOffersAreProposed(): void
    {
        $bad = [
            'an offer beside the answer field of another' => [
                self::offer('egyhaz', '1000', request: true),
                self::offer('egyhaz_answer', '2000'),
            ],
            'the answer field first' => [
                self::offer('egyhaz_answer', '2000'),
                self::offer('egyhaz', '1000', request: true),
            ],
        ];
        foreach ($bad as $what => $offers) {
            try {
                $this->offers->propose($this->event, $offers);
                self::fail('Accepted: ' . $what);
            } catch (InvalidInput) {
                self::assertSame([], $this->offers->offers($this->event), $what);
            }
        }
    }

    public function testAKeyAFieldOfAnotherTypeOrDeclarerHoldsIsRefusedByTheOneDeclarableCheck(): void
    {
        $this->declareParticipantField($this->event, 'gyulekezet');
        $this->declareParticipantField($this->event, 'tamogatas_answer');
        $this->declareParticipantField($this->event, 'ar', [['value' => 'a', 'label' => 'A']]);
        $this->extensionField('masike', 'kalaka/consent-field', 'aid');
        $this->extensionField('masike2_answer', 'kalaka/choice-field', 'aid');
        $bad = [
            'a key a form field of another type holds' => [self::offer('gyulekezet', '1000')],
            'an answer key a form field of another type holds' => [self::offer('tamogatas', '1000', request: true)],
            'a choice field under the consent key' => [self::offer('ar', '1000')],
            'a key another declarer holds' => [self::offer('masike', '1000')],
            'an answer key another declarer holds' => [self::offer('masike2', '1000', request: true)],
        ];
        foreach ($bad as $what => $offers) {
            try {
                $this->offers->propose($this->event, $offers);
                self::fail('Accepted: ' . $what);
            } catch (RuleViolation) {
                self::assertSame([], $this->offers->offers($this->event), $what);
            }
        }
    }

    public function testAConsentFieldAFormCreatedUnderTheOffersKeyIsAdoptedAndTheOffersOwnFieldsAreMoneyRelevant(): void
    {
        $this->extensionField('egyhaz', 'kalaka/consent-field', null, 'form');

        $this->applyOffers([self::offer('egyhaz', '1000', request: true)]);

        $fields = [];
        foreach ((new ParticipantFields())->of($this->event) as $field) {
            $fields[$field->key] = $field;
        }
        self::assertSame('extension', $fields['egyhaz']->source);
        self::assertSame('support', $fields['egyhaz']->declaredBy);
        self::assertTrue($fields['egyhaz']->moneyRelevant);
        self::assertSame('support', $fields['egyhaz_answer']->declaredBy);
        self::assertSame((new Module())->id(), SetSupportOffers::DECLARER);
        self::assertTrue($fields['egyhaz_answer']->moneyRelevant);
    }

    /** A field of the event under the source, declared by the module the slug names (none for a form's). */
    private function extensionField(string $key, string $type, ?string $declarer, string $source = 'extension'): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => $key,
            'version' => 1,
            'type' => $type,
            'title' => $key,
            'source' => $source,
            'declared_by' => $declarer,
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 0,
            'form_writable' => 0,
        ]);
    }

    public function testMoreThanTheMostOffersAreRefusedAtTheProposalAsAtTheSave(): void
    {
        $offers = [];
        for ($i = 0; $i <= OffersInput::MAX_OFFERS; $i++) {
            $offers[] = self::offer('o' . $i, '1000');
        }

        try {
            $this->offers->propose($this->event, $offers);
            self::fail('Accepted.');
        } catch (InvalidInput) {
            self::assertSame([], $this->offers->offers($this->event));
        }
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

        // The panel reads no request; the rule (and so awaits()) does not test seats: a submit always holds one.
        self::assertSame([], (new Requests())->states($key));
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

    public function testAnUnlimitedOfferHasAPoolForTheAnswerActSoARefusalFreesItsSeatAndAYesTakesOne(): void
    {
        $this->applyOffers([self::offer('szabad', '1000', request: true)]);
        $anna = $this->register('00A0');
        $this->holdSeat($anna, 'szabad');
        $this->writeValue($anna, 'szabad', '1');
        $request = new Request(OfferRequests::PREFIX . 'szabad', RequestKind::Offer, 'Szabad');

        $pool = (new OfferRequests())->seatPool($anna, $request);
        self::assertInstanceOf(OfferSeatPool::class, $pool);
        self::assertNull($pool->limit());
        $unknown = new Request(OfferRequests::PREFIX . 'nincs', RequestKind::Offer, 'Nincs');
        self::assertNull((new OfferRequests())->seatPool($anna, $unknown));

        $this->answer($anna, 'szabad', 'no');
        self::assertFalse($this->holds($anna, 'szabad'));

        $again = $this->answer($anna, 'szabad', 'yes');
        self::assertFalse($again['over_limit']);
        self::assertTrue($this->holds($anna, 'szabad'));
    }

    public function testReleaseAllFreesAnOfferSeat(): void
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
        self::assertSame(1, $this->seats->left(new OfferSeatPool($this->event, 'keret')));
    }

    public function testThePoolsOfTheOffersAreTheFormVariableOfEveryOfferLimitedOrNot(): void
    {
        $this->applyOffers([self::offer('keret', '8000', limit: 2), self::offer('szabad', '1000')]);
        $event = (new Events())->find($this->event) ?? throw new \LogicException();

        $entries = (new OfferClaims())->pools($event);

        self::assertSame(['keret', 'szabad'], array_map(static fn ($entry): string => $entry->fieldKey, $entries));
        self::assertSame(['1', '1'], array_map(static fn ($entry): string => $entry->optionValue, $entries));
        self::assertSame(
            ['offer.keret', 'offer.szabad'],
            array_map(static fn ($entry): string => $entry->pool->key(), $entries)
        );
        self::assertSame(2, $this->seats->left($entries[0]->pool));
        self::assertNull($this->seats->left($entries[1]->pool));

        $this->holdSeat($this->register('00A0'), 'keret');

        self::assertSame(1, $this->seats->left($entries[0]->pool));
    }

    public function testASelectedOfferCannotBeRemovedButAnUnselectedOneCanAndALimitMayBeAddedAnyTime(): void
    {
        $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000')]);
        $this->registerSelecting('00A0', 'diak');

        self::assertTrue((new Registrations())->openHolds($this->event, 'diak', '1'));
        try {
            $this->applyOffers([self::offer('csalad', '3000')]);
            self::fail('The save went through.');
        } catch (RuleViolation $refused) {
            self::assertInstanceOf(ItemChosen::class, $refused);
            self::assertSame('kalaka_item_chosen', $refused->errorCode());
            self::assertCount(2, $this->offers->offers($this->event));
        }

        // A limit may be added at any time: every chooser holds a seat row.
        $this->applyOffers([self::offer('diak', '5000', limit: 3), self::offer('csalad', '3000')]);
        self::assertSame(3, $this->offers->offers($this->event)[0]->limit);
        $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000')]);

        $report = $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000', limit: 4)]);
        self::assertSame([], $report->failed());
        self::assertSame(
            [null, 4],
            array_map(static fn ($offer): ?int => $offer->limit, $this->offers->offers($this->event))
        );
    }

    public function testTheRefusalOfASelectedOfferIsInThePreviewAndAnApplyThatIsStaleSavesNothing(): void
    {
        $this->applyOffers([self::offer('diak', '5000'), self::offer('csalad', '3000')]);
        $stale = $this->offers->propose($this->event, [self::offer('csalad', '3000')]);
        $key = $this->registerSelecting('00A0', 'diak');

        // The preview's proposal already refuses.
        try {
            $this->offers->propose($this->event, [self::offer('csalad', '3000')]);
            self::fail('The proposal went through.');
        } catch (RuleViolation) {
            self::assertCount(2, $this->offers->offers($this->event));
        }

        // A proposal made before the selection is refused by the apply before the fixed price is saved.
        try {
            $this->settings->apply(
                $this->event,
                new ProposedMoneySettings($this->huf('40000'), [$stale]),
                new CliActor(null)
            );
            self::fail('The apply went through.');
        } catch (RuleViolation) {
            $price = $this->settings->fixedPrice($this->event);
            self::assertTrue($price !== null && $price->isEqualTo($this->huf('30000')));
            self::assertCount(2, $this->offers->offers($this->event));
            self::assertSame('25000', $this->balance($key));
        }
    }

    public function testFlippingTheRequestOfASelectedOfferIsRefusedLikeRemovingItAndAnUnselectedOneCanFlip(): void
    {
        $this->applyOffers([
            self::offer('egyhaz', '10000', request: true),
            self::offer('diak', '5000'),
            self::offer('szabad', '1000'),
        ]);
        $anna = $this->registerSelecting('00A0', 'egyhaz', 'diak');
        $this->answer($anna, 'egyhaz', 'no');
        self::assertSame('25000', $this->balance($anna));

        // true -> false would turn Anna's refusal into support; false -> true would take a line from her.
        foreach (
            [
                [self::offer('egyhaz', '10000'), self::offer('diak', '5000'), self::offer('szabad', '1000')],
                [
                    self::offer('egyhaz', '10000', request: true),
                    self::offer('diak', '5000', request: true),
                    self::offer('szabad', '1000'),
                ],
            ] as $offers
        ) {
            try {
                $this->applyOffers($offers);
                self::fail('The save went through.');
            } catch (RuleViolation) {
                self::assertSame('25000', $this->balance($anna));
            }
        }

        $report = $this->applyOffers([
            self::offer('egyhaz', '10000', request: true),
            self::offer('diak', '5000'),
            self::offer('szabad', '1000', request: true),
        ]);
        self::assertSame([], $report->failed());
    }

    public function testASupportLineIsNamedByItsOffersOwnLabelOnTheParticipantAndTheAdminSide(): void
    {
        $this->applyOffers([self::offer('diak', '5000')]);
        $key = $this->registerSelecting('00A0', 'diak');
        $label = 'Teszt támogatás diak';

        $labels = array_map(
            static fn ($row): string => $row->label,
            (new MoneyRead())->of($key, Currency::HUF)->owed
        );
        self::assertContains($label, $labels);
        self::assertNotContains('Díj', $labels);

        $read = (new RegistrationDetail())->of(new SystemActor(), $this->event, $key->code);
        $support = array_values(array_filter(
            $read['money']['lines'],
            static fn (array $line): bool => $line['kind_label'] === $label
        ));
        self::assertCount(1, $support);
        self::assertSame('component', $support[0]['type']);
        self::assertSame('-5000', rtrim(rtrim($support[0]['amount'], '0'), '.'));
    }

    public function testTheAnswerFieldTitleDropsATrailingColonOrSpaceOfTheLabel(): void
    {
        $this->applyOffers([['label' => 'Kérsz támogatást: '] + self::offer('egyhaz', '10000', request: true)]);

        foreach ((new ParticipantFields())->of($this->event) as $field) {
            if ($field->key === 'egyhaz_answer') {
                self::assertSame('Kérsz támogatást: válasz', $field->title);

                return;
            }
        }
        self::fail('The answer field is not declared.');
    }

    public function testAnOfferThatNeedsNoRequestMayHoldAKeyThatLooksLikeAnAnswerKey(): void
    {
        $this->declareParticipantField($this->event, 'diak_answer');

        $report = $this->applyOffers([self::offer('diak', '5000')]);

        self::assertSame([], $report->failed());
    }

    public function testSupportIsComposedBeforeAShareOfTheBasePriceAndNothingGoesBelowZero(): void
    {
        $this->useComponents($this->shareStandIn('20000'));
        $this->applyOffers([self::offer('nagy', '25000')]);
        $anna = $this->registerSelecting('00A0', 'nagy');
        $bela = $this->register('00A2');

        // The base after support is 5 000: the share finds that much left, never more.
        self::assertSame('0', $this->balance($anna));
        self::assertSame(['support:nagy participant -25000'], $this->lineSources($anna));
        // Without support the share takes 20 000 of 30 000.
        self::assertSame('10000', $this->balance($bela));
    }

    /** A stand-in for the share (S29, unbuilt): `min(share, base after support)` off the participant's fee. */
    private function shareStandIn(string $amount): FeeComponent
    {
        $share = $this->huf($amount);

        return new class ($share) implements FeeComponent {
            public function __construct(private readonly Money $share)
            {
            }

            public function id(): string
            {
                return 'share_standin';
            }

            public function stage(): FeeStage
            {
                return FeeStage::Share;
            }

            public function targets(FeeComponentInput $input): FeeTargets
            {
                $left = $input->before->total(Payer::PARTICIPANT);
                $taken = $this->share->isGreaterThan($left) ? $left : $this->share;

                return $taken->isPositive()
                    ? FeeTargets::ofItems(['share' => [Payer::PARTICIPANT => $taken->negated()]])
                    : FeeTargets::none();
            }
        };
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
            'a negative limit' => [self::offer('diak', '5000', limit: -1)],
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

        // The panel reads no request; the rule (and so awaits()) does not test seats, a waitlisted one awaits nothing.
        self::assertSame([], (new Requests())->states($key));
        self::assertSame([], $this->lineSources($key));
    }

    public function testALimitOfZeroIsAFullOfferAndStillAccepted(): void
    {
        $report = $this->applyOffers([self::offer('keret', '8000', limit: 0)]);

        self::assertSame([], $report->failed());
        self::assertSame(0, $this->offers->offers($this->event)[0]->limit);
        self::assertSame(0, $this->seats->left(new OfferSeatPool($this->event, 'keret')));
    }

    public function testAnUnlimitedOfferClaimsAndHoldsASeatRowToo(): void
    {
        $this->applyOffers([self::offer('szabad', '1000')]);
        $event = (new Events())->find($this->event) ?? throw new \LogicException();

        $claims = (new OfferClaims())->claims($event, new ValidatedAnswers(['szabad' => '1'], ['szabad' => '1']));
        $none = (new OfferClaims())->claims($event, new ValidatedAnswers(['szabad' => '0'], ['szabad' => '0']));

        self::assertCount(1, $claims);
        self::assertSame('szabad', $claims[0]->fieldKey);
        self::assertSame([], $none);
        $key = $this->register('00A0');
        $this->holdSeat($key, 'szabad');
        self::assertTrue($this->holds($key, 'szabad'));
    }

    public function testTheRequestSourceReadsAnsweredFromItsOwnAnswerField(): void
    {
        $this->applyOffers([self::offer('egyhaz', '1000', request: true)]);
        $source = new OfferRequests();
        $key = new RegistrationKey($this->event, '00A0');
        $request = $source->requestsOf($key, ['egyhaz' => '1'])[0];

        self::assertSame(['egyhaz'], $request->askFields);
        self::assertSame('egyhaz_answer', $request->answerField);
        self::assertFalse($source->isAnswered($request, ['egyhaz' => '1']));
        self::assertFalse($source->isAnswered($request, ['egyhaz' => '1', 'egyhaz_answer' => '']));
        self::assertTrue($source->isAnswered($request, ['egyhaz' => '1', 'egyhaz_answer' => 'approved']));
        self::assertTrue($source->isAnswered($request, ['egyhaz' => '1', 'egyhaz_answer' => 'refused']));
    }

    public function testTheAnswerIsWrittenUnderTheOffersDeclarerAndAnotherDeclarerIsRefused(): void
    {
        $this->applyOffers([self::offer('egyhaz', '1000', request: true)]);
        $key = $this->registerSelecting('00A0', 'egyhaz');

        $this->answer($key, 'egyhaz', 'yes');
        self::assertSame('approved', (new Registrations())->values($key)['egyhaz_answer'] ?? null);

        try {
            $this->inRun(function (array $input, Context $context) use ($key): void {
                $this->lockRegistrations($context, $key);
                (new Registrations())->writeValue($key, 'egyhaz_answer', 'refused', $context, 'aid');
            });
            self::fail('Another declarer wrote the field.');
        } catch (\Kalaka\Foundation\Api\Error\SystemFailure) {
            self::assertSame('approved', (new Registrations())->values($key)['egyhaz_answer'] ?? null);
        }
    }

    /** @return list<string> the codes of the event's registrations core's compiled condition says await an answer */
    private function awaitingBySql(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kalaka_field_values';
        [$condition, $args] = (new Requests([new OfferRequests()]))->awaitingCondition($this->event, $table);
        $sql = 'SELECT r.code FROM %i r WHERE r.event_id = %d AND (' . $condition . ') ORDER BY r.code';
        $codes = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test inspection; the text is the table placeholders and the condition's own placeholders
            $wpdb->prepare($sql, $wpdb->prefix . 'kalaka_registrations', $this->event, ...$args)
        );

        return array_map('strval', $codes);
    }

    public function testTheAwaitingRuleMeansWhatTheRequestSourceMeans(): void
    {
        $this->applyOffers([
            self::offer('egyhaz', '1000', request: true),
            self::offer('masik', '700', request: true),
            self::offer('nemkell', '500'),
            self::offer('korlatos', '200', request: true, limit: 2),
        ]);
        $awaiting = [
            $this->registerSelecting('00A0', 'egyhaz'),
            $this->registerSelecting('00A2', 'egyhaz'),
            $this->registerSelecting('00A5', 'egyhaz', 'masik'),
            $this->registerSelecting('00B0', 'korlatos'),
        ];
        $this->holdSeat($awaiting[3], 'korlatos');
        $this->writeValue($awaiting[1], 'egyhaz_answer', '');
        $this->writeValue($awaiting[2], 'masik_answer', 'approved');
        $this->writeValue($this->registerSelecting('00B3', 'egyhaz'), 'egyhaz_answer', 'approved');
        $this->writeValue($this->registerSelecting('00B6', 'egyhaz'), 'egyhaz_answer', 'refused');
        $this->registerSelecting('00B9', 'nemkell');
        $this->register('00E2');
        $waitlisted = $this->register('00E5', RegistrationStatus::Waitlisted);
        $this->writeValue($waitlisted, 'egyhaz', '1');
        $withdrawn = $this->register('00E8', RegistrationStatus::Withdrawn);
        $this->writeValue($withdrawn, 'egyhaz', '1');

        $bySql = $this->awaitingBySql();

        self::assertSame(['00A0', '00A2', '00A5', '00B0'], $bySql);
        $requests = new Requests([new OfferRequests()]);
        $byStates = [];
        $byRule = [];
        $rule = (new OfferRequests())->awaitingRule($this->event) ?? throw new \LogicException();
        foreach (['00A0', '00B3', '00B6', '00B9', '00A2', '00A5', '00E2', '00E5', '00E8', '00B0'] as $code) {
            $key = new RegistrationKey($this->event, $code);
            $status = (new Registrations())->find($key)->status ?? throw new \LogicException();
            if ($status === RegistrationStatus::Registered && $requests->awaits($key, $status)) {
                $byStates[] = $code;
            }
            if ($status === RegistrationStatus::Registered && $rule->matches((new Registrations())->values($key))) {
                $byRule[] = $code;
            }
        }
        sort($byStates);
        sort($byRule);
        self::assertSame($byStates, $bySql);
        self::assertSame($byRule, $bySql);
    }

    public function testNoOfferThatNeedsApprovalGivesNoRule(): void
    {
        $this->applyOffers([self::offer('nemkell', '500')]);

        self::assertNull((new OfferRequests())->awaitingRule($this->event));
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
