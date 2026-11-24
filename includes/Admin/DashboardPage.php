<?php
/**
 * Dashboard page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Deployment\BackupManager;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\Queue\Queue;
use GitHubSync\Security\Capability;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Overview of connection, configurations, queue and recent activity.
 */
final class DashboardPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$auth        = new GitHubAuth();
		$deployments = new DeploymentRepository();
		$jobs        = new JobRepository();
		$backups     = new BackupManager( new FilesystemAdapter() );

		$all       = $deployments->all();
		$recent    = $jobs->recent( 0, 10 );
		$failures  = array();
		$queue     = 0;

		foreach ( $jobs->recent( 0, 50 ) as $job ) {
			if ( 'failed' === $job['status'] && count( $failures ) < 5 ) {
				$failures[] = $job;
			}
			if ( in_array( $job['status'], array( 'queued', 'retrying' ), true ) ) {
				$queue++;
			}
		}

		$data = array(
			'connected'      => $auth->is_connected(),
			'connection'     => $auth->summary(),
			'encryption_ok'  => CredentialVault::is_configured(),
			'deployments'    => $all,
			'active_count'   => count( array_filter( $all, static function ( $row ) { return 'active' === $row['status']; } ) ),
			'auto_count'     => count( array_filter( $all, static function ( $row ) { return (int) $row['auto_deploy'] === 1; } ) ),
			'recent'         => $recent,
			'failures'       => $failures,
			'queue_count'    => $queue,
			'backup_usage'   => Helpers::human_bytes( $backups->usage() ),
			'cron_disabled'  => Queue::cron_disabled(),
		);

		include GITHUBSYNC_DIR . 'templates/dashboard.php';
	}
}
