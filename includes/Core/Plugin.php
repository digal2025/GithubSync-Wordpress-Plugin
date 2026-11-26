<?php
/**
 * Plugin bootstrap.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Core;

use GitHubSync\Admin\Actions;
use GitHubSync\Admin\AdminMenu;
use GitHubSync\Admin\Assets;
use GitHubSync\Database\DeliveryRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Database\LogRepository;
use GitHubSync\Database\Schema;
use GitHubSync\Deployment\DeploymentManager;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\Queue\Queue;
use GitHubSync\Support\Helpers;
use GitHubSync\Support\Settings;
use GitHubSync\Update\Updater;
use GitHubSync\Webhooks\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Wires WordPress hooks to plugin services. Nothing heavy loads on
 * front-end requests: GitHub API code only loads when a job or admin page
 * actually needs it.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		load_plugin_textdomain( 'githubsync', false, dirname( plugin_basename( GITHUBSYNC_FILE ) ) . '/languages' );

		Schema::maybe_upgrade();

		add_filter( 'cron_schedules', array( Queue::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		add_action( 'admin_menu', array( AdminMenu::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		// admin-post.php does not fire admin_init, so register handlers earlier.
		add_action( 'init', array( Actions::class, 'register' ) );
		add_action( 'admin_notices', array( AdminMenu::class, 'render_notices' ) );

		add_action( 'rest_api_init', array( WebhookController::class, 'register_routes' ) );

		add_action( Queue::HOOK, array( DeploymentManager::class, 'handle_job' ), 10, 1 );
		add_action( Queue::TICK_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'githubsync_cleanup', array( __CLASS__, 'cleanup' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( GITHUBSYNC_FILE ), array( __CLASS__, 'action_links' ) );

		Updater::register();

		if ( Helpers::is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
		}

		if ( Queue::cron_disabled() ) {
			add_action( 'admin_notices', array( __CLASS__, 'cron_notice' ) );
		}
	}

	/**
	 * Recovery tick: reclaim abandoned jobs and run anything due.
	 *
	 * @return void
	 */
	public static function run_tick() {
		( new JobRepository() )->recover_stale( HOUR_IN_SECONDS );

		if ( Queue::cron_disabled() ) {
			return;
		}

		( new Queue() )->process_due( 3 );
	}

	/**
	 * Daily retention cleanup.
	 *
	 * @return void
	 */
	public static function cleanup() {
		( new LogRepository() )->clear_older_than( (int) Settings::get( 'log_retention_days', 30 ) );
		( new DeliveryRepository() )->purge_older_than( 30 );

		// Remove abandoned work directories older than 24 hours.
		$work = Helpers::work_dir();
		$items = @scandir( $work ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $items ) ) {
			return;
		}

		$fs = new FilesystemAdapter();

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || 0 !== strpos( $item, 'job-' ) ) {
				continue;
			}

			$path = $work . $item;

			if ( is_dir( $path ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				$fs->remove_tree( $path, $work );
			}
		}
	}

	/**
	 * Plugin row action links.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=githubsync' ) ),
			esc_html__( 'Dashboard', 'githubsync' )
		);

		return $links;
	}

	/**
	 * Multisite unsupported notice.
	 *
	 * @return void
	 */
	public static function multisite_notice() {
		if ( ! \GitHubSync\Security\Capability::user_can_manage() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'GitHubSync does not support WordPress multisite in this release. Deployments are disabled until the network is converted to a single site.', 'githubsync' )
		);
	}

	/**
	 * WP-Cron disabled notice.
	 *
	 * @return void
	 */
	public static function cron_notice() {
		if ( ! \GitHubSync\Security\Capability::user_can_manage() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'DISABLE_WP_CRON is enabled. GitHubSync deployments will only run when a real system cron calls wp-cron.php or when you use "Run queue now" on the Tools page.', 'githubsync' )
		);
	}
}
