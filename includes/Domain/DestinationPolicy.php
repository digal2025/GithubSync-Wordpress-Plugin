<?php
/**
 * Destination policy: the only places GitHubSync may write to.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Domain;

use GitHubSync\Security\InputValidator;
use GitHubSync\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a deployment type + slug to a canonical destination inside an
 * approved root, and rejects everything else.
 */
final class DestinationPolicy {

	public const TYPE_THEME  = 'theme';
	public const TYPE_PLUGIN = 'plugin';
	public const TYPE_STATIC = 'static';

	/**
	 * Supported deployment types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			self::TYPE_THEME  => __( 'WordPress theme', 'githubsync' ),
			self::TYPE_PLUGIN => __( 'WordPress plugin', 'githubsync' ),
			self::TYPE_STATIC => __( 'Static site', 'githubsync' ),
		);
	}

	/**
	 * Approved root directories, keyed by type.
	 *
	 * @return array<string,string>
	 */
	public static function roots() {
		return array(
			self::TYPE_THEME  => wp_normalize_path( WP_CONTENT_DIR . '/themes' ),
			self::TYPE_PLUGIN => wp_normalize_path( WP_CONTENT_DIR . '/plugins' ),
			self::TYPE_STATIC => wp_normalize_path( WP_CONTENT_DIR . '/uploads/githubsync-sites' ),
		);
	}

	/**
	 * Resolve and validate a destination.
	 *
	 * @param string $type Deployment type.
	 * @param string $slug Destination slug (directory name).
	 * @return array|\WP_Error {
	 *     @type string $path     Canonical absolute destination.
	 *     @type string $root     Canonical approved root.
	 *     @type string $relative Path relative to the root.
	 * }
	 */
	public static function resolve( $type, $slug ) {
		$roots = self::roots();

		if ( ! isset( $roots[ $type ] ) ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'Unknown deployment type.', 'githubsync' ) );
		}

		$slug = InputValidator::slug( $slug );
		if ( false === $slug ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'The destination name may only contain letters, numbers, dots, dashes and underscores.', 'githubsync' ) );
		}

		$root = $roots[ $type ];

		// Never allow deploying over GitHubSync itself.
		$own_dir = wp_normalize_path( GITHUBSYNC_DIR );
		$self    = wp_normalize_path( $root . '/' . $slug );

		if ( rtrim( $self, '/' ) === rtrim( $own_dir, '/' ) || 'githubsync' === strtolower( $slug ) ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'GitHubSync cannot deploy over its own installation directory.', 'githubsync' ) );
		}

		if ( self::TYPE_STATIC === $type ) {
			// Create the container lazily so canonicalisation can succeed.
			if ( ! is_dir( $root ) ) {
				if ( ! is_writable( dirname( $root ) ) ) {
					return new \WP_Error( 'DESTINATION_NOT_WRITABLE', __( 'The uploads directory is not writable.', 'githubsync' ) );
				}
				wp_mkdir_p( $root );
			}
		}

		$canonical_root = realpath( $root );
		if ( false === $canonical_root ) {
			return new \WP_Error( 'DESTINATION_NOT_WRITABLE', __( 'The approved destination root does not exist or is not readable.', 'githubsync' ) );
		}

		$canonical_root = wp_normalize_path( $canonical_root );
		$candidate      = PathGuard::canonicalize( $canonical_root . '/' . $slug );

		if ( false === $candidate ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'The destination path could not be resolved safely.', 'githubsync' ) );
		}

		if ( ! PathGuard::is_inside( $candidate, $canonical_root ) ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'The destination resolves outside the approved root.', 'githubsync' ) );
		}

		if ( rtrim( $candidate, '/' ) === rtrim( $canonical_root, '/' ) ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'The destination must be a subdirectory of the approved root.', 'githubsync' ) );
		}

		return array(
			'path'     => $candidate,
			'root'     => $canonical_root,
			'relative' => ltrim( substr( $candidate, strlen( $canonical_root ) ), '/' ),
		);
	}

	/**
	 * Whether a directory already contains files that GitHubSync did not
	 * create (collision / adoption check).
	 *
	 * @param string $path Absolute destination path.
	 * @return bool
	 */
	public static function has_foreign_content( $path ) {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		if ( file_exists( $path . '/.githubsync-owner.json' ) ) {
			return false;
		}

		$entries = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $entries ) ) {
			return false;
		}

		$entries = array_diff( $entries, array( '.', '..', 'index.php' ) );

		return count( $entries ) > 0;
	}
}
