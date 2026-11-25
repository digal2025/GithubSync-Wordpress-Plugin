<?php
/**
 * Self-update: manifest validation and version comparison.
 *
 * @package GitHubSync
 */

use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Update\Updater;

CaseRunner::group( 'Self-update manifest' );

$sha = str_repeat( 'a1b2', 16 );

$valid = array(
	'version'      => 'v1.4.0',
	'download_url' => 'https://github.com/digal2025/GithubSync-Wordpress-Plugin/releases/download/v1.4.0/githubsync.zip',
	'sha256'       => 'sha256:' . $sha,
);

CaseRunner::case( 'valid manifest normalises fields' );
$release = Updater::validate_manifest( $valid );
Assert::false( is_wp_error( $release ), 'valid manifest accepted' );
Assert::same( '1.4.0', $release['version'], 'leading v stripped' );
Assert::same( $sha, $release['sha256'], 'sha256: prefix stripped and lowercased' );
Assert::same( 'githubsync', $release['slug'], 'slug defaults to githubsync' );

CaseRunner::case( 'bare digest accepted' );
$bare = Updater::validate_manifest( array_merge( $valid, array( 'sha256' => $sha ) ) );
Assert::false( is_wp_error( $bare ), 'bare 64 hex digest accepted' );

CaseRunner::case( 'non-object rejected' );
Assert::true( is_wp_error( Updater::validate_manifest( 'nope' ) ), 'string manifest rejected' );
Assert::true( is_wp_error( Updater::validate_manifest( null ) ), 'null manifest rejected' );

CaseRunner::case( 'required fields enforced' );
foreach ( array( 'version', 'download_url', 'sha256' ) as $field ) {
	$broken = $valid;
	unset( $broken[ $field ] );
	Assert::true( is_wp_error( Updater::validate_manifest( $broken ) ), $field . ' is required' );
}

CaseRunner::case( 'bad version rejected' );
Assert::true( is_wp_error( Updater::validate_manifest( array_merge( $valid, array( 'version' => 'latest' ) ) ) ), 'non-numeric version rejected' );
Assert::true( is_wp_error( Updater::validate_manifest( array_merge( $valid, array( 'version' => '' ) ) ) ), 'empty version rejected' );

CaseRunner::case( 'bad digest rejected' );
Assert::true( is_wp_error( Updater::validate_manifest( array_merge( $valid, array( 'sha256' => 'deadbeef' ) ) ) ), 'short digest rejected' );
$bad_hex = array_merge( $valid, array( 'sha256' => str_repeat( 'z', 64 ) ) );
Assert::true( is_wp_error( Updater::validate_manifest( $bad_hex ) ), 'non-hex digest rejected' );

CaseRunner::case( 'package URL must be an HTTPS GitHub release' );
Assert::true( Updater::is_allowed_package_url( $valid['download_url'] ), 'github.com release URL allowed' );
Assert::false( Updater::is_allowed_package_url( 'http://github.com/x/y.zip' ), 'plain http rejected' );
Assert::false( Updater::is_allowed_package_url( 'https://evil.example.com/githubsync.zip' ), 'foreign host rejected' );
Assert::false( Updater::is_allowed_package_url( 'https://github.com.evil.example/githubsync.zip' ), 'look-alike host rejected' );
Assert::false( Updater::is_allowed_package_url( '' ), 'empty URL rejected' );

CaseRunner::case( 'objects.githubusercontent.com redirect allowed' );
Assert::true( Updater::is_allowed_package_url( 'https://objects.githubusercontent.com/github-production-release-asset/1/x.zip' ), 'asset CDN host allowed' );

CaseRunner::group( 'Self-update versions' );

CaseRunner::case( 'numeric comparison, not string comparison' );
Assert::true( Updater::is_newer( '1.10.0', '1.9.0' ), '1.10.0 > 1.9.0' );
Assert::false( Updater::is_newer( '1.9.0', '1.10.0' ), '1.9.0 < 1.10.0' );
Assert::false( Updater::is_newer( '1.4.0', '1.4.0' ), 'equal is not newer' );

CaseRunner::case( 'tag prefixes ignored' );
Assert::true( Updater::is_newer( 'v0.2.0', '0.1.8' ), 'v tag compared' );
Assert::same( '2.0.0', Updater::normalize_version( ' V2.0.0 ' ), 'normalize trims and strips prefix' );

CaseRunner::case( 'empty remote never updates' );
Assert::false( Updater::is_newer( '', '0.1.8' ), 'empty version is not newer' );
Assert::false( Updater::is_newer( 'n/a', '0.1.8' ), 'junk version is not newer' );

CaseRunner::group( 'Self-update checksum gate' );

// filter_verify_download() is the gate that stops WordPress installing a
// package that is not byte-for-byte the one the manifest advertises. Drive it
// through its public hook with WordPress's HTTP layer short-circuited
// (pre_http_request) so the manifest and the package are served from fixtures.
CaseRunner::case( 'a package that does not match the manifest is refused' );
$gate_dir = sys_get_temp_dir() . '/githubsync-gate-' . getmypid();
if ( ! is_dir( $gate_dir ) ) {
	mkdir( $gate_dir, 0700, true );
}

$good_zip = $gate_dir . '/good.zip';
$bad_zip  = $gate_dir . '/bad.zip';
file_put_contents( $good_zip, 'the advertised package' );
file_put_contents( $bad_zip, 'something else entirely' );
$good_sha = (string) hash_file( 'sha256', $good_zip );
$bad_sha  = (string) hash_file( 'sha256', $bad_zip );

$base         = 'https://github.com/digal2025/githubsync-releases/releases/download/v9.9.9/';
$gate_url     = $base . 'githubsync.zip';
$manifest_url = 'https://github.com/digal2025/githubsync-releases/releases/latest/download/update.json';
$previous_url = (string) get_option( Updater::URL_OPTION );
$served_body  = $good_zip;
$served_sha   = $good_sha;

Updater::save_manifest_url( $manifest_url );

$serve = static function ( $pre, $args, $url ) use ( $gate_url, $manifest_url, &$served_body, &$served_sha ) {
	$body = '';

	if ( $manifest_url === $url ) {
		$body = (string) wp_json_encode(
			array(
				'version'      => '9.9.9',
				'download_url' => $gate_url,
				'sha256'       => 'sha256:' . $served_sha,
			)
		);
	} elseif ( $gate_url === $url && ! empty( $args['filename'] ) ) {
		// Write through a file handle and flush, so the bytes the updater
		// hashes are the bytes on disk.
		$bytes  = (string) file_get_contents( $served_body );
		$handle = fopen( $args['filename'], 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- test fixture.
		if ( false !== $handle ) {
			fwrite( $handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- test fixture.
			fflush( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- test fixture.
		}
	}

	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => $args['filename'] ?? null,
	);
};

$ours = array( 'plugin' => plugin_basename( GITHUBSYNC_FILE ) );

add_filter( 'pre_http_request', $serve, 10, 3 );

$served_body = $good_zip;
$served_sha  = $good_sha;
$accepted    = Updater::filter_verify_download( false, $gate_url, null, $ours );
Assert::false( is_wp_error( $accepted ), 'matching digest is accepted' );
Assert::true( is_string( $accepted ) && is_file( $accepted ), 'accepted package is on disk' );
Assert::same(
	$good_sha,
	hash_file( 'sha256', is_string( $accepted ) ? $accepted : '' ),
	'accepted package is the advertised bytes'
);
wp_delete_file( $accepted );

// The manifest advertises the good zip but GitHub serves something else.
$served_body = $bad_zip;
$served_sha  = $good_sha;
$rejected    = Updater::filter_verify_download( false, $gate_url, null, $ours );
Assert::true( is_wp_error( $rejected ), 'mismatched digest is refused' );
Assert::same(
	ErrorCodes::UPDATE_PACKAGE_INVALID,
	is_wp_error( $rejected ) ? $rejected->get_error_code() : '',
	'mismatch reports the package error'
);
Assert::true( '' !== $bad_sha && $bad_sha !== $good_sha, 'fixtures actually differ' );

CaseRunner::case( 'other plugins, foreign URLs and settled downloads are left alone' );
Assert::false( Updater::filter_verify_download( false, $gate_url, null, array( 'plugin' => 'akismet/akismet.php' ) ), 'not our plugin: untouched' );
Assert::false( Updater::filter_verify_download( false, 'https://example.org/other.zip', null, $ours ), 'foreign URL: untouched' );
Assert::same( '/tmp/already.zip', Updater::filter_verify_download( '/tmp/already.zip', $gate_url, null, $ours ), 'already downloaded: untouched' );

remove_filter( 'pre_http_request', $serve, 10 );
Updater::save_manifest_url( $previous_url );
wp_delete_file( $good_zip );
wp_delete_file( $bad_zip );
