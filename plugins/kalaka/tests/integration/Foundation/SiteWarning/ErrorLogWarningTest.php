<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\SiteWarning;

use Kalaka\Foundation\SiteWarning\ErrorLogWarning;
use Kalaka\Foundation\SiteWarning\SiteWarnings;

/** PHP's error log under the web root (engineering.md, "Platform"): a banner and a Site Health test. */
final class ErrorLogWarningTest extends SiteWarningTestCase
{
    private const TITLE = 'A PHP-hibanapló a webgyökér alatt van, így letölthető lehet';

    private const DESCRIPTION = 'A napló személyes adatot tartalmazhat. A webgyökéren kívülre kell tenni '
        . '(tárhelybeállítás vagy WP_DEBUG_LOG), és 30 naponként törölni.';

    private const GOOD_LABEL = 'A PHP-hibanapló a webgyökéren kívül van';

    private const GOOD_DESCRIPTION = 'A napló 30 naponkénti törlését a tárhelyen kell beállítani.';

    private string $previousLog;

    private string $previousDirectory;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->previousLog = (string) ini_get('error_log');
        $this->previousDirectory = (string) getcwd();
        $warnings = new SiteWarnings();
        $warnings->add(new ErrorLogWarning());
        $warnings->register();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        ini_set('error_log', $this->previousLog);
        chdir($this->previousDirectory);

        parent::tear_down();
    }

    /** @return iterable<string, array{string}> */
    public static function underTheWebRoot(): iterable
    {
        yield 'the default debug.log' => [ABSPATH . 'wp-content/debug.log'];
        yield 'the web root itself' => [ABSPATH . 'php-errors.log'];
        yield 'a directory that does not exist' => [ABSPATH . 'logs/new/php.log'];
        yield 'a way back in' => ['/tmp/../' . ltrim(ABSPATH, '/') . 'php.log'];
    }

    /** @dataProvider underTheWebRoot */
    public function testALogUnderTheWebRootShowsTheBannerWithItsPathAndFailsTheSiteHealthTest(string $path): void
    {
        ini_set('error_log', $path);
        $body = '<p>' . self::DESCRIPTION . '</p><details><summary>Részletek</summary><p>' . esc_html($path)
            . '</p></details>';

        self::assertSame(self::banner('notice-error', self::TITLE, $body), $this->notices());
        self::assertSame(
            self::result(
                'kalaka_error_log',
                self::TITLE,
                'critical',
                str_replace('<details>', '<details open>', $body)
            ),
            $this->siteHealth('kalaka_error_log')
        );
    }

    public function testARelativePathIsResolvedFromTheWorkingDirectory(): void
    {
        chdir(ABSPATH);
        ini_set('error_log', 'php-errors.log');

        self::assertStringContainsString(self::TITLE, $this->notices());

        chdir('/tmp');
        self::assertSame('', $this->notices());
    }

    /** @return iterable<string, array{string}> */
    public static function outsideTheWebRoot(): iterable
    {
        yield 'unset: the server log' => [''];
        yield 'syslog' => ['syslog'];
        yield 'the standard error' => ['/dev/stderr'];
        yield 'elsewhere' => ['/tmp/php-errors.log'];
        yield 'a way out' => [ABSPATH . '../php-errors.log'];
        yield 'a sibling with the same prefix' => [rtrim(ABSPATH, '/') . '-logs/php.log'];
    }

    /** @dataProvider outsideTheWebRoot */
    public function testALogOutsideShowsNothingAndPassesTheSiteHealthTest(string $path): void
    {
        ini_set('error_log', $path);

        self::assertSame('', $this->notices());
        self::assertSame(
            self::result('kalaka_error_log', self::GOOD_LABEL, 'good', '<p>' . self::GOOD_DESCRIPTION . '</p>'),
            $this->siteHealth('kalaka_error_log')
        );
        self::assertSame(self::GOOD_LABEL, $this->siteHealthTests()['kalaka_error_log']['label'] ?? null);
    }
}
