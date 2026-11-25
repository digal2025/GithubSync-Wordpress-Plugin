<?php
/**
 * GitHub connection page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\GitHub\AppSetup;
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

		// GitHub redirects the manifest and install callbacks to this page.
		$callback = Actions::page_callback();

		$auth = new GitHubAuth();

		$browse   = isset( $_GET['browse'] ) && '1' === $_GET['browse']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$repos    = array();
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
						set_transient(
							$cache_key,
							array(
								'items'    => $repos,
								'has_next' => $has_next,
							),
							5 * MINUTE_IN_SECONDS
						);
					}
				}
			}
		}

		$data = array(
			'connected'     => $auth->is_connected(),
			'connection'    => $auth->connection(),
			'summary'       => $auth->summary(),
			'encryption_ok' => CredentialVault::is_configured(),
			'browse'        => $browse,
			'paged'         => $paged,
			'repos'         => $repos,
			'has_next'      => $has_next,
			'error'         => $error,
			'browse_url'    => wp_nonce_url( admin_url( 'admin.php?page=githubsync-connection&browse=1' ), 'githubsync_browse' ),
			'manifest'      => self::manifest_data( $auth, $callback ),
		);

		include GITHUBSYNC_DIR . 'templates/connection.php';
	}

	/**
	 * One-click App setup state for the template.
	 *
	 * The installation list needs a live GitHub call with an App JWT, so it is
	 * only fetched when an App is stored and not yet installed.
	 *
	 * @param GitHubAuth $auth     Auth instance.
	 * @param array|null $callback Outcome of a GitHub callback on this page.
	 * @return array
	 */
	private static function manifest_data( GitHubAuth $auth, $callback = null ) {
		$setup   = new AppSetup();
		$meta    = $auth->app_meta();
		$pending = isset( $_GET['gs_state'] ) ? sanitize_text_field( wp_unslash( $_GET['gs_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = '' !== $pending ? $setup->form( $pending ) : null;

		$installations = array();
		$install_error = '';

		if ( $auth->has_app() && ! CredentialVault::is_configured() ) {
			$install_error = __( 'Secure encryption is not configured, so the App key cannot be read to list installations.', 'githubsync' );
		} elseif ( $auth->has_app() ) {
			$list = $auth->app_installations();

			if ( is_wp_error( $list ) ) {
				$install_error = $list->get_error_message();
			} else {
				$installations = $list;
			}
		}

		$app_id = isset( $meta['app_id'] ) ? (string) $meta['app_id'] : '';

		return array(
			'supported'     => $setup->is_supported(),
			'app_name'      => $setup->app_name(),
			'form'          => $form,
			'callback'      => is_array( $callback ) ? $callback : null,
			'has_app'       => $auth->has_app(),
			'app_meta'      => $meta,
			'install_url'   => AppSetup::install_url( isset( $meta['slug'] ) ? $meta['slug'] : '' ),
			'installations' => $installations,
			'install_error' => $install_error,
			'key_ready'     => '' !== $app_id && $setup->key_download_available( $app_id ),
			'current'       => $auth->is_connected() && 'app' === $auth->connection()['mode'] ? (string) $auth->connection()['installation_id'] : '',
		);
	}
}
