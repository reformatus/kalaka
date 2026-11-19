<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * The date reference "now": the render time at a form's render and re-fetch, the submit time at submit,
 * the runner's clock in an evaluation; a branch reading it is withheld like an event value.
 */
final class ConditionNowTest extends FormsTestCase
{
    private function withClockAt(string $instant): void
    {
        $this->useClock(new TestClock(new \DateTimeImmutable($instant, new \DateTimeZone('UTC'))));
        Runner::publish($this->runner);
    }

    /** @return array<string, mixed> born in the last sixteen years from now */
    private function under16(): array
    {
        return [
            'match' => 'all',
            'items' => [
                ['key' => 'born', 'operator' => 'greaterThan', 'value' => ['ref' => 'now', 'years' => -16]],
            ],
        ];
    }

    private function form(): int
    {
        return $this->slotForm(
            $this->field('date', 'born', ['required' => true])
            . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $this->under16()]) . ' -->'
            . '<!-- wp:paragraph --><p>Teszt</p><!-- /wp:paragraph --><!-- /wp:kalaka/if -->'
            . '<!-- wp:kalaka/else -->' . $this->field('text', 'motivation', ['required' => true])
            . '<!-- /wp:kalaka/else -->'
        );
    }

    private function facts(): ValueContext
    {
        $event = $this->events->find($this->published());
        self::assertNotNull($event);

        return new ValueContext(EventValues::form($event, null));
    }

    public function testTheFormFactsCarryTheDayOfTheRunnersClockInTheSitesTimeZone(): void
    {
        update_option('timezone_string', 'Pacific/Auckland');
        $this->withClockAt('2026-11-30 20:00:00');

        self::assertSame('2026-12-01', $this->facts()->get(Facts::NOW)->data);
    }

    public function testAChainReadingNowIsWithheldLikeAnEventValue(): void
    {
        $definition = $this->forms->definition($this->readForm($this->form()));

        self::assertSame([0], $definition->withheldChains());
        self::assertSame(['born'], $definition->triggers);
    }

    public function testTheRenderOrRefetchReadsTheRenderTime(): void
    {
        $definition = $this->forms->definition($this->readForm($this->form()));
        $answers = ['born' => '2011-01-01'];

        $this->withClockAt('2026-11-30 08:00:00');
        $early = $this->forms->availability($definition, $answers, $this->facts());
        $this->withClockAt('2027-06-01 08:00:00');
        $late = $this->forms->availability($definition, $answers, $this->facts());

        self::assertSame([0], $early->chains);
        self::assertFalse($early->shows('motivation'));
        self::assertSame([1], $late->chains);
        self::assertTrue($late->shows('motivation'));
    }

    public function testTheSubmitReadsTheSubmitTime(): void
    {
        $definition = $this->forms->definition($this->readForm($this->form()));
        $answers = [
            'name' => 'Teszt Elek',
            'email' => 'teszt@example.org',
            'born' => '2011-01-01',
            'motivation' => 'Teszt',
        ];

        $this->withClockAt('2026-11-30 08:00:00');
        $dropped = $this->forms->validate($definition, $answers, $this->facts());
        $this->withClockAt('2027-06-01 08:00:00');
        $kept = $this->forms->validate($definition, $answers, $this->facts());

        self::assertSame(['name', 'email', 'born'], array_keys($dropped->stored()));
        self::assertSame(['name', 'email', 'born', 'motivation'], array_keys($kept->stored()));
    }

    public function testAnEvaluationReadsTheRunnersClock(): void
    {
        $this->withClockAt('2026-11-30 08:00:00');
        self::assertSame('2026-11-30', EventValues::now()[Facts::NOW]->data);
        $this->withClockAt('2027-01-05 08:00:00');
        self::assertSame('2027-01-05', EventValues::now()[Facts::NOW]->data);
    }

    public function testTheNowKeyIsNoKeyAConditionMayNameOnItsOwn(): void
    {
        self::assertArrayNotHasKey(Facts::NOW, EventValues::FORM_TYPES);
        self::assertArrayNotHasKey(Facts::NOW, EventValues::TYPES);
    }
}
