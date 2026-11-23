<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The evaluation's mail step (foundation.md, "Evaluation"; mail.md, "Five kinds of mail"): every `confirmed`
 * record queues the event's „Jelentkezés véglegesítve” mail, keyed by that record, while the event has it on
 * (the owner's ruling 2026-11-23, topic 15, Q4). Nothing is skipped: a re-confirmation right after staff's
 * unconfirm, a retry and a restore that qualifies each mail.
 */
final class ConfirmedMailTest extends ConfirmationTestCase
{
    public function testAConfirmedRecordQueuesTheMailKeyedByTheRecord(): void
    {
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        self::assertSame([], $this->deliveryRows(), 'Unconfirmed: nothing to mail.');

        $this->payOn($anna, '10000', '2026-11-20');

        self::assertCount(1, $this->deliveryRows());
        self::assertSame('00A0', $this->deliveryRows()[0]['code']);
        self::assertSame('pending', $this->deliveryRows()[0]['status']);
        $this->assertMailedPerConfirmedRecord($anna);
    }

    public function testWhileTheEventHasTheMailOffNothingIsQueued(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '10000', '2026-11-20');

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertSame([], $this->deliveryRows());
    }

    public function testAnEvaluationThatWritesNoConfirmedRecordQueuesNothingMore(): void
    {
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        self::assertCount(1, $this->deliveryRows());

        $this->evaluate($anna);
        $this->refundOn($anna, '4000', '2026-11-22');

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(1, $this->deliveryRows(), 'A refund never unconfirms, so nothing is written or mailed.');
    }

    public function testAReConfirmationRightAfterStaffsUnconfirmMailsAgainInTheSamePass(): void
    {
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        self::assertCount(1, $this->deliveryRows());

        $this->unconfirm($anna);

        self::assertCount(3, $this->confirmations($anna), 'confirmed, unconfirmed, confirmed in one pass.');
        self::assertCount(2, $this->deliveryRows());
        $this->assertMailedPerConfirmedRecord($anna);
    }

    public function testEachRetryOfTheUnconfirmMailsAgain(): void
    {
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');

        $this->unconfirm($anna);
        $this->unconfirm($anna);

        self::assertCount(5, $this->confirmations($anna));
        self::assertCount(3, $this->deliveryRows());
        $this->assertMailedPerConfirmedRecord($anna);
    }

    public function testAnUnconfirmThatDoesNotConfirmAgainMailsNothing(): void
    {
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '4000', '2026-11-20');
        $this->setFlag($anna);
        self::assertCount(1, $this->deliveryRows(), 'The flag confirmed it.');

        $this->unconfirm($anna);

        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertCount(1, $this->deliveryRows());
    }

    public function testARestoreThatQualifiesMailsAndOneThatDoesNotMailsNothing(): void
    {
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $bela = $this->register('00B0');
        $this->payOn($bela, '10000', '2026-11-20');
        self::assertCount(2, $this->deliveryRows());

        $this->setStatus($anna, RegistrationStatus::Withdrawn);
        $this->setStatus($bela, RegistrationStatus::Removed);
        self::assertCount(2, $this->deliveryRows(), 'The closing `unconfirmed` mails nothing.');

        $this->setPrice('15000');
        $this->setStatus($anna, RegistrationStatus::Registered);
        self::assertSame('unconfirmed', $this->confirmationState($anna), 'The price rose while withdrawn.');
        self::assertCount(2, $this->deliveryRows());

        $this->setPrice('10000');
        $this->setStatus($bela, RegistrationStatus::Registered);

        self::assertSame('confirmed', $this->confirmationState($bela));
        self::assertCount(4, $this->deliveryRows(), 'Anna confirmed again once the price fell, and Béla on restore.');
        $this->assertMailedPerConfirmedRecord($anna);
        $this->assertMailedPerConfirmedRecord($bela);
    }
}
