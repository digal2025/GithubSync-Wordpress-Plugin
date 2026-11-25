<?php
/**
 * Activation routine.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Core;

use GitHubSync\Database\Schema;
use GitHubSync\Queue\Queue;
use GitHubSync\Support\Helpers;
use GitHubSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once on activation. Idempotent.
 */
final class Activator {

	/**
	 * Activate.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', 'no' );
		}

		// The custom recurrence must be registered before it is scheduled; the
		// plugin's normal boot has not run yet during first activation.
		add_filter( 'cron_schedules', array( Queue::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		Queue::schedule_tick();

		if ( ! wp_next_scheduled( 'githubsync_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'githubsync_cleanup' );
		}

		Helpers::protect_dir( Helpers::work_dir() );

		update_option( 'githubsync_activated_at', Helpers::now(), false );
	}
}
