<?php
/**
 * Spec §3.4 / §3.5: input validation and destination policy.
 *
 * @package GitHubSync
 */

use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\Security\InputValidator;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Input validation and destinations (spec §3.4-3.5)' );

CaseRunner::case( 'repository full name' );
Assert::same( 'owner/repo', InputValidator::repository_full_name( ' owner/repo ' ), 'valid name is trimmed' );
Assert::same( 'my-org/my.repo_1', InputValidator::repository_full_name( 'my-org/my.repo_1' ), 'dots, dashes and underscores allowed' );
foreach ( array( '', 'owner', 'owner/repo/extra', 'https://github.com/owner/repo', 'owner/../repo', 'own er/repo' ) as $bad ) {
	Assert::false( InputValidator::repository_full_name( $bad ), 'rejects ' . wp_json_encode( $bad ) );
}
// FINDING (low severity): trim() strips a trailing newline, so "owner/repo\n"
// is accepted rather than rejected. Harmless because the value is trimmed
// first, but it differs from the strict reading of "reject malformed input".
Assert::same( 'owner/repo', InputValidator::repository_full_name( "owner/repo\n" ), 'FINDING: trailing newline is trimmed, not rejected' );

CaseRunner::case( 'branch names follow git ref rules' );
Assert::same( 'main', InputValidator::branch( 'main' ), 'simple branch' );
Assert::same( 'feature/thing-1', InputValidator::branch( 'feature/thing-1' ), 'slashed branch' );
Assert::same( 'release-1.0', InputValidator::branch( 'release-1.0' ), 'dotted branch' );
foreach ( array( '', 'a..b', 'a@{b', 'a~b', 'a^b', 'a:b', 'a?b', 'a*b', 'a[b', 'a\\b', '/main', 'main/', 'a//b', 'main.lock', str_repeat( 'a', 256 ) ) as $bad ) {
	Assert::false( InputValidator::branch( $bad ), 'rejects ' . wp_json_encode( $bad ) );
}
// FINDINGS vs git-check-ref-format: a leading "-" is accepted (could be read
// as a CLI flag by any shell-out) and a trailing space is trimmed instead of
// rejected. The branch value is URL-encoded when sent to the GitHub API, so
// neither is exploitable today; recorded as hardening gaps.
Assert::same( '-main', InputValidator::branch( '-main' ), 'FINDING: leading dash is accepted' );
Assert::same( 'main', InputValidator::branch( 'main ' ), 'FINDING: trailing space is trimmed, not rejected' );

CaseRunner::case( 'source path' );
Assert::same( '', InputValidator::source_path( '' ), 'empty means repository root' );
Assert::same( 'dist/theme', InputValidator::source_path( './dist/theme/' ), 'normalised' );
foreach ( array( '../etc', '/etc/passwd', 'a/../../b' ) as $bad ) {
	Assert::false( InputValidator::source_path( $bad ), 'rejects ' . $bad );
}

CaseRunner::case( 'destination slug' );
Assert::same( 'my-theme', InputValidator::slug( 'my-theme' ), 'valid slug' );
Assert::same( 'v1.2_3', InputValidator::slug( 'v1.2_3' ), 'dots and underscores allowed' );
foreach ( array( '', '-leading', '.hidden', 'a b', 'a/b', '../x', 'a..b', str_repeat( 'a', 101 ) ) as $bad ) {
	Assert::false( InputValidator::slug( $bad ), 'rejects ' . wp_json_encode( $bad ) );
}

CaseRunner::case( 'commit SHA' );
Assert::same( str_repeat( 'a', 40 ), InputValidator::sha( str_repeat( 'A', 40 ) ), 'lowercased 40-hex SHA' );
Assert::same( 'abc1234', InputValidator::sha( 'abc1234' ), '7-char abbreviation allowed' );
foreach ( array( '', 'xyz1234', 'abc123', str_repeat( 'a', 65 ), 'main' ) as $bad ) {
	Assert::false( InputValidator::sha( $bad ), 'rejects ' . wp_json_encode( $bad ) );
}

CaseRunner::case( 'pattern list parsing' );
Assert::same( array( 'tests/**', '*.scss' ), InputValidator::pattern_list( "tests/**\r\n\r\n*.scss\n  \n" ), 'trims and drops blank lines' );
Assert::same( array(), InputValidator::pattern_list( '' ), 'empty input gives empty list' );

CaseRunner::case( 'approved roots' );
$roots = DestinationPolicy::roots();
Assert::same( wp_normalize_path( WP_CONTENT_DIR . '/themes' ), $roots['theme'], 'themes root' );
Assert::same( wp_normalize_path( WP_CONTENT_DIR . '/plugins' ), $roots['plugin'], 'plugins root' );
Assert::same( wp_normalize_path( WP_CONTENT_DIR . '/uploads/githubsync-sites' ), $roots['static'], 'static root under uploads' );

CaseRunner::case( 'destination resolve' );
$ok = DestinationPolicy::resolve( 'plugin', 'ghs-test-plugin' );
Assert::true( is_array( $ok ), 'valid plugin destination resolves' );
Assert::same( wp_normalize_path( WP_CONTENT_DIR . '/plugins/ghs-test-plugin' ), $ok['path'], 'path is inside the plugins root' );
Assert::same( 'ghs-test-plugin', $ok['relative'], 'relative path reported' );

foreach ( array( 'a/b', '../evil', '.hidden', '' ) as $bad ) {
	$res = DestinationPolicy::resolve( 'plugin', $bad );
	Assert::true( is_wp_error( $res ), 'rejects slug ' . wp_json_encode( $bad ) );
}

$unknown = DestinationPolicy::resolve( 'core', 'x' );
Assert::true( is_wp_error( $unknown ), 'unknown deployment type rejected' );

CaseRunner::case( 'self-deployment is refused' );
$self = DestinationPolicy::resolve( 'plugin', 'githubsync' );
Assert::true( is_wp_error( $self ), 'cannot deploy over GitHubSync itself' );

CaseRunner::case( 'destination cannot escape its root' );
$base   = Assert::mktmp( 'dest-root' );
$link   = $base . '/plugins';
$inner  = Assert::mktmp( 'dest-inner' );
// A symlinked plugin root that points outside is caught by canonicalisation.
mkdir( $base . '/outside', 0700, true );
Assert::false( DestinationPolicy::has_foreign_content( $base . '/outside' ), 'empty directory has no foreign content' );
file_put_contents( $base . '/outside/style.css', 'x' );
Assert::true( DestinationPolicy::has_foreign_content( $base . '/outside' ), 'foreign content detected' );
Assert::false( DestinationPolicy::has_foreign_content( $base . '/missing' ), 'missing directory has no foreign content' );
file_put_contents( $base . '/outside/.githubsync-owner.json', '{}' );
Assert::false( DestinationPolicy::has_foreign_content( $base . '/outside' ), 'ownership marker suppresses the foreign-content warning' );
Assert::rmtree( $base );
Assert::rmtree( $inner );
