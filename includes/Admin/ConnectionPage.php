<?php
/**
 * GitHub connection page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\Security\Capability;
use GitHubSync\Security\CredentialVault;

defined( 'ABSPATH' ) || exit;

/**
 * Connect via GitHub App (preferred) or PAT, and browse repositories.
 */
final class ConnectionPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$auth = new GitHubAuth();

		$browse  = isset( $_GET['browse'] ) && '1' === $_GET['browse']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$repos   = array();
		$has_next = false;
		$error    = '';

		if ( $browse && $auth->is_connected() ) {
			$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'githubsync_browse' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( ! $nonce_ok ) {
				$error = __( 'The repository list request expired. Try again.', 'githubsync' );
			} else {
				$cache_key = 'githubsync_repos_' . $paged . '_' . md5( $auth->summary() );
				$cached    = get_transient( $cache_key );

				if ( is_array( $cached ) ) {
					$repos    = $cached['items'];
					$has_next = (bool) $cached['has_next'];
				} else {
					$result = ( new GitHubApiClient( $auth ) )->repositories( $paged, 50 );

					if ( is_wp_error( $result ) ) {
						$error = $result->get_error_message();
					} else {
						$repos    = $result['items'];
						$has_next = $result['has_next'];
						set_transient( $cache_key, array( 'items' => $repos, 'has_next' => $has_next ), 5 * MINUTE_IN_SECONDS );
					}
				}
			}
		}

		$data = array(
			'connected'      => $auth->is_connected(),
			'connection'     => $auth->connection(),
			'summary'        => $auth->summary(),
			'encryption_ok'  => CredentialVault::is_configured(),
			'browse'         => $browse,
			'paged'          => $paged,
			'repos'          => $repos,
			'has_next'       => $has_next,
			'error'          => $error,
			'browse_url'     => wp_nonce_url( admin_url( 'admin.php?page=githubsync-connection&browse=1' ), 'githubsync_browse' ),
		);

		include GITHUBSYNC_DIR . 'templates/connection.php';
	}
}
