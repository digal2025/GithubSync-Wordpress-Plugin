<?php
/**
 * Deployment list page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Deployment\BackupManager;
use GitHubSync\Filesystem\FilesystemAdapter;
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
		$fs          = new FilesystemAdapter();
		$backups     = new BackupManager( $fs );

		$rows = $deployments->all();

		foreach ( $rows as $index => $row ) {
			$recent                     = $jobs->recent( (int) $row['id'], 1 );
			$rows[ $index ]['last_job'] = $recent ? $recent[0] : null;

			$path = (string) $row['destination_path'];
			$stats = is_dir( $path ) ? $fs->stats( $path ) : array(
				'files' => 0,
				'bytes' => 0,
			);

			$rows[ $index ]['size']         = (int) $stats['bytes'];
			$rows[ $index ]['file_count']   = (int) $stats['files'];
			$rows[ $index ]['backup_bytes'] = $backups->usage_for( (int) $row['id'] );
			$rows[ $index ]['has_files']    = is_dir( $path );
		}

		$data = array(
			'deployments' => $rows,
			'types'       => \GitHubSync\Domain\DestinationPolicy::types(),
		);

		include GITHUBSYNC_DIR . 'templates/deployment-list.php';
	}
}
