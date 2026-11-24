<?php
/**
 * Reconstructed test runner for GitHubSync.
 *
 * This suite was rebuilt from GithubSync.md after the original tests were
 * lost. It is NOT the original suite.
 *
 * Run:
 *   GITHUBSYNC_WP_ROOT="/path/to/public" \
 *   GITHUBSYNC_PLUGIN_DIR="$HOME/githubsync-dev" \
 *   php -d mysqli.default_socket="..." tests/run-tests.php
 *
 * @package GitHubSync
 */

use GitHubSync\Tests\CaseRunner;

require_once __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/cases/*.php' );
sort( $files );

foreach ( $files as $file ) {
	require_once $file;
}

echo "\nGitHubSync reconstructed test suite\n";
echo 'PHP ' . PHP_VERSION . ' | WordPress ' . get_bloginfo( 'version' ) . "\n";

exit( CaseRunner::summary() );
