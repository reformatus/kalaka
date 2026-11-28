<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * The date reference "now": the render time at a form's render and re-fetch, the submit time at submit,
 * the moment of the read that renders it after submit; a branch reading it is withheld like an event value.
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

        return new ValueContext(EventValues::form($event, null, Runner::current()->clock()->now()));
    }

    public function testTheFormFactsCarryTheRunnersClockInTheSitesTimeZoneToTheSecond(): void
    {
        update_option('timezone_string', 'Pacific/Auckland');
        $this->withClockAt('2026-11-30 20:00:07');

        $now = $this->facts()->get(Facts::NOW);
        self::assertSame(ValueType::Datetime, $now->type);
        self::assertSame('2026-12-01T09:00:07+13:00', $now->data);
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

    public function testTheFactReadsTheMomentItIsGivenAndNoClockOfItsOwn(): void
    {
        $this->withClockAt('2026-11-30 08:00:00');
        $given = new \DateTimeImmutable('2027-01-05 08:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2027-01-05', substr((string) EventValues::now($given)[Facts::NOW]->data, 0, 10));
    }

    /** The registration is open while it closes after now: the branch holds up to the closing instant. */
    private function whileOpenForm(): int
    {
        $open = [
            'match' => 'all',
            'items' => [
                ['key' => 'event.registration_closes', 'operator' => 'greaterThan', 'value' => ['ref' => 'now']],
            ],
        ];

        return $this->slotForm(
            '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $open]) . ' -->'
            . $this->field('text', 'motivation', ['required' => true])
            . '<!-- /wp:kalaka/if -->'
        );
    }

    public function testAFormOpenedBeforeAnInstantIsJudgedAtSubmitWithTheNewInstant(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $definition = $this->forms->definition($this->readForm($this->whileOpenForm()));
        $answers = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org', 'motivation' => 'Teszt'];

        // The registration closes at 2026-12-15 20:00 (+01:00, 19:00 UTC). Rendered a minute before, the branch shows.
        $this->withClockAt('2026-12-15 18:59:00');
        self::assertTrue($this->forms->availability($definition, [], $this->facts())->shows('motivation'));
        self::assertArrayHasKey('motivation', $this->forms->validate($definition, $answers, $this->facts())->stored());

        // Submitted a minute after the instant (the same calendar day): the branch is gone, the answer dropped,
        // no error for it.
        $this->withClockAt('2026-12-15 19:01:00');
        self::assertFalse($this->forms->availability($definition, [], $this->facts())->shows('motivation'));
        $stored = $this->forms->validate($definition, $answers, $this->facts())->stored();
        self::assertArrayNotHasKey('motivation', $stored);
    }

    public function testADayOnlyConditionKeepsItsWholeDayMeaningAtSubmit(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $started = [
            'match' => 'all',
            'items' => [['key' => 'event.start', 'operator' => 'greaterOrEqual', 'value' => ['ref' => 'now']]],
        ];
        $form = $this->slotForm(
            '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $started]) . ' -->'
            . $this->field('text', 'motivation', ['required' => true])
            . '<!-- /wp:kalaka/if -->'
        );
        $definition = $this->forms->definition($this->readForm($form));

        // The event starts on 2027-07-05 (10:00): the whole day counts as "not yet started past the day".
        $this->withClockAt('2027-07-05 21:59:00');
        self::assertTrue($this->forms->availability($definition, [], $this->facts())->shows('motivation'));
        $this->withClockAt('2027-07-05 22:00:00');
        self::assertFalse($this->forms->availability($definition, [], $this->facts())->shows('motivation'));
    }

    public function testTheNowKeyIsNoKeyAConditionMayNameOnItsOwn(): void
    {
        self::assertArrayNotHasKey(Facts::NOW, EventValues::FORM_TYPES);
        self::assertArrayNotHasKey(Facts::NOW, EventValues::TYPES);
    }
}
