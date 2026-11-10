<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Mail;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Mail\DeliveryError;
use PHPUnit\Framework\TestCase;

/** The stored error of an attempt: masked, one line, with its correlation ID. */
final class DeliveryErrorTest extends TestCase
{
    public function testEveryEmailShapedPieceIsMasked(): void
    {
        self::assertSame(
            'SMTP Error: [cím], <[cím]>, "[cím]"; Teszt Elek ([cím]) failed.',
            DeliveryError::mask(
                'SMTP Error: teszt@example.test, <teszt.elek+x@example.test>, "TESZT@EXAMPLE.TEST";'
                . ' Teszt Elek (a@b) failed.'
            )
        );
    }

    public function testTheTextHoldsTheStageTheCodeTheMaskedMessageAndTheCorrelation(): void
    {
        $message = "Could not send\n to teszt@example.test";
        $error = new DeliveryError(DeliveryError::SEND, 'wp_mail_failed', $message, 'abc');

        self::assertSame('send wp_mail_failed: Could not send to [cím] (correlation abc)', $error->text());
        self::assertSame(
            'send wp_mail_false (correlation abc)',
            (new DeliveryError(DeliveryError::SEND, 'wp_mail_false', null, 'abc'))->text()
        );
    }

    public function testAThrownErrorKeepsOnlyItsClassUnlessItIsAKalakaException(): void
    {
        self::assertSame(
            'compose RuntimeException (correlation abc)',
            DeliveryError::thrown(DeliveryError::COMPOSE, new \RuntimeException('teszt@example.test'), 'abc')->text()
        );
        self::assertSame(
            'compose ' . NotFound::class . ': A keresett elem nem található. (correlation abc)',
            DeliveryError::thrown(DeliveryError::COMPOSE, new NotFound(), 'abc')->text()
        );
    }
}
