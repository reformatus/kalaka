<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Mail;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Mail\Body\AccountLinkRule;
use Kalaka\Mail\Body\PasswordLinkRequired;
use Kalaka\Mail\Body\RegisteredMailStaysOn;
use Kalaka\Mail\Catalogue\BodyTaken;
use Kalaka\Mail\Catalogue\LayoutOtherPlaceholder;
use Kalaka\Mail\Catalogue\LayoutPlaceholderCount;
use Kalaka\Mail\Catalogue\MailNeedsBody;
use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use PHPUnit\Framework\TestCase;

/** T510-T518's refusals of the mail catalogue: a code of its own each, and the text it always had. */
final class MailRefusalsTest extends TestCase
{
    public function testEachRefusalHasItsOwnCodeAndKeepsItsApprovedText(): void
    {
        $refusals = [
            [new MailNeedsBody(), 'kalaka_mail_no_body', UpdateActionMail::NO_BODY],
            [new BodyTaken(), 'kalaka_mail_body_taken', UpdateActionMail::BODY_TAKEN],
            [new PasswordLinkRequired(), 'kalaka_mail_needs_password_link', AccountLinkRule::NEEDS_LINK],
            [new RegisteredMailStaysOn(), 'kalaka_mail_registered_stays_on', AccountLinkRule::NO_SWITCH_OFF],
            [new LayoutPlaceholderCount(), 'kalaka_mail_layout_placeholder', SetEventMailLayout::NO_PLACEHOLDER],
            [
                new LayoutOtherPlaceholder(),
                'kalaka_mail_layout_other_placeholder',
                SetEventMailLayout::OTHER_PLACEHOLDER,
            ],
        ];
        $codes = [];
        foreach ($refusals as [$refusal, $code, $text]) {
            self::assertInstanceOf(RuleViolation::class, $refusal);
            self::assertSame($code, $refusal->errorCode());
            self::assertSame(422, $refusal->status());
            self::assertSame('A művelet nem hajtható végre. ' . $text, $refusal->publicMessage());
            $codes[] = $code;
        }
        self::assertCount(6, array_unique($codes));
        self::assertNotSame((new RuleViolation())->errorCode(), $codes[0]);
    }
}
