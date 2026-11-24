<?php
/**
 * Tools and diagnostics page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\LogRepository;
use GitHubSync\Database\Schema;
use GitHubSync\Deployment\BackupManager;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\Queue\Queue;
use GitHubSync\Security\Capability;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only environment checks plus safe maintenance actions.
 */
final class ToolsPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$fs      = new FilesystemAdapter();
		$backups = new BackupManager( $fs );
		$work    = Helpers::work_dir();

		$checks = array(
			array(
				'label'  => __( 'PHP version', 'githubsync' ),
				'value'  => PHP_VERSION,
				'ok'     => version_compare( PHP_VERSION, GITHUBSYNC_MIN_PHP, '>=' ),
				'help'   => sprintf( /* translators: %s: version. */ __( 'GitHubSync requires PHP %s or newer.', 'githubsync' ), GITHUBSYNC_MIN_PHP ),
			),
			array(
				'label' => __( 'WordPress version', 'githubsync' ),
				'value' => get_bloginfo( 'version' ),
				'ok'    => version_compare( get_bloginfo( 'version' ), GITHUBSYNC_MIN_WP, '>=' ),
				'help'  => sprintf( /* translators: %s: version. */ __( 'GitHubSync requires WordPress %s or newer.', 'githubsync' ), GITHUBSYNC_MIN_WP ),
			),
			array(
				'label' => __( 'ZIP extension', 'githubsync' ),
				'value' => class_exists( 'ZipArchive' ) ? __( 'Available', 'githubsync' ) : __( 'Missing', 'githubsync' ),
				'ok'    => class_exists( 'ZipArchive' ),
				'help'  => __( 'Required to extract repository archives.', 'githubsync' ),
			),
			array(
				'label' => __( 'curl extension', 'githubsync' ),
				'value' => function_exists( 'curl_init' ) ? __( 'Available', 'githubsync' ) : __( 'Missing', 'githubsync' ),
				'ok'    => function_exists( 'curl_init' ),
				'help'  => __( 'Used for streaming archive downloads. The WordPress HTTP API is used as a fallback.', 'githubsync' ),
			),
			array(
				'label' => __( 'Encryption (sodium/OpenSSL)', 'githubsync' ),
				'value' => CredentialVault::is_configured() ? __( 'Configured', 'githubsync' ) : __( 'Not configured', 'githubsync' ),
				'ok'    => CredentialVault::is_configured(),
				'help'  => __( 'Credentials cannot be stored until a site key exists.', 'githubsync' ),
			),
			array(
				'label' => __( 'Working directory', 'githubsync' ),
				'value' => $work,
				'ok'    => is_dir( $work ) && is_writable( $work ),
				'help'  => __( 'Temporary archives and extracted files live here.', 'githubsync' ),
			),
			array(
				'label' => __( 'WP-Cron', 'githubsync' ),
				'value' => Queue::cron_disabled() ? __( 'Disabled', 'githubsync' ) : __( 'Enabled', 'githubsync' ),
				'ok'    => ! Queue::cron_disabled(),
				'help'  => __( 'Background deployments need a working scheduler.', 'githubsync' ),
			),
			array(
				'label' => __( 'Action Scheduler', 'githubsync' ),
				'value' => Queue::uses_action_scheduler() ? __( 'Available', 'githubsync' ) : __( 'Not detected', 'githubsync' ),
				'ok'    => true,
				'help'  => __( 'When present, jobs are dispatched through Action Scheduler.', 'githubsync' ),
			),
			array(
				'label' => __( 'Database tables', 'githubsync' ),
				'value' => Schema::tables_exist() ? __( 'Present', 'githubsync' ) : __( 'Missing', 'githubsync' ),
				'ok'    => Schema::tables_exist(),
				'help'  => __( 'Re-activate the plugin to create missing tables.', 'githubsync' ),
			),
			array(
				'label' => __( 'GitHub connection', 'githubsync' ),
				'value' => ( new GitHubAuth() )->summary(),
				'ok'    => ( new GitHubAuth() )->is_connected(),
				'help'  => __( 'Connect GitHub on the GitHub Connection page.', 'githubsync' ),
			),
		);

		$data = array(
			'checks'       => $checks,
			'backup_usage' => Helpers::human_bytes( $backups->usage() ),
			'log_count'    => ( new LogRepository() )->count(),
			'work_dir'     => $work,
		);

		include GITHUBSYNC_DIR . 'templates/tools.php';
	}
}
