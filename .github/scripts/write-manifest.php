<?php
/**
 * Write the self-update manifest for a release.
 *
 * Usage: php write-manifest.php <zip> <output.json>
 *
 * The manifest is what GitHubSync reads from
 * /releases/latest/download/update.json. It is plain JSON with only the fields
 * Updater::validate_manifest() understands.
 *
 * Reads from the environment:
 *   GITHUB_REPOSITORY     owner/name of the PUBLIC host (e.g. owner/githubsync-releases)
 *   GITHUB_REF_NAME       tag name, e.g. v0.1.9
 *   GITHUBSYNC_SHA256     optional "sha256:<hex>" digest GitHub reported for the
 *                         uploaded asset; the local file is hashed otherwise
 *   GITHUBSYNC_SOURCE_REPO optional owner/name of the private source repository
 *   GITHUBSYNC_SOURCE_TAG  optional source tag (defaults to GITHUB_REF_NAME)
 *
 * @package GitHubSync
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php write-manifest.php <zip> <output.json>\n" );
	exit( 2 );
}

list( , $zip, $output ) = $argv;

if ( ! is_file( $zip ) ) {
	fwrite( STDERR, "Zip not found: {$zip}\n" );
	exit( 1 );
}

$repo = (string) getenv( 'GITHUB_REPOSITORY' );
$tag  = (string) getenv( 'GITHUB_REF_NAME' );

if ( '' === $repo || '' === $tag ) {
	fwrite( STDERR, "GITHUB_REPOSITORY and GITHUB_REF_NAME must be set.\n" );
	exit( 1 );
}

// The plugin header is the single source of truth for the version.
$header  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/githubsync.php' );
$version = '';

if ( preg_match( '/^ \* Version:\s*(\S+)/m', $header, $matches ) ) {
	$version = $matches[1];
}

if ( '' === $version || $version !== ltrim( $tag, 'v' ) ) {
	fwrite( STDERR, "Version mismatch: header '{$version}', tag '{$tag}'.\n" );
	exit( 1 );
}

$source_repo = (string) getenv( 'GITHUBSYNC_SOURCE_REPO' );
$source_tag  = (string) getenv( 'GITHUBSYNC_SOURCE_TAG' );

if ( '' === $source_tag ) {
	$source_tag = $tag;
}

$homepage = '' !== $source_repo ? sprintf( 'https://github.com/%s', $source_repo ) : sprintf( 'https://github.com/%s', $repo );

$sha = trim( (string) getenv( 'GITHUBSYNC_SHA256' ) );

if ( '' === $sha ) {
	$sha = hash_file( 'sha256', $zip );
}

if ( ! is_string( $sha ) || '' === $sha ) {
	fwrite( STDERR, "Could not hash {$zip}\n" );
	exit( 1 );
}

if ( 0 === stripos( $sha, 'sha256:' ) ) {
	$sha = substr( $sha, 7 );
}

if ( ! preg_match( '/^[a-f0-9]{64}$/i', $sha ) ) {
	fwrite( STDERR, "Invalid SHA-256 digest: {$sha}\n" );
	exit( 1 );
}

$manifest = array(
	'slug'         => 'githubsync',
	'version'      => $version,
	'download_url' => sprintf( 'https://github.com/%s/releases/download/%s/githubsync.zip', $repo, $tag ),
	'sha256'       => 'sha256:' . $sha,
	'requires'     => readme_field( 'Requires at least', '6.2' ),
	'requires_wp'  => readme_field( 'Requires at least', '6.2' ),
	'requires_php' => readme_field( 'Requires PHP', '7.4' ),
	'tested'       => readme_field( 'Tested up to', '6.8' ),
	'homepage'     => $homepage,
	'source'       => '' !== $source_repo ? sprintf( 'https://github.com/%s/tree/%s', $source_repo, $source_tag ) : '',
	'released_at'  => gmdate( 'Y-m-d' ),
	'changelog'    => changelog_for( $version ),
);

$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

if ( ! is_string( $json ) ) {
	fwrite( STDERR, "Could not encode the manifest.\n" );
	exit( 1 );
}

if ( false === file_put_contents( $output, $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	fwrite( STDERR, "Could not write {$output}\n" );
	exit( 1 );
}

echo "Wrote {$output} (githubsync {$version}, sha256 {$sha})\n";

/**
 * Read a "Label: value" line from readme.txt.
 *
 * @param string $label    Field label, e.g. "Tested up to".
 * @param string $fallback Value to use when the field is missing.
 * @return string
 */
function readme_field( $label, $fallback ) {
	$file = dirname( __DIR__, 2 ) . '/readme.txt';

	if ( ! is_file( $file ) ) {
		return $fallback;
	}

	$readme = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(\S+)/mi', $readme, $matches ) ) {
		return $matches[1];
	}

	return $fallback;
}

/**
 * Pull one "## [x.y.z]" block out of CHANGELOG.md.
 *
 * @param string $version Version to look for.
 * @return string Markdown, or '' when the version has no heading.
 */
function changelog_for( $version ) {
	$file = dirname( __DIR__, 2 ) . '/CHANGELOG.md';

	if ( ! is_file( $file ) ) {
		return '';
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file

	if ( ! is_array( $lines ) ) {
		return '';
	}

	$collect = false;
	$body    = array();

	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, '## [' ) ) {
			if ( $collect ) {
				break;
			}

			$collect = 0 === strpos( $line, '## [' . $version . ']' );

			continue;
		}

		if ( $collect ) {
			$body[] = $line;
		}
	}

	return trim( implode( "\n", $body ) );
}
