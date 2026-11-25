<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\AccountMailNotReady;
use Kalaka\Events\Api\AccountMode;
use Kalaka\Mail\Body\AccountLinkRule;
use Kalaka\Tests\Integration\Mail\MailBodyTestCase;

/** An account mode is taken only while the "registered" mail is on with a template that has the password link. */
final class AccountModeMailTest extends MailBodyTestCase
{
    private function save(int $id, string $mode): void
    {
        $this->events->update($this->updateInput($id, $this->versionOf($id), ['account_mode' => $mode]), $this->admin);
    }

    private function mode(int $id): AccountMode
    {
        $event = $this->events->find($id);
        self::assertNotNull($event);

        return $event->accountMode;
    }

    private function link(): string
    {
        return $this->paragraph('<span data-kalaka-value="account.password_link">link</span>');
    }

    private function refused(int $id, string $mode): void
    {
        try {
            $this->save($id, $mode);
            self::fail('The mode was accepted.');
        } catch (AccountMailNotReady $e) {
            self::assertSame(AccountLinkRule::NEEDS_LINK, $e->reason);
            self::assertSame('kalaka_account_mail_not_ready', $e->errorCode());
            self::assertSame(AccountMode::Off, $this->mode($id));
        }
    }

    public function testTheModeIsRefusedWhileTheMailIsOff(): void
    {
        $id = $this->filled();

        $this->refused($id, 'mandatory');
        $this->refused($id, 'optional');
        $body = $this->document($id, $this->link());
        $this->updateMail(['event_id' => $id, 'mail' => 'registered', 'body_id' => $body, 'enabled' => false]);
        $this->refused($id, 'mandatory');
    }

    public function testTheModeIsRefusedWhenTheBodyLacksTheLink(): void
    {
        $id = $this->filled();
        $this->switchOn($id, 'registered', $this->document($id, $this->paragraph('Köszönjük!')));

        $this->refused($id, 'mandatory');
        $this->refused($id, 'optional');
    }

    public function testTheModeIsAcceptedWhenTheMailIsOnWithTheLinkAndOffIsAlwaysAllowed(): void
    {
        $id = $this->filled();
        $this->save($id, 'off');
        $this->switchOn($id, 'registered', $this->document($id, $this->link()));

        $this->save($id, 'mandatory');
        self::assertSame(AccountMode::Mandatory, $this->mode($id));
        $this->save($id, 'optional');
        self::assertSame(AccountMode::Optional, $this->mode($id));
        $this->save($id, 'off');
        self::assertSame(AccountMode::Off, $this->mode($id));
    }
}
