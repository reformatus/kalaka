<?php

declare(strict_types=1);

$testsDir = getenv('WP_TESTS_DIR');
if ($testsDir === false || $testsDir === '') {
    fwrite(STDERR, "WP_TESTS_DIR is not set; run the integration tests inside wp-env's tests-cli container.\n");
    exit(1);
}

// The repository's root vendor/ is mounted into wp-env by .wp-env.json.
$devVendor = dirname(__DIR__, 4) . '/kalaka-dev-vendor';
require_once $devVendor . '/autoload.php';
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $devVendor . '/yoast/phpunit-polyfills');

require_once $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/kalaka.php';
});

require $testsDir . '/includes/bootstrap.php';
