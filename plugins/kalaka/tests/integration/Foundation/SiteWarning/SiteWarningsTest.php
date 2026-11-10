<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\SiteWarning;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\SiteWarning\SiteWarnings;

/** The banner renderer and the Site Health adapter (foundation.md, "Site warnings"). */
final class SiteWarningsTest extends SiteWarningTestCase
{
    private SiteWarnings $warnings;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->warnings = new SiteWarnings();
        $this->warnings->register();
    }

    private static function error(string $title, ?string $details = null): Shown
    {
        return new Shown(Severity::Error, $title, 'Leírás.', $details);
    }

    public function testErrorsShowFirstThenInfoEachInTheOrderAdded(): void
    {
        $this->warnings->add(new FakeWarning('a', new Shown(Severity::Info, 'Első info', 'Leírás.')));
        $this->warnings->add(new FakeWarning('b', self::error('Első hiba')));
        $this->warnings->add(new FakeWarning('c', null));
        $this->warnings->add(new FakeWarning('d', new Shown(Severity::Info, 'Második info', 'Leírás.')));
        $this->warnings->add(new FakeWarning('e', self::error('Második hiba')));

        self::assertSame(
            self::banner('notice-error', 'Első hiba', '<p>Leírás.</p>')
            . self::banner('notice-error', 'Második hiba', '<p>Leírás.</p>')
            . self::banner('notice-info', 'Első info', '<p>Leírás.</p>')
            . self::banner('notice-info', 'Második info', '<p>Leírás.</p>'),
            $this->notices()
        );
    }

    public function testTheDetailsAreCollapsedOneLinePerItemAndEverythingIsEscaped(): void
    {
        $this->warnings->add(new FakeWarning(
            'a',
            new Shown(Severity::Error, 'Cím <b>', 'Leírás & <i>', "first <line>\nsecond & line")
        ));

        self::assertSame(
            self::banner(
                'notice-error',
                'Cím &lt;b&gt;',
                '<p>Leírás &amp; &lt;i&gt;</p><details><summary>Részletek</summary>'
                . '<p>first &lt;line&gt;<br>second &amp; line</p></details>'
            ),
            $this->notices()
        );
    }

    public function testNothingShowsWhileEveryCheckFindsNothing(): void
    {
        $this->warnings->add(new FakeWarning('a', null));

        self::assertSame('', $this->notices());
    }

    public function testAUserWithFullAccessSeesTheBannersWhileTheSiteIsOpen(): void
    {
        $this->warnings->add(new FakeWarning('a', self::error('Hiba')));
        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_userdata($user)->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);

        self::assertStringContainsString('Hiba', $this->notices());
    }

    public function testWhileTheSiteIsClosedOnlyUsersWhoMaySeeSiteHealthSeeThem(): void
    {
        $this->warnings->add(new FakeWarning('a', self::error('Hiba')));
        $this->closeTheSite();
        self::assertStringContainsString('Hiba', $this->notices());

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_userdata($user)->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);
        self::assertSame('', $this->notices());
    }

    public function testOtherUsersSeeNoneAndRunNoCheck(): void
    {
        $warning = new FakeWarning('a', self::error('Hiba'));
        $this->warnings->add($warning);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        self::assertSame('', $this->notices());
        self::assertSame(0, $warning->checks);
    }

    public function testACheckThatThrowsShowsNoBannerAndIsLogged(): void
    {
        $this->warnings->add(new FakeWarning('broken', new \RuntimeException('check failed')));
        $this->warnings->add(new FakeWarning('fine', self::error('Hiba')));

        $html = '';
        $log = $this->capturedErrorLog(function () use (&$html): void {
            $html = $this->notices();
        });

        self::assertSame(self::banner('notice-error', 'Hiba', '<p>Leírás.</p>'), $html);
        self::assertSame(
            [
                'Kaláka: site warning broken failed, correlation ' . Correlation::id()
                . ': RuntimeException (0): check failed',
            ],
            self::logLines($log)
        );
    }

    public function testOnlyAWarningWithASiteHealthTestAddsOne(): void
    {
        $this->warnings->add(new FakeWarning('a', null));
        $this->warnings->add(new FakeWarning('b', null, new SiteHealth('kalaka_test_b', 'Rendben', 'Minden jó.')));

        $tests = $this->siteHealthTests();
        self::assertSame(['kalaka_test_b'], array_keys($tests));
        self::assertIsArray($tests['kalaka_test_b']);
        self::assertSame('Rendben', $tests['kalaka_test_b']['label']);
    }

    public function testTheSiteHealthTestPassesWithItsGoodTextsWhileTheCheckFindsNothing(): void
    {
        $health = new SiteHealth('kalaka_test_a', 'Rendben <b>', 'Minden & jó.');
        $this->warnings->add(new FakeWarning('a', null, $health));

        self::assertSame(
            self::result('kalaka_test_a', 'Rendben <b>', 'good', '<p>Minden &amp; jó.</p>'),
            $this->siteHealth('kalaka_test_a')
        );
    }

    public function testAnErrorIsCriticalWithTheDetailsOpenAndAnInfoIsRecommended(): void
    {
        $health = new SiteHealth('kalaka_test_a', 'Rendben', 'Minden jó.');
        $this->warnings->add(new FakeWarning('a', self::error('Hiba', "egy\nkettő"), $health));
        $this->warnings->add(new FakeWarning(
            'b',
            new Shown(Severity::Info, 'Info', 'Leírás.'),
            new SiteHealth('kalaka_test_b', 'Rendben', 'Minden jó.')
        ));

        self::assertSame(
            self::result(
                'kalaka_test_a',
                'Hiba',
                'critical',
                '<p>Leírás.</p><details open><summary>Részletek</summary><p>egy<br>kettő</p></details>'
            ),
            $this->siteHealth('kalaka_test_a')
        );
        self::assertSame(
            self::result('kalaka_test_b', 'Info', 'recommended', '<p>Leírás.</p>'),
            $this->siteHealth('kalaka_test_b')
        );
    }

    public function testASiteHealthCheckThatThrowsIsCriticalWithTheBoundarysTextAndIsLogged(): void
    {
        $this->warnings->add(new FakeWarning(
            'broken',
            new \RuntimeException('check failed'),
            new SiteHealth('kalaka_test_broken', 'Rendben', 'Minden jó.')
        ));

        $result = [];
        $log = $this->capturedErrorLog(function () use (&$result): void {
            $result = $this->siteHealth('kalaka_test_broken');
        });

        self::assertSame(
            self::result(
                'kalaka_test_broken',
                'Váratlan hiba történt. Próbáld újra később.',
                'critical',
                '<p>Hibaazonosító: ' . substr(Correlation::id(), 0, 8) . '</p>'
            ),
            $result
        );
        self::assertStringContainsString('site warning broken failed', $log);
    }

    public function testTheSiteHealthTestRunsWithoutAUserForTheWeeklyCronCheck(): void
    {
        $this->warnings->add(new FakeWarning('a', self::error('Hiba'), new SiteHealth('kalaka_test_a', 'Rendben', '')));
        wp_set_current_user(0);

        self::assertSame('critical', $this->siteHealth('kalaka_test_a')['status']);
    }
}
