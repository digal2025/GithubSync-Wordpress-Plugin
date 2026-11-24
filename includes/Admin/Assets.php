<?php
/**
 * Admin assets.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads CSS/JS only on GitHubSync screens.
 */
final class Assets {

	/**
	 * Enqueue.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style(
			'githubsync-admin',
			GITHUBSYNC_URL . 'assets/css/admin.css',
			array(),
			GITHUBSYNC_VERSION
		);

		wp_enqueue_script(
			'githubsync-admin',
			GITHUBSYNC_URL . 'assets/js/admin.js',
			array(),
			GITHUBSYNC_VERSION,
			true
		);

		unset( $hook );
	}

	/**
	 * Whether the current request is a GitHubSync admin screen.
	 *
	 * @return bool
	 */
	private static function is_plugin_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 0 === strpos( $page, 'githubsync' );
	}
}
