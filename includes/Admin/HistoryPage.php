<?php
/**
 * Deployment history page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * All jobs with their outcome, counts and error details.
 */
final class HistoryPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$deployments = new DeploymentRepository();
		$jobs        = new JobRepository();

		$names = array();

		foreach ( $deployments->all() as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
		}

		$data = array(
			'jobs'          => $jobs->recent( 0, 200 ),
			'names'         => $names,
			'history_count' => $jobs->count(),
		);

		include GITHUBSYNC_DIR . 'templates/history.php';
	}
}
