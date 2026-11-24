<?php
/**
 * Central path safety service.
 *
 * Every filesystem decision in the plugin must go through this class.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Normalisation and containment checks for untrusted paths.
 */
final class PathGuard {

	/**
	 * Normalise a relative path: forward slashes, collapsed separators,
	 * no leading "./", no trailing slash.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = preg_replace( '#^(\./)+#', '', $path );
		return rtrim( (string) $path, '/' );
	}

	/**
	 * Whether a relative path is safe to join onto a root.
	 *
	 * Rejects: empty, null bytes, absolute paths, drive letters, UNC paths,
	 * traversal segments and "." segments.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public static function is_safe_relative( $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return false;
		}

		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$normalized = self::normalize( $path );

		if ( '' === $normalized ) {
			return false;
		}

		// Absolute POSIX path or UNC share.
		if ( '/' === $normalized[0] ) {
			return false;
		}

		// Windows drive letter (C:) or scheme-like prefix.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $normalized ) ) {
			return false;
		}

		foreach ( explode( '/', $normalized ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a path is contained by a root directory (string comparison,
	 * normalised). Does not touch the filesystem.
	 *
	 * @param string $candidate Absolute candidate path.
	 * @param string $root      Absolute root path.
	 * @return bool
	 */
	public static function is_inside( $candidate, $root ) {
		$candidate = rtrim( self::normalize( $candidate ), '/' );
		$root      = rtrim( self::normalize( $root ), '/' );

		if ( '' === $root || '' === $candidate ) {
			return false;
		}

		if ( $candidate === $root ) {
			return true;
		}

		return 0 === strpos( $candidate, $root . '/' );
	}

	/**
	 * Resolve the canonical path of an existing directory, or of the nearest
	 * existing ancestor with the remaining segments appended.
	 *
	 * @param string $path Absolute path that may not exist yet.
	 * @return string|false Canonical path, or false when a parent is a symlink
	 *                      that escapes or the path cannot be resolved.
	 */
	public static function canonicalize( $path ) {
		$path      = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		$remainder = array();
		$probe     = $path;

		while ( '' !== $probe && ! file_exists( $probe ) ) {
			$remainder[] = basename( $probe );
			$parent      = dirname( $probe );
			if ( $parent === $probe ) {
				return false;
			}
			$probe = $parent;
		}

		if ( '' === $probe || ! file_exists( $probe ) ) {
			return false;
		}

		$real = realpath( $probe );
		if ( false === $real ) {
			return false;
		}

		$real = str_replace( '\\', '/', $real );
		$tail = array_reverse( $remainder );

		foreach ( $tail as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
			$real .= '/' . $segment;
		}

		return $real;
	}

	/**
	 * Join a relative path onto a root, rejecting unsafe input.
	 *
	 * @param string $root     Absolute root.
	 * @param string $relative Relative path.
	 * @return string|false
	 */
	public static function join( $root, $relative ) {
		$relative = self::normalize( $relative );

		if ( ! self::is_safe_relative( $relative ) ) {
			return false;
		}

		$joined = rtrim( str_replace( '\\', '/', (string) $root ), '/' ) . '/' . $relative;

		if ( ! self::is_inside( $joined, $root ) ) {
			return false;
		}

		return $joined;
	}

	/**
	 * Reject paths that resolve outside a root through existing symlinks.
	 *
	 * @param string $candidate Absolute path (may not exist yet).
	 * @param string $root      Absolute root that must contain it.
	 * @return bool
	 */
	public static function resolves_inside( $candidate, $root ) {
		$canonical_candidate = self::canonicalize( $candidate );
		$canonical_root      = self::canonicalize( $root );

		if ( false === $canonical_candidate || false === $canonical_root ) {
			return false;
		}

		return self::is_inside( $canonical_candidate, $canonical_root );
	}
}
