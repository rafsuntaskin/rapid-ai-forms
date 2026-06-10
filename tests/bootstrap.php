<?php
/**
 * PHPUnit bootstrap — loads the WordPress test suite with this plugin active.
 *
 * Run via wp-env: `npm run test:php` (see package.json). The tests container
 * exposes the suite at WP_TESTS_DIR (/wordpress-phpunit).
 *
 * @package Rapid_Ai_Forms
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Composer autoloader (PHPUnit polyfills required by the WP suite).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Is wp-env running? Try: npx wp-env start" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/rapid-ai-forms.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
