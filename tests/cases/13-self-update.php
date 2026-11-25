<?php
/**
 * Self-update: manifest validation and version comparison.
 *
 * @package GitHubSync
 */

use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;
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
