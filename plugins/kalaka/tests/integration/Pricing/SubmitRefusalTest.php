<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Events\PublishEvent;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Pricing\Api\FeeRefusal;
use Kalaka\Pricing\Api\FeeRefused;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * A registrant's own submit through the real submit, with a fee component that refuses (#200 N11, N15): the
 * submit is refused with the component's text for the registrant, and nothing is written.
 */
final class SubmitRefusalTest extends FormsTestCase
{
    public function testARefusingFeeComponentRefusesTheRegistrantsSubmitWithItsText(): void
    {
        $refuse = static function (): FeeTargets {
            throw new FeeRefusal('Munkatársi szöveg.', 'Jelentkezői szöveg.', 'test_refusal');
        };
        $this->runner = new Runner(
            new TestClock(),
            true,
            new Evaluation(
                new DecisionRules(),
                money: new Materializer(new CoreFeeComponents([new ClosureComponent('tier', FeeStage::Tier, $refuse)]))
            )
        );
        Runner::publish($this->runner);

        $event = $this->create();
        $this->update($event, ['capacity' => 10]);
        $this->transition(new PublishEvent(), $event);
        $form = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $event]);
        $version = $this->forms->form($form)?->version ?? 1;

        try {
            (new RegistrationSubmit($this->runner))->submit(
                new SubmitRequest(
                    $form,
                    $version,
                    ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'],
                    SubmitToken::generate(),
                    [],
                    false
                ),
                new GuestActor()
            );
            self::fail('The submit went through.');
        } catch (FeeRefused $refused) {
            self::assertSame('Jelentkezői szöveg.', $refused->publicMessage());
            self::assertSame(422, $refused->toWpError()->get_error_data()['status'] ?? null);
        }

        global $wpdb;
        self::assertSame('0', $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $event
        )));
    }
}
