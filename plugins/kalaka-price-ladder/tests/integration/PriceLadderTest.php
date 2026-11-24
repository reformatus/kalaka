<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Forms\Api\FieldKind;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
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
 * S25: the tier a registration chooses in `price_tier` (a tier has no condition and no deadline), the refusals
 * when the chosen tier is gone or there is no answer, a later admission and the ladder's apply.
 */
final class PriceLadderTest extends PriceLadderTestCase
{
    public function testEachRegistrationPaysTheTierItChose(): void
    {
        $this->applyLadder(self::ivkTiers());

        $expected = ['00A0' => ['alap', '49500'], '00A2' => ['teljes', '69500'], '00A5' => ['gy_megosztott', '44500'],
            '00A8' => ['gy', '34500'], '01A1' => ['gy_alap', '49500']];
        foreach ($expected as $code => [$tier, $amount]) {
            // The answers that once chose a tier by condition decide nothing now.
            $key = $this->registerAnswering($code, 'nincs', 'emelt', chosen: $tier);
            self::assertSame([self::line($amount)], $this->charges($key));
        }
    }

    public function testATierHasNoConditionAndNoDeadlineAndNoShare(): void
    {
        self::assertSame(
            ['key', 'label', 'amount', 'limit'],
            array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionClass(Tier::class))->getProperties()
            )
        );
        foreach (['condition', 'until'] as $sent) {
            $tier = self::tier('alap', 'Alap ár', '49500') + [$sent => null];
            try {
                $this->ladder->propose($this->event, [$tier]);
                self::fail('A tier with a ' . $sent . ' was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->ladder->tiers($this->event));
            }
        }
    }

    public function testARegistrationWrittenWithItsAnswerInOneRunPaysItsTierInThatRun(): void
    {
        $this->applyLadder(self::ivkTiers());
        $key = new RegistrationKey($this->event, '00A0');

        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->insertRegistration($this->newRegistration($this->event, '00A0'), $context);
            $this->values->upsert(new FieldValue($key, PriceLadderComponent::FIELD, 'gy_megosztott'), $context);
        });

        self::assertSame([self::line('44500')], $this->charges($key));
    }

    public function testALaterAnswerChangeNeverReChecksTheTier(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerAnswering('00A0', 'nincs', 'alap', chosen: 'gy');

        $this->writeValue($anna, 'tamogatas', 'teljes');
        $this->writeValue($anna, 'ar', 'emelt');

        self::assertSame([self::line('34500')], $this->charges($anna));
        self::assertNull((new EvaluationFailureRepository())->find($anna));
        // Only a change of the tier answer itself moves the registration to another tier.
        $this->writeValue($anna, PriceLadderComponent::FIELD, 'teljes');
        self::assertSame([self::line('34500'), self::line('35000', 2)], $this->charges($anna));
    }

    public function testWithoutATierAnswerTheAdmissionIsRefusedNeverAZeroBaseNorTheFixedPrice(): void
    {
        $this->applyLadder(self::ivkTiers());
        $this->setPrice('40000');

        try {
            $this->registerChoosing('00A0', null);
            self::fail('The admission went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::ADMIN_NONE_TEXT, $refused->publicMessage());
            self::assertSame('money/price_ladder', $refused->getPrevious()?->getMessage());
        }
        $bela = new RegistrationKey($this->event, '00A0');
        self::assertSame(RegistrationStatus::Waitlisted, $this->registrations->find($bela)?->status);
        self::assertSame([], $this->charges($bela));

        $anna = $this->registerChoosing('00A2', 'alap');
        self::assertSame([self::line('49500')], $this->charges($anna));
    }

    public function testAnAnswerLostAfterAStaffWriteIsRefusedAndAChosenTierGoneFromTheLadderIsRefused(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerChoosing('00A0', 'gy');

        try {
            $this->deleteValue($anna, PriceLadderComponent::FIELD);
            self::fail('The staff write went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::ADMIN_NONE_TEXT, $refused->publicMessage());
        }
        self::assertSame([self::line('34500')], $this->charges($anna));

        $csaba = $this->register('00A2', RegistrationStatus::Waitlisted, '2026-12-01 10:00:00');
        $this->writeValue($csaba, PriceLadderComponent::FIELD, 'korai');
        try {
            $this->setStatus($csaba, RegistrationStatus::Registered, '2026-12-02 10:00:00');
            self::fail('The admission went through.');
        } catch (FeeRefused $refused) {
            self::assertSame(TierSelection::ADMIN_CHOSEN_TEXT, $refused->publicMessage());
        }
        self::assertSame([], $this->charges($csaba));
    }

    public function testTheRegistrantsOwnRefusalIsTheGenericFailureNotAText(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerChoosing('00A0', 'gy');

        try {
            $this->runner->run(
                new PortalProbe([$anna], function (array $input, Context $context) use ($anna): void {
                    $this->lockRegistrations($context, $anna);
                    $stored = $this->values->findForUpdate(
                        ['eventId' => $anna->eventId, 'code' => $anna->code, 'fieldKey' => PriceLadderComponent::FIELD],
                        $context
                    );
                    self::assertNotNull($stored);
                    $stored->value = 'nincs_ilyen';
                    $this->values->upsert($stored, $context);
                }),
                [],
                new SecretLinkActor($anna)
            );
            self::fail('The registrant\'s write went through.');
        } catch (FeeRefused $refused) {
            self::fail('A ladder text reached the registrant: ' . $refused->publicMessage());
        } catch (SystemFailure) {
            self::assertSame([self::line('34500')], $this->charges($anna));
        }
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
        $bela = $this->registerChoosing('00A0', 'ingyenes');

        self::assertSame([], $this->charges($bela));
    }

    public function testAWaitlistAdmissionKeepsTheChoiceAndChargesTheTiersCurrentAmount(): void
    {
        $this->applyLadder(self::ivkTiers());
        $csaba = $this->register('00A0', RegistrationStatus::Waitlisted, '2026-12-01 10:00:00');
        $this->writeValue($csaba, PriceLadderComponent::FIELD, 'gy');
        self::assertSame([], $this->charges($csaba));

        $raised = self::ivkTiers();
        $raised[3]['amount'] = '38000';
        $this->applyLadder($raised);
        $this->setStatus($csaba, RegistrationStatus::Registered, '2027-01-20 10:00:00');

        self::assertSame([self::line('38000')], $this->charges($csaba));
        self::assertSame('gy', $this->values->find(
            ['eventId' => $this->event, 'code' => '00A0', 'fieldKey' => PriceLadderComponent::FIELD]
        )?->value);
    }

    public function testThePreviewShowsOldAndNewAmountsAndWritesNothingThenTheApplySavesAndConverges(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerChoosing('00A0', 'alap');
        $dora = $this->registerChoosing('00A2', 'gy');
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

    public function testATierKeyGivenTwiceIsRefusedBeforeAnythingIsSaved(): void
    {
        try {
            $twice = [self::tier('alap', 'Alap ár', '49500'), self::tier('alap', 'Másik', '1')];
            $this->ladder->propose($this->event, $twice);
            self::fail('The ladder was accepted.');
        } catch (InvalidInput) {
            self::assertSame([], $this->ladder->tiers($this->event));
        }
    }

    public function testALadderChangeThatDropsAChosenTierIsRefusedAtThePreviewUntilStaffMoveTheRegistration(): void
    {
        $this->applyLadder(self::ivkTiers());
        $anna = $this->registerChoosing('00A0', 'gy');

        try {
            $this->ladder->propose($this->event, [self::ivkTiers()[0]]);
            self::fail('The proposal went through.');
        } catch (RuleViolation) {
            self::assertCount(5, $this->ladder->tiers($this->event));
        }
        self::assertNull((new EvaluationFailureRepository())->find($anna));
        self::assertSame([self::line('34500')], $this->charges($anna));

        // Once the registration has another tier, the one it left may go (the re-price then moves its price).
        $this->writeValue($anna, PriceLadderComponent::FIELD, 'alap');
        $this->applyLadder([self::ivkTiers()[0]]);

        self::assertNull((new EvaluationFailureRepository())->find($anna));
        self::assertSame([self::line('34500'), self::line('15000', 2)], $this->charges($anna));
    }

    public function testTheLadderDeclaresPriceTierWithItsTiersAsOptionsAndRedeclaresItOnEverySave(): void
    {
        $this->applyLadder(self::ivkTiers());

        $field = $this->priceTier();
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source);
        self::assertTrue($field->formWritable);
        self::assertSame(PriceLadderComponent::FIELD_TITLE, $field->title);
        self::assertSame(FieldKind::CHOICE, $field->type);
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
        $this->applyLadder(self::ivkTiers());

        $this->registerChoosing('00A0', 'gy');
        $this->registerChoosing('00A2', 'alap');

        self::assertSame([[['gy'], 'Gyülekezeti ár'], [['alap'], 'Alap ár']], $seen);
    }

    private function deleteValue(RegistrationKey $key, string $fieldKey): void
    {
        $this->inRun(function (array $input, Context $context) use ($key, $fieldKey): void {
            $this->lockRegistrations($context, $key);
            $locked = $this->values->findForUpdate(
                ['eventId' => $key->eventId, 'code' => $key->code, 'fieldKey' => $fieldKey],
                $context
            );
            self::assertNotNull($locked);
            $this->values->delete($locked, $context);
        });
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

    private function registerChoosing(
        string $code,
        ?string $tier,
        string $registeredAt = '2026-11-30 08:00:00',
    ): RegistrationKey {
        return $this->registerAnswering($code, null, null, $registeredAt, $tier);
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
