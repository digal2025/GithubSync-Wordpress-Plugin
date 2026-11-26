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

CaseRunner::case( 'public release feed is the default' );
Assert::same(
	'https://github.com/digal2025/githubsync-releases/releases/latest/download/update.json',
	Updater::DEFAULT_URL,
	'installed sites know the public feed without setup'
);

CaseRunner::case( 'WordPress and PHP requirements are separate' );
$requirements = Updater::validate_manifest(
	array_merge(
		$valid,
		array(
			'requires'     => '6.2',
			'requires_wp'  => '6.3',
			'requires_php' => '8.0',
		)
	)
);
Assert::false( is_wp_error( $requirements ), 'manifest with requirements accepted' );
Assert::same( '6.3', $requirements['requires_wp'], 'WordPress requirement preserved' );
Assert::same( '8.0', $requirements['requires_php'], 'PHP requirement preserved' );

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

CaseRunner::group( 'Self-update notice' );

CaseRunner::case( 'a newer release appears on the Plugins update list' );
$notice_url    = 'https://github.com/digal2025/githubsync-releases/releases/latest/download/update.json';
$notice_prev   = (string) get_option( Updater::URL_OPTION );
$notice_zip    = 'https://github.com/digal2025/githubsync-releases/releases/download/v99.0.0/githubsync.zip';
Updater::save_manifest_url( $notice_url );

$notice_serve = static function ( $pre, $args, $url ) use ( $notice_url, $notice_zip ) {
	if ( $notice_url !== $url ) {
		return $pre;
	}

	return array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode(
			array(
				'version'      => '99.0.0',
				'download_url' => $notice_zip,
				'sha256'       => 'sha256:' . str_repeat( 'cd', 32 ),
				'requires'     => '6.2',
				'requires_wp'  => '6.3',
				'requires_php' => '8.0',
				'tested'       => '7.1',
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};

add_filter( 'pre_http_request', $notice_serve, 10, 3 );
Updater::flush_cache();
$basename  = plugin_basename( GITHUBSYNC_FILE );
$transient = (object) array(
	'checked'   => array( 'akismet/akismet.php' => '5.0' ),
	'response'  => array(),
	'no_update' => array( $basename => (object) array( 'new_version' => GITHUBSYNC_VERSION ) ),
);
$updated   = Updater::filter_update_transient( $transient );
Assert::true( isset( $updated->response[ $basename ] ), 'newer version is offered' );
Assert::same( '99.0.0', isset( $updated->response[ $basename ] ) ? $updated->response[ $basename ]->new_version : '', 'update uses the manifest version' );
Assert::same( $notice_zip, isset( $updated->response[ $basename ] ) ? $updated->response[ $basename ]->package : '', 'update uses the manifest package' );
Assert::false( isset( $updated->no_update[ $basename ] ), 'stale no_update entry is removed' );
Assert::same( '6.3', isset( $updated->response[ $basename ] ) ? $updated->response[ $basename ]->requires : '', 'WordPress requirement is correct' );
Assert::same( '8.0', isset( $updated->response[ $basename ] ) ? $updated->response[ $basename ]->requires_php : '', 'PHP requirement is correct' );
remove_filter( 'pre_http_request', $notice_serve, 10 );
Updater::save_manifest_url( $notice_prev );

CaseRunner::group( 'Self-update details dialog' );

// The Plugins screen asks for plugin_information whether or not an update is
// waiting, so the filter must answer for an up-to-date install too. Otherwise
// WordPress.org replies "Plugin not found".
CaseRunner::case( 'an up-to-date plugin still has details' );
$info_url      = 'https://github.com/digal2025/githubsync-releases/releases/latest/download/update.json';
$info_previous = (string) get_option( Updater::URL_OPTION );

Updater::save_manifest_url( $info_url );

$info_serve = static function ( $pre, $args, $url ) use ( $info_url ) {
	if ( $info_url !== $url ) {
		return $pre;
	}

	return array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode(
			array(
				'version'      => GITHUBSYNC_VERSION,
				'download_url' => 'https://github.com/digal2025/githubsync-releases/releases/download/v' . GITHUBSYNC_VERSION . '/githubsync.zip',
				'sha256'       => 'sha256:' . str_repeat( 'ab', 32 ),
				'changelog'    => "### Fixed\n\n- Something.",
				'tested'       => '7.1',
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};

add_filter( 'pre_http_request', $info_serve, 10, 3 );

$info = Updater::filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'githubsync' ) );
Assert::true( is_object( $info ), 'details returned while up to date' );
Assert::same( GITHUBSYNC_VERSION, is_object( $info ) ? $info->version : '', 'details describe the latest release' );
Assert::true( is_object( $info ) && isset( $info->sections['changelog'] ), 'release notes are included' );

CaseRunner::case( 'other plugins and other actions are left alone' );
Assert::false( Updater::filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ), 'foreign slug: untouched' );
Assert::false( Updater::filter_plugin_information( false, 'hot_tags', (object) array( 'slug' => 'githubsync' ) ), 'other action: untouched' );

remove_filter( 'pre_http_request', $info_serve, 10 );
Updater::save_manifest_url( $info_previous );
