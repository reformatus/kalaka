<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/** The per-event action-mail catalogue and the event's layout. mail.md, "Configuration". */
final class ActionMailCatalogueTest extends MailBodyTestCase
{
    public function testEveryActionIsListedInOrderOffWithoutABodyAndWrapped(): void
    {
        $event = $this->create();

        $settings = (new ActionMailSettings())->ofEvent($event)->toArray();

        self::assertSame($event, $settings['event_id']);
        self::assertSame('{{content}}', $settings['layout_html']);
        self::assertSame(
            ['registered', 'waitlisted', 'admitted', 'payment_recorded', 'confirmed', 'link_regenerated'],
            array_column($settings['mails'], 'mail')
        );
        self::assertSame('Jelentkezés rögzítve', $settings['mails'][0]['label']);
        foreach ($settings['mails'] as $mail) {
            self::assertFalse($mail['enabled']);
            self::assertNull($mail['body_id']);
            self::assertTrue($mail['layout']);
        }
    }

    public function testAMailIsSwitchedOnWithItsBodyAndAudited(): void
    {
        $event = $this->create();
        $body = $this->document($event, $this->paragraph('Szia'));

        $settings = $this->switchOn($event, 'waitlisted', $body, false);

        self::assertSame(
            [
                'mail' => 'waitlisted',
                'label' => 'Jelentkezés várólistára helyezve',
                'enabled' => true,
                'body_id' => $body,
                'layout' => false,
            ],
            $settings['mails'][1]
        );
        $entries = $this->entriesOf('kalaka/update-action-mail');
        self::assertCount(1, $entries);
        self::assertSame('succeeded', $entries[0]['outcome']);
    }

    public function testAMailIsNotSwitchedOnWithoutABody(): void
    {
        $event = $this->create();

        try {
            $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'enabled' => true]);
            self::fail('Expected a refusal.');
        } catch (RuleViolation $e) {
            self::assertSame('A művelet nem hajtható végre. Előbb add meg a levél szövegét.', $e->getMessage());
        }
        self::assertFalse((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['enabled']);
    }

    public function testTheBodyOfAMailThatIsOnIsNotRemoved(): void
    {
        $event = $this->create();
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));

        $this->expectException(RuleViolation::class);
        $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'body_id' => null]);
    }

    public function testABodyOfAnotherEventOrAnotherPostTypeIsRefused(): void
    {
        $event = $this->create();
        $other = $this->create('Másik');
        $page = self::factory()->post->create(['post_type' => 'page']);

        foreach ([$this->document($other, $this->paragraph('Szia')), $page] as $body) {
            try {
                $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'registered', 'body_id' => $body]);
                self::fail('Expected a refusal.');
            } catch (InvalidInput) {
                self::assertNull((new ActionMailSettings())->ofEvent($event)->toArray()['mails'][0]['body_id']);
            }
        }
    }

    public function testAnUnknownMailOrEventIsRefused(): void
    {
        $event = $this->create();
        try {
            $this->exec(new UpdateActionMail(), ['event_id' => $event, 'mail' => 'checked_in', 'enabled' => false]);
            self::fail('Expected a refusal.');
        } catch (InvalidInput) {
        }

        $this->expectException(NotFound::class);
        $this->exec(new UpdateActionMail(), ['event_id' => $event + 100, 'mail' => 'registered', 'enabled' => false]);
    }

    public function testOnlyFullAccessChangesTheSettings(): void
    {
        $event = $this->create();
        $user = self::factory()->user->create(['role' => 'editor']);

        $this->expectException(PermissionDenied::class);
        $this->exec(
            new UpdateActionMail(),
            ['event_id' => $event, 'mail' => 'registered', 'enabled' => false],
            new UserActor($user)
        );
    }

    public function testALayoutWithOneContentPlaceholderIsStoredAsWritten(): void
    {
        $event = $this->create();
        $html = '<html><body style="color:#333"><div class="keret">{{content}}</div><script>x()</script></body></html>';

        $this->setLayout($event, $html);
        self::assertSame($html, (new ActionMailSettings())->ofEvent($event)->layoutHtml);

        $this->setLayout($event, '{{content}}');
        self::assertSame('{{content}}', (new ActionMailSettings())->ofEvent($event)->layoutHtml);
        self::assertCount(2, $this->entriesOf('kalaka/set-event-mail-layout'));
    }

    public function testALayoutWithoutExactlyOnePlaceholderIsRefused(): void
    {
        $event = $this->create();

        foreach (['', '<p>Nincs helye</p>', '{{content}}<hr>{{content}}', '{{ content }}'] as $html) {
            try {
                $this->exec(new SetEventMailLayout(), ['event_id' => $event, 'html' => $html]);
                self::fail('Expected a refusal.');
            } catch (RuleViolation $e) {
                self::assertSame(
                    'A művelet nem hajtható végre. ' . SetEventMailLayout::NO_PLACEHOLDER,
                    $e->getMessage()
                );
            }
        }
        self::assertSame('{{content}}', (new ActionMailSettings())->ofEvent($event)->layoutHtml);
    }

    public function testOnlyFullAccessSetsTheLayout(): void
    {
        $event = $this->create();
        $user = self::factory()->user->create(['role' => 'administrator']);

        $this->expectException(PermissionDenied::class);
        $this->exec(
            new SetEventMailLayout(),
            ['event_id' => $event, 'html' => '{{content}}'],
            new UserActor($user)
        );
    }
}
