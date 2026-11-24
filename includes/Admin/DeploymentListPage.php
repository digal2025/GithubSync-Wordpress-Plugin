<?php
/**
 * Deployment list page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Lists configurations with quick actions.
 */
final class DeploymentListPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$deployments = new DeploymentRepository();
		$jobs        = new JobRepository();

		$rows = $deployments->all();

		foreach ( $rows as $index => $row ) {
			$recent                = $jobs->recent( (int) $row['id'], 1 );
			$rows[ $index ]['last_job'] = $recent ? $recent[0] : null;
		}

		$data = array(
			'deployments' => $rows,
			'types'       => \GitHubSync\Domain\DestinationPolicy::types(),
		);

		include GITHUBSYNC_DIR . 'templates/deployment-list.php';
	}
}
