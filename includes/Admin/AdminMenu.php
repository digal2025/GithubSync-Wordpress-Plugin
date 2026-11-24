<?php
/**
 * Admin menu and notices.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Domain\DeploymentStatus;
use GitHubSync\Security\Capability;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the GitHubSync admin menu and renders queued notices.
 */
final class AdminMenu {

	/**
	 * Register pages.
	 *
	 * @return void
	 */
	public static function register() {
		$cap = Capability::required();

		add_menu_page(
			__( 'GitHubSync', 'githubsync' ),
			__( 'GitHubSync', 'githubsync' ),
			$cap,
			'githubsync',
			array( DashboardPage::class, 'render' ),
			'dashicons-cloud',
			58
		);

		add_submenu_page( 'githubsync', __( 'Dashboard', 'githubsync' ), __( 'Dashboard', 'githubsync' ), $cap, 'githubsync', array( DashboardPage::class, 'render' ) );
		add_submenu_page( 'githubsync', __( 'Deployments', 'githubsync' ), __( 'Deployments', 'githubsync' ), $cap, 'githubsync-deployments', array( DeploymentListPage::class, 'render' ) );
		add_submenu_page( 'githubsync', __( 'Add Deployment', 'githubsync' ), __( 'Add Deployment', 'githubsync' ), $cap, 'githubsync-deployment-new', array( DeploymentFormPage::class, 'render' ) );
		add_submenu_page( 'githubsync', __( 'History', 'githubsync' ), __( 'History', 'githubsync' ), $cap, 'githubsync-history', array( HistoryPage::class, 'render' ) );
		add_submenu_page( 'githubsync', __( 'GitHub Connection', 'githubsync' ), __( 'GitHub Connection', 'githubsync' ), $cap, 'githubsync-connection', array( ConnectionPage::class, 'render' ) );
		add_submenu_page( 'githubsync', __( 'Settings', 'githubsync' ), __( 'Settings', 'githubsync' ), $cap, 'githubsync-settings', array( SettingsPage::class, 'render' ) );
		add_submenu_page( 'githubsync', __( 'Tools', 'githubsync' ), __( 'Tools', 'githubsync' ), $cap, 'githubsync-tools', array( ToolsPage::class, 'render' ) );

		// Hidden pages.
		add_submenu_page( '', __( 'Edit Deployment', 'githubsync' ), __( 'Edit Deployment', 'githubsync' ), $cap, 'githubsync-deployment-edit', array( DeploymentFormPage::class, 'render' ) );
		add_submenu_page( '', __( 'Deployment', 'githubsync' ), __( 'Deployment', 'githubsync' ), $cap, 'githubsync-deployment', array( DeploymentDetailPage::class, 'render' ) );
	}

	/**
	 * Render notices: queued ones plus redirect messages.
	 *
	 * @return void
	 */
	public static function render_notices() {
		if ( ! Capability::user_can_manage() ) {
			return;
		}

		foreach ( Helpers::take_notices() as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( self::notice_class( $notice['type'] ) ),
				esc_html( $notice['message'] )
			);
		}

		if ( isset( $_GET['gs_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = sanitize_key( wp_unslash( $_GET['gs_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = isset( $_GET['gs_type'] ) ? sanitize_key( wp_unslash( $_GET['gs_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$message = self::message( $code );

			if ( '' !== $message ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( self::notice_class( $type ) ),
					esc_html( $message )
				);
			}
		}
	}

	/**
	 * Map a redirect message code to a translated string.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = array(
			'saved'               => __( 'Deployment configuration saved.', 'githubsync' ),
			'deleted'             => __( 'Deployment configuration deleted. Deployed files and backups were kept.', 'githubsync' ),
			'queued'              => __( 'Deployment queued. It will run in the background.', 'githubsync' ),
			'retried'             => __( 'Job requeued.', 'githubsync' ),
			'rolledback'          => __( 'Rollback queued.', 'githubsync' ),
			'paused'              => __( 'Automatic deployment paused.', 'githubsync' ),
			'resumed'             => __( 'Automatic deployment resumed.', 'githubsync' ),
			'secret_regenerated'  => __( 'A new webhook secret was generated. Update it in GitHub.', 'githubsync' ),
			'connected'           => __( 'GitHub connected.', 'githubsync' ),
			'disconnected'        => __( 'GitHub disconnected and stored credentials removed.', 'githubsync' ),
			'settings_saved'      => __( 'Settings saved.', 'githubsync' ),
			'key_created'         => __( 'Encryption key file created outside the web root.', 'githubsync' ),
			'logs_cleared'        => __( 'Deployment logs cleared.', 'githubsync' ),
			'queue_ran'           => __( 'Queue processed.', 'githubsync' ),
			'backups_pruned'      => __( 'Old backups pruned.', 'githubsync' ),
			'checklist_closed'    => __( 'Setup checklist closed. You can show it again at any time from the dashboard.', 'githubsync' ),
			'checklist_incomplete' => __( 'The setup checklist cannot be closed until every step is complete.', 'githubsync' ),
			'checklist_restored'  => __( 'Setup checklist reopened.', 'githubsync' ),
			'error'               => __( 'The action could not be completed.', 'githubsync' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : '';
	}

	/**
	 * WordPress notice class.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function notice_class( $type ) {
		$map = array(
			'error'   => 'error',
			'warning' => 'warning',
			'success' => 'success',
			'info'    => 'info',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : 'info';
	}

	/**
	 * Status badge markup.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function badge( $status ) {
		return sprintf(
			'<span class="gs-badge gs-badge--%1$s">%2$s</span>',
			esc_attr( DeploymentStatus::badge_class( $status ) ),
			esc_html( DeploymentStatus::label( $status ) )
		);
	}

	/**
	 * Render a page header.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Optional subtitle.
	 * @return void
	 */
	public static function page_header( $title, $subtitle = '' ) {
		echo '<div class="wrap gs-wrap">';
		echo '<h1 class="gs-title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $subtitle ) {
			echo '<p class="gs-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
	}

	/**
	 * Close the page wrapper.
	 *
	 * @return void
	 */
	public static function page_footer() {
		echo '</div>';
	}
}
