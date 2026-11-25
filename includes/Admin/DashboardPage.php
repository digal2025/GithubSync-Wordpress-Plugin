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
	 * User meta flag set when the completed checklist is closed.
	 */
	public const DISMISSED_META = 'githubsync_checklist_dismissed';

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

		$all      = $deployments->all();
		$recent   = $jobs->recent( 0, 10 );
		$failures = array();
		$queue    = 0;

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
			'active_count'   => count(
				array_filter(
					$all,
					static function ( $row ) {
						return 'active' === $row['status']; }
				)
			),
			'auto_count'     => count(
				array_filter(
					$all,
					static function ( $row ) {
						return 1 === (int) $row['auto_deploy']; }
				)
			),
			'recent'         => $recent,
			'failures'       => $failures,
			'queue_count'    => $queue,
			'backup_usage'   => Helpers::human_bytes( $backups->usage() ),
			'cron_disabled'  => Queue::cron_disabled(),
			'cron_scheduled' => (bool) wp_next_scheduled( Queue::TICK_HOOK ),
			'steps'          => self::steps( $auth->is_connected(), CredentialVault::is_configured(), $all, $recent ),
			'dismissed'      => (bool) get_user_meta( get_current_user_id(), self::DISMISSED_META, true ),
		);

		include GITHUBSYNC_DIR . 'templates/dashboard.php';
	}

	/**
	 * Whether every setup step is complete.
	 *
	 * @return bool
	 */
	public static function setup_complete() {
		foreach ( self::steps( ( new GitHubAuth() )->is_connected(), CredentialVault::is_configured(), ( new DeploymentRepository() )->all(), ( new JobRepository() )->recent( 0, 10 ) ) as $step ) {
			if ( empty( $step['done'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Setup checklist with live state.
	 *
	 * A step is only marked done from stored data: connected credentials,
	 * a usable encryption key, saved deployments and recorded jobs. Whether
	 * a webhook has actually been added on GitHub cannot be read without
	 * asking GitHub, so that step stays open until a webhook-triggered job
	 * is recorded and says so in its text.
	 *
	 * @param bool  $connected     Whether GitHub credentials are stored.
	 * @param bool  $encryption_ok Whether credentials can be stored at all.
	 * @param array $deployments   All deployments.
	 * @param array $recent        Recent jobs.
	 * @return array[]
	 */
	private static function steps( $connected, $encryption_ok, array $deployments, array $recent ) {
		$pushed   = false;
		$deployed = false;

		foreach ( $recent as $job ) {
			if ( 'webhook' === (string) $job['trigger_type'] ) {
				$pushed = true;
			}
			if ( 'success' === $job['status'] && 'webhook' === (string) $job['trigger_type'] ) {
				$deployed = true;
			}
		}

		$first = ! empty( $deployments ) ? (int) $deployments[0]['id'] : 0;

		return array(
			array(
				'title'   => __( 'Create the encryption key', 'githubsync' ),
				'done'    => $encryption_ok,
				'body'    => __( 'Credentials are encrypted before they are stored, so nothing can be saved until a key exists. GitHubSync writes a key file outside the web root; if the site cannot write there, define GITHUBSYNC_ENCRYPTION_KEY in wp-config.php instead.', 'githubsync' ),
				'action'  => array( __( 'Open Settings', 'githubsync' ), 'githubsync-settings' ),
				'help'    => __( 'Status: key configured.', 'githubsync' ),
				'missing' => __( 'No usable key yet. Click “Create key file” on the Settings page.', 'githubsync' ),
			),
			array(
				'title'   => __( 'Connect GitHub', 'githubsync' ),
				'done'    => $connected,
				'body'    => __( 'Create a GitHub App (App ID, installation ID, private key) and install it on the repository you want to deploy, or use a fine-grained token with Contents (read) and Metadata (read). The plugin only reads; it never writes to GitHub.', 'githubsync' ),
				'action'  => array( __( 'Open GitHub Connection', 'githubsync' ), 'githubsync-connection' ),
				'help'    => __( 'Status: credentials stored.', 'githubsync' ),
				'missing' => __( 'Save a GitHub App or token on the connection page.', 'githubsync' ),
			),
			array(
				'title'   => __( 'Add a deployment', 'githubsync' ),
				'done'    => ! empty( $deployments ),
				'body'    => __( 'Pick a repository and branch, a destination type (theme, plugin or static site) and the directory slug under wp-content. Only files inside that destination are written; nothing else on the site is touched.', 'githubsync' ),
				'action'  => array( __( 'Add Deployment', 'githubsync' ), 'githubsync-deployment-new' ),
				'help'    => __( 'Status: at least one deployment saved.', 'githubsync' ),
				'missing' => __( 'No deployment saved yet.', 'githubsync' ),
			),
			array(
				'title'   => __( 'Add the webhook on GitHub', 'githubsync' ),
				'done'    => $pushed,
				'body'    => __( 'Open the deployment page and copy its Payload URL and Secret. On GitHub: repository → Settings → Webhooks → Add webhook. Content type must be application/json (form-encoded bodies fail signature checks), paste the secret, enable SSL verification, subscribe to push events only, and leave Active checked. GitHub sends a ping immediately; Recent Deliveries should show 200 with the body pong.', 'githubsync' ),
				'action'  => $first
					? array( __( 'Open deployment', 'githubsync' ), 'githubsync-deployment&id=' . $first )
					: array( __( 'Add Deployment', 'githubsync' ), 'githubsync-deployment-new' ),
				'help'    => __( 'Status: a webhook delivery has been received.', 'githubsync' ),
				'missing' => __( 'No webhook delivery recorded yet. Adding the webhook is a manual step: this version has no API call that creates it for you.', 'githubsync' ),
			),
			array(
				'title'   => __( 'Push to the deployed branch', 'githubsync' ),
				'done'    => $deployed,
				'body'    => __( 'Commit to the branch the deployment watches. GitHubSync accepts the delivery, queues a job, and deploys on the next run. WP-Cron drives it, so a busy site with low traffic can be slow; a system cron hitting wp-cron.php every five minutes is more reliable.', 'githubsync' ),
				'action'  => array( __( 'Open History', 'githubsync' ), 'githubsync-history' ),
				'help'    => __( 'Status: a push-triggered deployment succeeded.', 'githubsync' ),
				'missing' => $pushed
					? __( 'A push was received but has not completed successfully yet. Check the job on the History page.', 'githubsync' )
					: __( 'Nothing has been deployed from a push yet.', 'githubsync' ),
			),
		);
	}
}
