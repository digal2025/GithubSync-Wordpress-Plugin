<?php
/**
 * Deactivation routine.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Core;

use GitHubSync\Queue\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Removes scheduled events only. Configurations, history, deployed files and
 * backups are always preserved on deactivation.
 */
final class Deactivator {

	/**
	 * Deactivate.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Queue::unschedule_all();
	}
}
