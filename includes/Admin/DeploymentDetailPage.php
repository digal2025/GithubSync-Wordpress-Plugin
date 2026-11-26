<?php
/**
 * Deployment detail page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Database\LogRepository;
use GitHubSync\Deployment\BackupManager;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\Security\Capability;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Webhooks\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Full view of one configuration with deploy, pause, rollback and delete.
 */
final class DeploymentDetailPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$row = ( new DeploymentRepository() )->find( $id );

		if ( ! $row ) {
			wp_die( esc_html__( 'That deployment configuration does not exist.', 'githubsync' ), 404 );
		}

		$jobs    = new JobRepository();
		$backups = new BackupManager( new FilesystemAdapter() );

		$secret = '';

		try {
			$vault  = CredentialVault::from_site();
			$secret = $vault->decrypt( (string) $row['webhook_secret_encrypted'] );
		} catch ( \Throwable $e ) {
			$secret = '';
		}

		$successful = $jobs->successful( $id, 10 );
		$rollbacks  = array();

		foreach ( $successful as $job ) {
			if ( $backups->exists( $id, (int) $job['id'], (string) $job['commit_sha'] ) ) {
				$rollbacks[] = $job;
			}
		}

		$commits       = array();
		$commits_error = '';

		try {
			$commits = ( new GitHubApiClient() )->commits( (string) $row['repository_full_name'], (string) $row['branch'], 10 );

			if ( is_wp_error( $commits ) ) {
				$commits_error = $commits->get_error_message();
				$commits       = array();
			}
		} catch ( \Throwable $e ) {
			$commits_error = __( 'GitHub history is unavailable right now.', 'githubsync' );
		}

		$data = array(
			'row'           => $row,
			'webhook_url'   => WebhookController::payload_url( (string) $row['webhook_token'] ),
			'secret'        => $secret,
			'jobs'          => $jobs->recent( $id, 20 ),
			'rollbacks'     => $rollbacks,
			'commits'       => $commits,
			'commits_error' => $commits_error,
			'logs'          => ( new LogRepository() )->for_deployment( $id, 30 ),
		);

		include GITHUBSYNC_DIR . 'templates/deployment-detail.php';
	}
}
