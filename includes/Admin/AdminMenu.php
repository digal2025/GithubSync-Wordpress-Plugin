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
			'saved'                => __( 'Deployment configuration saved.', 'githubsync' ),
			'deleted'              => __( 'Deployment configuration deleted. Deployed files and backups were kept.', 'githubsync' ),
			'queued'               => __( 'Deployment queued. It will run in the background.', 'githubsync' ),
			'retried'              => __( 'Job requeued.', 'githubsync' ),
			'rolledback'           => __( 'Rollback queued.', 'githubsync' ),
			'paused'               => __( 'Automatic deployment paused.', 'githubsync' ),
			'resumed'              => __( 'Automatic deployment resumed.', 'githubsync' ),
			'secret_regenerated'   => __( 'A new webhook secret was generated. Update it in GitHub.', 'githubsync' ),
			'connected'            => __( 'GitHub connected.', 'githubsync' ),
			'app_manifest_ready'   => __( 'App manifest ready. Continue to GitHub to approve it.', 'githubsync' ),
			'app_created'          => __( 'GitHub App created and its key stored. Now install it on the account you want to deploy from.', 'githubsync' ),
			'app_installed'        => __( 'GitHub App installed. Repositories can now be browsed.', 'githubsync' ),
			'app_setup_cancelled'  => __( 'GitHub App setup did not finish. Nothing was changed.', 'githubsync' ),
			'disconnected'         => __( 'GitHub disconnected and stored credentials removed.', 'githubsync' ),
			'settings_saved'       => __( 'Settings saved.', 'githubsync' ),
			'key_created'          => __( 'Encryption key file created outside the web root.', 'githubsync' ),
			'logs_cleared'         => __( 'Deployment logs cleared.', 'githubsync' ),
			'queue_ran'            => __( 'Queue processed.', 'githubsync' ),
			'backups_pruned'       => __( 'Old backups pruned.', 'githubsync' ),
			'checklist_closed'     => __( 'Setup checklist closed. You can show it again at any time from the dashboard.', 'githubsync' ),
			'checklist_incomplete' => __( 'The setup checklist cannot be closed until every step is complete.', 'githubsync' ),
			'checklist_restored'   => __( 'Setup checklist reopened.', 'githubsync' ),
			'error'                => __( 'The action could not be completed.', 'githubsync' ),
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
	 * @param string $actions  Optional escaped action markup (buttons/links).
	 * @return void
	 */
	public static function page_head( $title, $subtitle = '', $actions = '' ) {
		echo '<div class="gs-page-head">';
		echo '<div class="gs-page-head__text">';
		echo '<h1 class="gs-title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $subtitle ) {
			echo '<p class="gs-subtitle">' . esc_html( $subtitle ) . '</p>';
		}

		echo '</div>';

		if ( '' !== $actions ) {
			// Action markup is built and escaped by the caller.
			echo '<div class="gs-page-head__actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
	}

	/**
	 * Render a back link to another admin page.
	 *
	 * @param string $page  Admin page slug.
	 * @param string $label Link label.
	 * @param array  $args  Extra query arguments.
	 * @return void
	 */
	public static function back_link( $page, $label, array $args = array() ) {
		$url = add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );

		printf(
			'<p class="gs-backlink"><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Render an empty state.
	 *
	 * @param string $title   Headline.
	 * @param string $text    Supporting copy.
	 * @param string $actions Optional escaped action markup.
	 * @return void
	 */
	public static function empty_state( $title, $text = '', $actions = '' ) {
		echo '<div class="gs-empty">';
		echo '<p class="gs-empty__title">' . esc_html( $title ) . '</p>';

		if ( '' !== $text ) {
			echo '<p class="gs-empty__text">' . esc_html( $text ) . '</p>';
		}

		if ( '' !== $actions ) {
			// Action markup is built and escaped by the caller.
			echo '<p class="gs-empty__actions">' . $actions . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
	}

	/**
	 * Render an inline notice.
	 *
	 * @param string $type    success|warning|error|info.
	 * @param string $message Message. May contain inline markup such as <strong>, which is escaped by the caller.
	 * @return void
	 */
	public static function notice( $type, $message ) {
		printf(
			'<p class="gs-notice gs-notice--%1$s">%2$s</p>',
			esc_attr( self::notice_class( $type ) ),
			wp_kses_post( $message )
		);
	}
}
