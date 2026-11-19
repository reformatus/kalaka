<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Evaluation\EvaluationFailureRepository;
use Kalaka\Ledger\Api\Payer;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\PriceLadder\PriceLadderComponent;
use Kalaka\PriceLadder\TierSelection;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeRefused;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Tests\Integration\Foundation\PortalProbe;
use Kalaka\Tests\Integration\Pricing\ClosureComponent;

/**
 * S25: the tier a registration chooses in `price_tier` or its answers reach, its deadline at `registered_at`, the
 * refusals when no chosen or available tier applies, and the ladder's apply.
 */
final class PriceLadderTest extends PriceLadderTestCase
{
    public function testWithoutAChosenTierEachRegistrationPaysTheFirstTierWhoseConditionHolds(): void
    {
        $this->applyLadder(self::ivkTiers());

        $expected = [
            '00A0' => ['nincs', 'alap', '49500'],
            '00A2' => ['nincs', 'emelt', '69500'],
            '00A5' => ['megosztott', null, '44500'],
            '00A8' => ['teljes', 'alap', '34500'],
            '01A1' => ['teljes', 'emelt', '49500'],
        ];
        foreach ($expected as $code => [$level, $price, $amount]) {
            $key = $this->registerAnswering($code, $level, $price);
            self::assertSame([self::line($amount)], $this->charges($key));
        }
    }

    public function testARegistrationWrittenWithItsAnswersInOneRunPaysItsTierInThatRun(): void
    {
        $this->applyLadder(self::ivkTiers());
        $key = new RegistrationKey($this->event, '00A0');

        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->insertRegistration($this->newRegistration($this->event, '00A0'), $context);
            $this->values->upsert(new FieldValue($key, 'tamogatas', 'megosztott'), $context);
        });

        self::assertSame([self::line('44500')], $this->charges($key));
    }

    public function testAChangedAnswerMovesTheRegistrationToAnotherTierAtItsNextEvaluation(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerAnswering('00A0', 'nincs', 'alap');

        $this->writeValue($anna, 'ar', 'emelt');

        self::assertSame([self::line('49500'), self::line('20000', 2)], $this->charges($anna));
        self::assertSame('69500', $this->balance($anna));
    }

    public function testWithALadderNoApplyingTierIsRefusedNeverAZeroBaseNorTheFixedPrice(): void
    {
        $this->applyLadder(self::ivkTiers());
        $this->setPrice('40000');

        try {
            $this->registerAnswering('00A0', null, null);
            self::fail('The admission went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::ADMIN_NONE_TEXT, $refused->publicMessage());
            self::assertSame('money/price_ladder', $refused->getPrevious()?->getMessage());
        }
        $bela = new RegistrationKey($this->event, '00A0');
        self::assertSame(RegistrationStatus::Waitlisted, $this->registrations->find($bela)?->status);
        self::assertSame([], $this->charges($bela));

        $anna = $this->registerAnswering('00A2', 'nincs', 'alap');
        self::assertSame([self::line('49500')], $this->charges($anna));
    }

    public function testWithoutALadderTheFixedPriceIsTheBase(): void
    {
        $this->setPrice('40000');
        $bela = $this->registerAnswering('00A0', null, null);

        self::assertSame(['fixed_price participant 40000 charge:fixed_price:participant:1'], $this->charges($bela));
    }

    public function testATierOwingNothingChargesNoFixedPriceEither(): void
    {
        $this->setPrice('40000');
        $this->applyLadder([self::tier('ingyenes', 'Ingyenes', '0')]);
        $bela = $this->registerAnswering('00A0', null, null);

        self::assertSame([], $this->charges($bela));
    }

    public function testNoTierCarriesAShareTheLadderChargesTheBasePriceOnly(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerAnswering('00A8', 'teljes', 'alap');

        self::assertSame([self::line('34500')], $this->charges($anna));
        self::assertSame(
            ['key', 'label', 'amount', 'condition', 'until'],
            array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionClass(Tier::class))->getProperties()
            )
        );
    }

    public function testAnEarlyTierCountsFromRegisteredAtNeverFromNow(): void
    {
        $this->applyLadder([
            self::tier('korai', 'Korai alap ár', '44000', ['tamogatas' => 'nincs'], '2026-12-15'),
            self::tier('alap', 'Alap ár', '49500', ['tamogatas' => 'nincs']),
        ]);
        // 23:30 on 15 December in Budapest is still the early tier's last day; 00:30 on the 16th is not.
        $early = $this->registerAnswering('00A0', 'nincs', null, '2026-12-15 22:30:00');
        $late = $this->registerAnswering('00A2', 'nincs', null, '2026-12-15 23:30:00');
        self::assertSame([self::line('44000')], $this->charges($early));
        self::assertSame([self::line('49500')], $this->charges($late));

        $this->now = '2027-01-10 08:00:00';
        $this->useComponents();
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($early));

        self::assertSame([self::line('44000')], $this->charges($early));
    }

    public function testWithoutAChosenTierAWaitlistAdmissionAfterTheDeadlinePaysTodaysFirstTier(): void
    {
        $this->applyLadder([
            self::tier('korai', 'Korai alap ár', '44000', [], '2026-12-15'),
            self::tier('alap', 'Alap ár', '49500'),
        ]);
        $csaba = $this->register('00A0', RegistrationStatus::Waitlisted, '2026-12-01 10:00:00');
        self::assertSame([], $this->charges($csaba));

        $this->setStatus($csaba, RegistrationStatus::Registered, '2026-12-20 10:00:00');

        self::assertSame([self::line('49500')], $this->charges($csaba));
    }

    public function testThePreviewShowsOldAndNewAmountsAndWritesNothingThenTheApplySavesAndConverges(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerAnswering('00A0', 'nincs', 'alap');
        $dora = $this->registerAnswering('00A2', 'teljes', 'alap');
        $lines = $this->lineCount();
        $raised = self::ivkTiers();
        $raised[0]['amount'] = '52000';
        $proposed = new ProposedMoneySettings(components: [$this->ladder->propose($this->event, $raised)]);

        $rows = $this->settings->preview($this->event, $proposed);

        self::assertSame(
            ['00A0' => true, '00A2' => false],
            array_combine(
                array_map(static fn (PreviewRow $row): string => $row->registration->code, $rows),
                array_map(static fn (PreviewRow $row): bool => $row->changes(), $rows)
            )
        );
        self::assertSame('49500.00000000', $rows[0]->before[Payer::PARTICIPANT]->amount());
        self::assertSame('52000.00000000', $rows[0]->after[Payer::PARTICIPANT]->amount());
        self::assertSame($lines, $this->lineCount());
        self::assertSame('49500.00000000', $this->ladder->tiers($this->event)[0]->amount->amount());

        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));

        self::assertCount(2, $report->done());
        self::assertSame('52000', $this->balance($anna));
        self::assertSame('34500', $this->balance($dora));
        self::assertSame('52000.00000000', $this->ladder->tiers($this->event)[0]->amount->amount());
        $lines = $this->lineCount();
        $this->settings->apply($this->event, $proposed, new CliActor(null));
        self::assertSame($lines, $this->lineCount());
    }

    public function testTheLadderIsSavedInOrderAndAnEmptyLadderRemovesIt(): void
    {
        $this->applyLadder(self::ivkTiers());
        $this->applyLadder([self::ivkTiers()[2], self::ivkTiers()[0]]);

        self::assertSame(
            ['gy_megosztott', 'alap'],
            array_map(static fn (Tier $tier): string => $tier->key, $this->ladder->tiers($this->event))
        );

        $this->applyLadder([]);
        self::assertSame([], $this->ladder->tiers($this->event));
    }

    public function testAConditionOnAnUndeclaredKeyOrATierKeyGivenTwiceIsRefusedBeforeAnythingIsSaved(): void
    {
        foreach (
            [
                [self::tier('alap', 'Alap ár', '49500', ['nincs_ilyen' => 'x'])],
                [self::tier('alap', 'Alap ár', '49500'), self::tier('alap', 'Másik', '1')],
                [self::tier('alap', 'Alap ár', '49500', [], '2026-02-30')],
            ] as $tiers
        ) {
            try {
                $this->ladder->propose($this->event, $tiers);
                self::fail('The ladder was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->ladder->tiers($this->event));
            }
        }
    }

    public function testTheLadderDeclaresPriceTierWithItsTiersAsOptionsAndRedeclaresItOnEverySave(): void
    {
        $this->applyLadder(self::ivkTiers());

        $field = $this->priceTier();
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source);
        self::assertTrue($field->formWritable);
        self::assertSame(PriceLadderComponent::FIELD_TITLE, $field->title);
        self::assertSame('kalaka/choice-field', $field->type);
        self::assertSame(
            ['alap', 'teljes', 'gy_megosztott', 'gy', 'gy_alap'],
            array_column($field->options, 'value')
        );
        self::assertSame('Gyülekezeti ár', $field->options[3]['label']);

        // A new tier adds an option; a removed tier's option is retired with its label kept.
        $this->applyLadder([self::ivkTiers()[0], self::tier('korai', 'Korai ár', '44000')]);
        self::assertSame(
            [
                'alap' => false,
                'teljes' => true,
                'gy_megosztott' => true,
                'gy' => true,
                'gy_alap' => true,
                'korai' => false,
            ],
            array_combine(
                array_column($this->priceTier()->options, 'value'),
                array_column($this->priceTier()->options, 'retired')
            )
        );
        self::assertSame('Gyülekezeti ár', $this->priceTier()->options[3]['label']);

        // An empty ladder retires every option and keeps the field.
        $this->applyLadder([]);
        self::assertSame([true], array_values(array_unique(array_column($this->priceTier()->options, 'retired'))));
    }

    public function testAnEmptyLadderOnAnEventThatNeverHadOneDeclaresNothing(): void
    {
        $this->applyLadder([]);

        self::assertNotContains(
            PriceLadderComponent::FIELD,
            array_map(
                static fn (ParticipantField $f): string => $f->key,
                (new ParticipantFields())->of($this->event, true)
            )
        );
    }

    public function testTheChosenTierAppliesWhenItIsAvailableEvenAfterAnEarlierAvailableOne(): void
    {
        $this->applyLadder(self::everyoneTiers());
        $key = $this->registerChoosing('00A0', 'gy');

        self::assertSame([self::line('34500')], $this->charges($key));
    }

    public function testAnAdmissionAfterTheChosenTiersDeadlineIsRefusedUntilStaffSetATierThatApplies(): void
    {
        $this->applyLadder([
            self::tier('korai', 'Korai ár', '44000', [], '2026-12-15'),
            self::tier('alap', 'Alap ár', '49500'),
        ]);
        $csaba = $this->register('00A0', RegistrationStatus::Waitlisted, '2026-12-01 10:00:00');
        $this->writeValue($csaba, PriceLadderComponent::FIELD, 'korai');

        try {
            $this->setStatus($csaba, RegistrationStatus::Registered, '2026-12-20 10:00:00');
            self::fail('The admission went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::ADMIN_CHOSEN_TEXT, $refused->publicMessage());
        }
        self::assertSame(RegistrationStatus::Waitlisted, $this->registrations->find($csaba)?->status);
        self::assertSame([], $this->charges($csaba));

        // No fallback: staff set a tier that applies, then the admission goes through.
        $this->writeValue($csaba, PriceLadderComponent::FIELD, 'alap');
        $this->setStatus($csaba, RegistrationStatus::Registered, '2026-12-20 10:00:00');

        self::assertSame([self::line('49500')], $this->charges($csaba));
    }

    public function testAnAnswerThatBreaksTheChosenTierIsRefusedForStaffAndForTheRegistrant(): void
    {
        $this->applyLadder([
            self::tier('alap', 'Alap ár', '49500'),
            self::tier('gy', 'Gyülekezeti ár', '34500', ['tamogatas' => 'teljes']),
        ]);
        $anna = $this->registerAnswering('00A0', 'teljes', null, chosen: 'gy');

        try {
            $this->writeValue($anna, 'tamogatas', 'nincs');
            self::fail('The staff write went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::ADMIN_CHOSEN_TEXT, $refused->publicMessage());
        }

        try {
            $this->runner->run(
                new PortalProbe([$anna], function (array $input, Context $context) use ($anna): void {
                    $this->lockRegistrations($context, $anna);
                    $stored = $this->values->findForUpdate(
                        ['eventId' => $anna->eventId, 'code' => $anna->code, 'fieldKey' => 'tamogatas'],
                        $context
                    );
                    self::assertNotNull($stored);
                    $stored->value = 'nincs';
                    $this->values->upsert($stored, $context);
                }),
                [],
                new SecretLinkActor($anna)
            );
            self::fail('The registrant\'s write went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::PORTAL_TEXT, $refused->publicMessage());
        }

        self::assertSame([self::line('34500')], $this->charges($anna));
        $level = $this->values->find(['eventId' => $this->event, 'code' => '00A0', 'fieldKey' => 'tamogatas']);
        self::assertSame('teljes', $level?->value);
    }

    public function testALadderChangeThatBreaksAChosenTierFlagsTheRegistrationUntilStaffFixIt(): void
    {
        $this->applyLadder(self::everyoneTiers());
        $anna = $this->registerChoosing('00A0', 'gy');

        $proposed = new ProposedMoneySettings(
            components: [$this->ladder->propose($this->event, [self::everyoneTiers()[0]])]
        );
        $report = $this->settings->apply($this->event, $proposed, new CliActor(null));

        self::assertCount(1, $report->failed());
        self::assertSame('money/price_ladder', (new EvaluationFailureRepository())->find($anna)?->rule);
        self::assertSame([self::line('34500')], $this->charges($anna));

        $this->writeValue($anna, PriceLadderComponent::FIELD, 'alap');

        self::assertNull((new EvaluationFailureRepository())->find($anna));
        self::assertSame([self::line('34500'), self::line('15000', 2)], $this->charges($anna));
    }

    public function testTheAppliedTierIsAComputedValueForTheLaterStages(): void
    {
        $seen = [];
        $this->useComponents(new ClosureComponent(
            'share_probe',
            FeeStage::Share,
            static function (FeeComponentInput $input) use (&$seen): FeeTargets {
                $applied = $input->facts->get(PriceLadderComponent::APPLIED);
                $seen[] = [$applied->data, $applied->text];

                return FeeTargets::none();
            }
        ));
        $this->applyLadder(self::everyoneTiers());

        $this->registerChoosing('00A0', 'gy');
        $this->registerAnswering('00A2', null, null);

        self::assertSame([[['gy'], 'Gyülekezeti ár'], [['alap'], 'Alap ár']], $seen);
    }

    /** Two tiers for everyone: without a choice the first applies. @return list<array<string, mixed>> */
    private static function everyoneTiers(): array
    {
        return [self::tier('alap', 'Alap ár', '49500'), self::tier('gy', 'Gyülekezeti ár', '34500')];
    }

    private function priceTier(): ParticipantField
    {
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            if ($field->key === PriceLadderComponent::FIELD) {
                return $field;
            }
        }
        self::fail('The ladder declared no price_tier.');
    }

    private function registerChoosing(string $code, string $tier): RegistrationKey
    {
        return $this->registerAnswering($code, null, null, chosen: $tier);
    }

    private function registerAnswering(
        string $code,
        ?string $level,
        ?string $price,
        string $registeredAt = '2026-11-30 08:00:00',
        ?string $chosen = null,
    ): RegistrationKey {
        $key = $this->register($code, RegistrationStatus::Waitlisted, $registeredAt);
        if ($chosen !== null) {
            $this->writeValue($key, PriceLadderComponent::FIELD, $chosen);
        }
        if ($level !== null) {
            $this->writeValue($key, 'tamogatas', $level);
        }
        if ($price !== null) {
            $this->writeValue($key, 'ar', $price);
        }
        $this->setStatus($key, RegistrationStatus::Registered, $registeredAt);

        return $key;
    }

    /** A charge line of the ladder's component, as `charges()` lists it. */
    private static function line(string $amount, int $n = 1): string
    {
        return 'price_ladder participant ' . $amount . ' charge:price_ladder:participant:' . $n;
    }
}
