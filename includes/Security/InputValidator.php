<?php
/**
 * Input validation for deployment configuration fields.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitises and validates administrator-supplied configuration values.
 */
final class InputValidator {

	/**
	 * Validate a repository full name (owner/repo).
	 *
	 * @param string $value Raw value.
	 * @return string|false
	 */
	public static function repository_full_name( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $value ) ) {
			return false;
		}
		return $value;
	}

	/**
	 * Validate a branch name. Git ref rules, simplified but strict.
	 *
	 * @param string $value Raw value.
	 * @return string|false
	 */
	public static function branch( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || strlen( $value ) > 255 ) {
			return false;
		}
		if ( preg_match( '#[\x00-\x20~^:?*\[\\\\]#', $value ) ) {
			return false;
		}
		if ( false !== strpos( $value, '..' ) || false !== strpos( $value, '@{' ) ) {
			return false;
		}
		if ( '/' === $value[0] || '/' === substr( $value, -1 ) || false !== strpos( $value, '//' ) ) {
			return false;
		}
		if ( '.lock' === substr( $value, -5 ) ) {
			return false;
		}

		return $value;
	}

	/**
	 * Validate a source path inside a repository (may be empty = root).
	 *
	 * @param string $value Raw value.
	 * @return string|false Normalised path ('' for root).
	 */
	public static function source_path( $value ) {
		$value = PathGuard::normalize( trim( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( ! PathGuard::is_safe_relative( $value ) ) {
			return false;
		}

		return $value;
	}

	/**
	 * Validate a destination slug.
	 *
	 * @param string $value Raw value.
	 * @return string|false
	 */
	public static function slug( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value ) ) {
			return false;
		}
		if ( false !== strpos( $value, '..' ) ) {
			return false;
		}
		return $value;
	}

	/**
	 * Validate a commit SHA (full or abbreviated).
	 *
	 * @param string $value Raw value.
	 * @return string|false
	 */
	public static function sha( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^[a-f0-9]{7,64}$/i', $value ) ) {
			return false;
		}
		return strtolower( $value );
	}

	/**
	 * Parse a newline-separated pattern list into a clean array.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	public static function pattern_list( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( strlen( $line ) > 255 ) {
				continue;
			}
			$out[] = $line;
		}

		return array_values( array_unique( $out ) );
	}
}
