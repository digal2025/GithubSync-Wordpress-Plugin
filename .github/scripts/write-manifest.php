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
 *   GITHUB_REPOSITORY  owner/name (set by Actions)
 *   GITHUB_REF_NAME    tag name, e.g. v0.1.9
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

$sha = hash_file( 'sha256', $zip );

if ( ! is_string( $sha ) ) {
	fwrite( STDERR, "Could not hash {$zip}\n" );
	exit( 1 );
}

$manifest = array(
	'slug'         => 'githubsync',
	'version'      => $version,
	'download_url' => sprintf( 'https://github.com/%s/releases/download/%s/githubsync.zip', $repo, $tag ),
	'sha256'       => 'sha256:' . $sha,
	'requires'     => '7.4',
	'tested'       => '6.8',
	'homepage'     => sprintf( 'https://github.com/%s', $repo ),
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
