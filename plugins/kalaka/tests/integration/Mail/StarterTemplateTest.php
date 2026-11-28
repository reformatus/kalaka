<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Mail\Api\StarterTemplate;
use Kalaka\Mail\Body\BodyCheck;

/** The code-defined starter mail: a plain subject and a body that passes the mail's own save check. */
final class StarterTemplateTest extends MailBodyTestCase
{
    public function testTheSubjectIsPlainText(): void
    {
        self::assertSame('Megkaptuk a jelentkezésedet', StarterTemplate::SUBJECT);
        self::assertStringNotContainsString('{{', StarterTemplate::SUBJECT);
        self::assertStringNotContainsString('data-kalaka-value', StarterTemplate::SUBJECT);
    }

    public function testTheBodyPassesTheMailsOwnSaveCheck(): void
    {
        self::assertNull(BodyCheck::problem(StarterTemplate::body(), $this->create()));
    }

    public function testTheBodyUsesExactlyTheFourDeclaredKeys(): void
    {
        preg_match_all('/data-kalaka-value="([^"]+)"/', StarterTemplate::body(), $matches);

        self::assertSame(
            [
                'recipient.name',
                'event.name',
                'registration.code',
                'registration.link',
            ],
            $matches[1]
        );
    }

    public function testTheBodyIsBlockMarkup(): void
    {
        $body = StarterTemplate::body();

        self::assertStringContainsString('<!-- wp:paragraph -->', $body);
        self::assertStringNotContainsString('money_table', $body);
    }
}
