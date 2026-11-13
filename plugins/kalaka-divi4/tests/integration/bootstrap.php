<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- a bootstrap script runs code by design

$testsDir = getenv('WP_TESTS_DIR');
if ($testsDir === false || $testsDir === '') {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- reports to the console before WordPress loads
    fwrite(STDERR, "WP_TESTS_DIR is not set; run the integration tests inside wp-env's tests-cli container.\n");
    exit(1);
}

// The repository's root vendor/ is mounted into wp-env by .wp-env.json.
$devVendor = dirname(__DIR__, 4) . '/kalaka-dev-vendor';
require_once $devVendor . '/autoload.php';
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $devVendor . '/yoast/phpunit-polyfills');

// Test classes, cases and doubles load on demand. Kalaka\Tests\Integration\Divi4\ maps to this folder and
// the rest of Kalaka\Tests\Integration\ to core's integration folder, whose cases the adapter's tests may extend.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kalaka\\Tests\\Integration\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $own = 'Divi4\\';
    $file = str_starts_with($relative, $own)
        ? __DIR__ . '/' . str_replace('\\', '/', substr($relative, strlen($own))) . '.php'
        : dirname(__DIR__, 3) . '/kalaka/tests/integration/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once $testsDir . '/includes/functions.php';

// Both plugins sit side by side under wp-content/plugins/; core first, as WordPress's dependency order does.
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 3) . '/kalaka/kalaka.php';
    require dirname(__DIR__, 2) . '/kalaka-divi4.php';
});

require $testsDir . '/includes/bootstrap.php';
