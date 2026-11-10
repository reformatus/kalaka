<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Catalogue\SetMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/** The per-event action-mail catalogue and the event's layout. mail.md, "Configuration". */
final class ActionMailCatalogueTest extends MailBodyTestCase
{
    public function testEveryActionIsListedInOrderOffWithoutABodyAndWrapped(): void
    {
        $event = $this->create();

        $settings = (new ActionMailSettings())->ofEvent($event)->toArray();

        self::assertSame($event, $settings['event_id']);
        self::assertNull($settings['layout_id']);
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

    public function testALayoutWithExactlyOneSlotIsSetAndRemoved(): void
    {
        $event = $this->create();
        $layout = $this->document($event, $this->paragraph('Fejléc') . "\n<!-- wp:kalaka/mail-content /-->");

        $this->setLayout($event, $layout);
        self::assertSame($layout, (new ActionMailSettings())->ofEvent($event)->layoutId);

        $this->setLayout($event, null);
        self::assertNull((new ActionMailSettings())->ofEvent($event)->layoutId);
        self::assertCount(2, $this->entriesOf('kalaka/set-mail-layout'));
    }

    public function testALayoutWithoutOneSlotIsRefused(): void
    {
        $event = $this->create();
        $none = $this->document($event, $this->paragraph('Fejléc'));
        $two = $this->document(
            $event,
            "<!-- wp:kalaka/mail-content /-->\n<!-- wp:group -->\n<div class=\"wp-block-group\">"
            . "<!-- wp:kalaka/mail-content /--></div>\n<!-- /wp:group -->"
        );

        foreach ([$none, $two] as $layout) {
            try {
                $this->exec(new SetMailLayout(), ['event_id' => $event, 'layout_id' => $layout]);
                self::fail('Expected a refusal.');
            } catch (RuleViolation $e) {
                self::assertSame(
                    'A művelet nem hajtható végre. Az elrendezésben pontosan egy „Levél szövege” blokknak kell lennie.',
                    $e->getMessage()
                );
            }
        }
        self::assertNull((new ActionMailSettings())->ofEvent($event)->layoutId);
    }
}
