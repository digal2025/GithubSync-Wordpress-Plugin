<?php
/**
 * Capability helper.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the capability required by every privileged
 * action. Filterable so a site can delegate to a custom role.
 */
final class Capability {

	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public static function required() {
		return (string) apply_filters( 'githubsync_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may manage GitHubSync.
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		return current_user_can( self::required() );
	}

	/**
	 * Hard-stop helper for admin pages.
	 *
	 * @return void
	 */
	public static function assert() {
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage GitHubSync.', 'githubsync' ), 403 );
		}
	}
}
