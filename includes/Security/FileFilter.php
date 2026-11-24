<?php
/**
 * Include/exclude and protected-file policy.
 *
 * Pattern syntax (documented in docs/ARCHITECTURE.md):
 *   *    matches any characters except "/"
 *   **   matches any characters including "/"
 *   ?    matches a single character except "/"
 *   A pattern ending in "/" matches the directory and everything below it.
 *   A pattern containing no "/" is also matched against the basename.
 * All matching is case-sensitive and anchored to the full relative path.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Applies administrator exclusions plus non-overridable protected patterns.
 */
final class FileFilter {

	/**
	 * Patterns that can never be deployed, overwritten or deleted. A
	 * repository cannot override these.
	 *
	 * @var string[]
	 */
	private const PROTECTED = array(
		'.git/',
		'.github/',
		'.gitignore',
		'.gitattributes',
		'.gitmodules',
		'.env',
		'.env.*',
		'**/.env',
		'**/.env.*',
		'wp-config.php',
		'**/wp-config.php',
		'.htpasswd',
		'**/.htpasswd',
		'**/*.pem',
		'**/*.key',
		'**/*.p12',
		'**/*.pfx',
		'**/id_rsa',
		'**/id_rsa.*',
		'**/id_ed25519',
		'**/.ssh/',
		'**/.DS_Store',
		'**/Thumbs.db',
		'**/desktop.ini',
	);

	/**
	 * Administrator-defined exclude patterns.
	 *
	 * @var string[]
	 */
	private $excludes = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $excludes Additional exclude patterns.
	 */
	public function __construct( array $excludes = array() ) {
		foreach ( $excludes as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern ) {
				$this->excludes[] = $pattern;
			}
		}
	}

	/**
	 * Build from a stored newline-separated pattern list.
	 *
	 * @param string $raw Newline separated patterns.
	 * @return self
	 */
	public static function from_text( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		return new self( is_array( $lines ) ? $lines : array() );
	}

	/**
	 * Protected patterns.
	 *
	 * @return string[]
	 */
	public static function protected_patterns() {
		return self::PROTECTED;
	}

	/**
	 * Whether a relative path is protected (cannot be written or deleted).
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public function is_protected( $relative ) {
		$relative = PathGuard::normalize( $relative );
		foreach ( self::PROTECTED as $pattern ) {
			if ( self::matches( $pattern, $relative ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a relative path should be skipped during deployment.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public function is_excluded( $relative ) {
		$relative = PathGuard::normalize( $relative );

		if ( $this->is_protected( $relative ) ) {
			return true;
		}

		foreach ( $this->excludes as $pattern ) {
			if ( self::matches( $pattern, $relative ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Test a single glob pattern against a path.
	 *
	 * @param string $pattern Glob pattern.
	 * @param string $path    Relative path.
	 * @return bool
	 */
	public static function matches( $pattern, $path ) {
		$pattern = trim( (string) $pattern );
		$path    = PathGuard::normalize( $path );

		if ( '' === $pattern || '' === $path ) {
			return false;
		}

		$regex = self::glob_to_regex( $pattern );

		if ( preg_match( $regex, $path ) ) {
			return true;
		}

		// Basename matching for slash-less patterns (gitignore-like).
		if ( false === strpos( $pattern, '/' ) ) {
			$basename = basename( $path );
			if ( preg_match( $regex, $basename ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a restricted glob to an anchored PCRE pattern.
	 *
	 * @param string $pattern Glob.
	 * @return string
	 */
	public static function glob_to_regex( $pattern ) {
		$pattern  = (string) $pattern;
		$dir_only = false;

		if ( '' !== $pattern && '/' === substr( $pattern, -1 ) ) {
			$dir_only = true;
			$pattern  = rtrim( $pattern, '/' );
		}

		$length = strlen( $pattern );
		$regex  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( '*' === $char ) {
				if ( $i + 1 < $length && '*' === $pattern[ $i + 1 ] ) {
					$i++;
					$regex .= '.*';
					// "**/" should also match zero directories.
					if ( $i + 1 < $length && '/' === $pattern[ $i + 1 ] ) {
						$i++;
						$regex .= '/?';
					}
				} else {
					$regex .= '[^/]*';
				}
				continue;
			}

			if ( '?' === $char ) {
				$regex .= '[^/]';
				continue;
			}

			$regex .= preg_quote( $char, '#' );
		}

		if ( $dir_only ) {
			$regex .= '(/.*)?';
		}

		return '#^' . $regex . '$#';
	}
}
