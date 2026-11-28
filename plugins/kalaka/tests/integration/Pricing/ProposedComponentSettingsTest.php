<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\Api\SettingsSave;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** The proposed-component path through the list, the preview and the apply's save loop (C4-1, C4-2). */
final class ProposedComponentSettingsTest extends PricingTestCase
{
    public function testTheListAndForComponentUseTheProposedComponent(): void
    {
        $stored = new TierComponent('1000', 'ladder');
        $replacement = new TierComponent('2000', 'ladder');
        $settings = self::proposed('ladder', $replacement);
        $proposed = new ProposedMoneySettings(components: [$settings]);

        self::assertSame($settings, $proposed->forComponent('ladder'));
        self::assertNull($proposed->forComponent('lunch'));

        $listed = [];
        foreach ((new CoreFeeComponents([$stored]))->list($proposed) as $component) {
            $listed[$component->id()] = $component;
        }
        self::assertSame($replacement, $listed['ladder']);
    }

    public function testAValidProposedComponentIsAcceptedAndPreviewed(): void
    {
        $this->useComponents(new TierComponent('1000', 'ladder'));
        $this->register('00A0');

        $preview = $this->settings->preview($this->event, $this->proposedLadder('2000'), new CliActor(null));

        self::assertCount(1, $preview);
        self::assertSame('2000', self::plain($preview[0]->after[Payer::PARTICIPANT]->amount()));
    }

    public function testADuplicateProposedComponentIdIsRefusedByBothSteps(): void
    {
        $this->useComponents(new TierComponent('1000', 'ladder'));
        $first = self::proposed('ladder', new TierComponent('2000', 'ladder'));
        $second = self::proposed('ladder', new TierComponent('3000', 'ladder'));

        $this->assertBothRefuse(new ProposedMoneySettings(components: [$first, $second]));
    }

    public function testAProposedComponentIdNoComponentHasIsRefusedByBothSteps(): void
    {
        $this->useComponents(new TierComponent('1000', 'ladder'));

        $this->assertBothRefuse(new ProposedMoneySettings(components: [
            self::proposed('lunch', new TierComponent('2000', 'lunch')),
        ]));
    }

    public function testTheApplySavesEveryProposedComponentInOrderThenConverges(): void
    {
        $calls = [];
        $this->useComponents(new TierComponent('1000', 'ladder'), new TierComponent('500', 'lunch'));
        $key = $this->register('00A0');

        $report = $this->settings->apply($this->event, new ProposedMoneySettings(components: [
            self::proposed('ladder', new TierComponent('2000', 'ladder'), $calls, 'ladder'),
            self::proposed('lunch', new TierComponent('600', 'lunch'), $calls, 'lunch'),
        ]), new CliActor(null));

        self::assertSame(['ladder', 'lunch'], $calls);
        self::assertSame([], $report->failed());
        self::assertSame([
            'ladder participant 1000 charge:ladder:participant:1',
            'lunch participant 500 charge:lunch:participant:1',
        ], $this->charges($key));
    }

    public function testAFailingSaveStopsTheApplyBeforeTheLaterSavesAndAnyRegistration(): void
    {
        $calls = [];
        $this->useComponents(new TierComponent('1000', 'ladder'), new TierComponent('500', 'lunch'));
        $key = $this->register('00A0');
        $lines = $this->lineCount();

        try {
            $this->settings->apply($this->event, new ProposedMoneySettings(components: [
                self::proposed('ladder', new TierComponent('2000', 'ladder'), failing: true),
                self::proposed('lunch', new TierComponent('600', 'lunch'), $calls, 'lunch'),
            ]), new CliActor(null));
            self::fail('The apply went on after a failing save.');
        } catch (SystemFailure) {
        }

        self::assertSame([], $calls, 'The save after the failing one did not run.');
        self::assertSame($lines, $this->lineCount(), 'No registration was touched.');
        self::assertSame('1500', $this->balance($key));
    }

    private function proposedLadder(string $amount): ProposedMoneySettings
    {
        return new ProposedMoneySettings(components: [
            self::proposed('ladder', new TierComponent($amount, 'ladder')),
        ]);
    }

    /**
     * A proposed component setting whose save records its name in `$calls`, or fails when asked.
     *
     * @param list<string> $calls
     */
    private static function proposed(
        string $id,
        TierComponent $component,
        array &$calls = [],
        ?string $name = null,
        bool $failing = false,
    ): ProposedTestSettings {
        $body = static function () use (&$calls, $name, $failing): void {
            if ($failing) {
                throw new \RuntimeException('A failing save.');
            }
            if ($name !== null) {
                $calls[] = $name;
            }
        };

        return new ProposedTestSettings($id, $component, new SettingsSave(new AdminProbe($body), []));
    }

    private function assertBothRefuse(ProposedMoneySettings $proposed): void
    {
        $lines = $this->lineCount();
        foreach (['preview', 'apply'] as $step) {
            try {
                if ($step === 'preview') {
                    $this->settings->preview($this->event, $proposed, new CliActor(null));
                } else {
                    $this->settings->apply($this->event, $proposed, new CliActor(null));
                }
                self::fail('The ' . $step . ' took ' . $proposed->components[0]->componentId() . '.');
            } catch (InvalidInput) {
            }
        }
        self::assertSame($lines, $this->lineCount());
    }

    private static function plain(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
