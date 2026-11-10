<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Mail\Pause\PauseCause;
use Kalaka\Mail\Pause\PauseWarning;
use Kalaka\Mail\Pause\RequestState;

/** The two mail warnings. foundation.md, "Site warnings". */
final class PauseWarningTest extends MailTestCase
{
    public function testNeitherShowsWhileSendingRuns(): void
    {
        $state = new RequestState();

        self::assertNull((new PauseWarning(PauseCause::Manual, $state))->check());
        self::assertNull((new PauseWarning(PauseCause::Errors, $state))->check());
    }

    public function testAPauseByHandShowsTheInfoBannerOnly(): void
    {
        $this->storeState(['paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'manual', 'paused_by' => 3]);
        $state = new RequestState();

        $shown = (new PauseWarning(PauseCause::Manual, $state))->check();

        self::assertNotNull($shown);
        self::assertSame(Severity::Info, $shown->severity);
        self::assertSame('A levélküldés szünetel', $shown->title);
        self::assertSame(
            'A levelek a küldési sorban gyűlnek. A küldés a Kaláka › Levelezés oldalon folytatható.',
            $shown->description
        );
        self::assertNull($shown->details);
        self::assertNull((new PauseWarning(PauseCause::Errors, $state))->check());
    }

    public function testAPauseAfterErrorsShowsTheErrorBannerWithTheDeliveryTheTimeAndTheMaskedError(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $this->storeState([
            'paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'errors', 'errors_in_row' => 2,
            'delivery_id' => 7, 'delivery_event_id' => 5,
            'error' => 'send wp_mail_failed: SMTP Error: [cím] (correlation 1234)',
        ]);
        $state = new RequestState();

        $shown = (new PauseWarning(PauseCause::Errors, $state))->check();

        self::assertNotNull($shown);
        self::assertSame(Severity::Error, $shown->severity);
        self::assertSame('A levélküldés hiba miatt szünetel', $shown->title);
        self::assertStringStartsWith('Két egymást követő küldési kísérlet hibával zárult', $shown->description);
        self::assertSame(
            "Delivery 7 of event 5\nPaused at 2026-11-30 09:00:00\n"
            . 'Error: send wp_mail_failed: SMTP Error: [cím] (correlation 1234)',
            $shown->details
        );
        self::assertNull((new PauseWarning(PauseCause::Manual, $state))->check());
        self::assertNull((new PauseWarning(PauseCause::Errors, $state))->siteHealth());
    }

    public function testBothWarningsShareOneReadPerRequest(): void
    {
        $state = new RequestState();
        $manual = new PauseWarning(PauseCause::Manual, $state);
        $errors = new PauseWarning(PauseCause::Errors, $state);
        $selects = 0;
        $count = static function (string $sql) use (&$selects): string {
            if (str_contains($sql, 'kalaka_mail_states')) {
                $selects++;
            }

            return $sql;
        };
        add_filter('query', $count);
        try {
            $manual->check();
            $errors->check();
            $manual->check();
        } finally {
            remove_filter('query', $count);
        }

        self::assertSame(1, $selects);
    }
}
