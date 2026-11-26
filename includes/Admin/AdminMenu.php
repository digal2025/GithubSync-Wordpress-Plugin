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
			'history_cleared'      => __( 'Deployment history cleared. Active jobs, deployed files and backups were kept.', 'githubsync' ),
			'queue_ran'            => __( 'Queue processed.', 'githubsync' ),
			'backups_pruned'       => __( 'Old backups cleared.', 'githubsync' ),
			'checklist_closed'     => __( 'Setup checklist closed. You can show it again at any time from the dashboard.', 'githubsync' ),
			'checklist_incomplete' => __( 'The setup checklist cannot be closed until every step is complete.', 'githubsync' ),
			'checklist_restored'   => __( 'Setup checklist reopened.', 'githubsync' ),
			'update_current'       => __( 'GitHubSync is up to date.', 'githubsync' ),
			'update_available'     => __( 'A new version of GitHubSync is available on Dashboard → Updates.', 'githubsync' ),
			'update_disabled'      => __( 'Self-updates are disabled for GitHubSync.', 'githubsync' ),
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
	 * @param string $icon     Optional icon name for the header tile.
	 * @return void
	 */
	public static function page_head( $title, $subtitle = '', $actions = '', $icon = '' ) {
		echo '<div class="gs-page-head">';
		echo '<div class="gs-page-head__text">';

		if ( '' !== $icon ) {
			echo '<span class="gs-page-head__icon" aria-hidden="true">';
			self::icon( $icon );
			echo '</span>';
		}

		echo '<div>';
		echo '<h1 class="gs-title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $subtitle ) {
			echo '<p class="gs-subtitle">' . esc_html( $subtitle ) . '</p>';
		}

		echo '</div>';
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
	 * Print a small stroked icon from a fixed set.
	 *
	 * The markup is inlined so the screens need no icon font and no extra
	 * request. Unknown names print nothing.
	 *
	 * @param string $name  Icon name.
	 * @param string $class CSS classes for the svg element.
	 * @return void
	 */
	public static function icon( $name, $class = 'gs-icon' ) {
		$icons = array(
			'activity'   => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
			'alert'      => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
			'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
			'branch'     => '<line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
			'check'      => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
			'clock'      => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
			'cloud'      => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
			'copy'       => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			'dashboard'  => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
			'database'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
			'external'   => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
			'folder'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
			'history'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
			'inbox'      => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
			'key'        => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
			'link'       => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			'list'       => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
			'package'    => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
			'plug'       => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8H6a2 2 0 0 0-2 2v2a6 6 0 0 0 6 6h4a6 6 0 0 0 6-6v-2a2 2 0 0 0-2-2z"/>',
			'plus'       => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
			'refresh'    => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
			'rocket'     => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91 0z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
			'search'     => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
			'settings'   => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
			'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
			'tools'      => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
			'zap'        => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
		);

		$name = (string) $name;

		if ( ! isset( $icons[ $name ] ) ) {
			return;
		}

		printf(
			'<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">',
			esc_attr( $class )
		);
		echo $icons[ $name ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup from the map above.
		echo '</svg>';
	}

	/**
	 * Render an empty state.
	 *
	 * @param string $title   Headline.
	 * @param string $text    Supporting copy.
	 * @param string $actions Optional escaped action markup.
	 * @param string $icon    Optional icon name.
	 * @return void
	 */
	public static function empty_state( $title, $text = '', $actions = '', $icon = 'inbox' ) {
		echo '<div class="gs-empty">';
		echo '<span class="gs-empty__icon" aria-hidden="true">';
		self::icon( $icon );
		echo '</span>';
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
