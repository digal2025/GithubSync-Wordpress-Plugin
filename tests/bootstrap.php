<?php
/**
 * Test bootstrap.
 *
 * Loads a real WordPress install so the plugin's security services can be
 * exercised against the same runtime they ship with. No PHPUnit / Composer
 * dependency: the runner is a tiny assertion harness (tests/run-tests.php).
 *
 * Usage:
 *   GITHUBSYNC_WP_ROOT=/path/to/public GITHUBSYNC_PLUGIN_DIR=/path/to/plugin php tests/run-tests.php
 *
 * @package GitHubSync
 */

if ( ! defined( 'GITHUBSYNC_TESTS' ) ) {
	define( 'GITHUBSYNC_TESTS', true );
}

$__ghs_wp_root = getenv( 'GITHUBSYNC_WP_ROOT' );
$__ghs_plugin = getenv( 'GITHUBSYNC_PLUGIN_DIR' );

if ( ! $__ghs_wp_root || ! is_dir( $__ghs_wp_root ) ) {
	fwrite( STDERR, "Set GITHUBSYNC_WP_ROOT to a WordPress install root.\n" );
	exit( 2 );
}

if ( ! $__ghs_plugin || ! is_file( $__ghs_plugin . '/githubsync.php' ) ) {
	fwrite( STDERR, "Set GITHUBSYNC_PLUGIN_DIR to the plugin directory.\n" );
	exit( 2 );
}

$__ghs_plugin = rtrim( $__ghs_plugin, '/' );

// The plugin under test must be the one WordPress loads for hooks/tables.
require_once $__ghs_wp_root . '/wp-load.php';

if ( ! defined( 'GITHUBSYNC_DIR' ) ) {
	fwrite( STDERR, "The githubsync plugin is not active on this WordPress install.\n" );
	exit( 2 );
}

if ( rtrim( GITHUBSYNC_DIR, '/' ) !== $__ghs_plugin ) { // phpcs:ignore
	fwrite( STDERR, "GITHUBSYNC_PLUGIN_DIR does not match the active plugin (" . GITHUBSYNC_DIR . ").\n" );
	exit( 2 );
}

require_once __DIR__ . '/lib/TestCase.php';

// Temporary scratch area for fixtures (outside the web root).
if ( ! defined( 'GITHUBSYNC_TEST_TMP' ) ) {
	$tmp = sys_get_temp_dir() . '/githubsync-tests-' . getmypid();
	if ( ! is_dir( $tmp ) ) {
		mkdir( $tmp, 0700, true );
	}
	define( 'GITHUBSYNC_TEST_TMP', $tmp );
}
