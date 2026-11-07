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

// Test classes, cases and doubles load on demand: Kalaka\Tests\Integration\ maps to this folder.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kalaka\\Tests\\Integration\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/kalaka.php';
});

require $testsDir . '/includes/bootstrap.php';

// Every run starts from a fresh install. WordPress's installer drops only its own tables, and dbDelta adds
// columns but never changes a collation, so the development drop recreates every declared table.
(new Kalaka\Foundation\Seed\SchemaDrop(Kalaka\Tests\Integration\Foundation\Seed\SeedTestCase::modulesForTests()))
    ->run();
