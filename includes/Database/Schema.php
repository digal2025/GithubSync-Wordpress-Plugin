<?php
/**
 * Custom table schema and upgrades.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the four custom tables with dbDelta().
 */
final class Schema {

	public const VERSION        = '1';
	public const OPTION_VERSION = 'githubsync_schema_version';

	/**
	 * Deployments table name.
	 *
	 * @return string
	 */
	public static function deployments() {
		global $wpdb;
		return $wpdb->prefix . 'githubsync_deployments';
	}

	/**
	 * Jobs table name.
	 *
	 * @return string
	 */
	public static function jobs() {
		global $wpdb;
		return $wpdb->prefix . 'githubsync_jobs';
	}

	/**
	 * Logs table name.
	 *
	 * @return string
	 */
	public static function logs() {
		global $wpdb;
		return $wpdb->prefix . 'githubsync_logs';
	}

	/**
	 * Deliveries table name.
	 *
	 * @return string
	 */
	public static function deliveries() {
		global $wpdb;
		return $wpdb->prefix . 'githubsync_deliveries';
	}

	/**
	 * Create or upgrade tables. Safe to run repeatedly.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$deploy  = self::deployments();
		$jobs    = self::jobs();
		$logs    = self::logs();
		$dels    = self::deliveries();

		$statements = array();

		$statements[] = "CREATE TABLE {$deploy} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			github_repository_id bigint(20) unsigned NOT NULL DEFAULT 0,
			repository_full_name varchar(255) NOT NULL DEFAULT '',
			repository_visibility varchar(20) NOT NULL DEFAULT '',
			branch varchar(255) NOT NULL DEFAULT '',
			source_path text NULL,
			destination_path text NULL,
			deployment_type varchar(30) NOT NULL DEFAULT 'plugin',
			destination_slug varchar(100) NOT NULL DEFAULT '',
			exclude_patterns longtext NULL,
			auto_deploy tinyint(1) NOT NULL DEFAULT 0,
			manual_deploy tinyint(1) NOT NULL DEFAULT 1,
			adopt_existing tinyint(1) NOT NULL DEFAULT 0,
			webhook_id varchar(64) NULL,
			webhook_token varchar(64) NOT NULL DEFAULT '',
			webhook_secret_encrypted longtext NULL,
			last_deployed_sha varchar(64) NULL,
			last_successful_deployment_id bigint(20) unsigned NULL,
			status varchar(30) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY repo_id (github_repository_id),
			KEY status (status),
			KEY auto_deploy (auto_deploy),
			KEY updated_at (updated_at),
			KEY webhook_token (webhook_token)
		) {$charset};";

		$statements[] = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			deployment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			commit_sha varchar(64) NOT NULL DEFAULT '',
			trigger_type varchar(20) NOT NULL DEFAULT 'manual',
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempt_count int(11) NOT NULL DEFAULT 0,
			max_attempts int(11) NOT NULL DEFAULT 3,
			scheduled_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			started_at datetime NULL,
			finished_at datetime NULL,
			lock_token varchar(64) NULL,
			rollback_of bigint(20) unsigned NULL,
			files_added int(11) NOT NULL DEFAULT 0,
			files_updated int(11) NOT NULL DEFAULT 0,
			files_removed int(11) NOT NULL DEFAULT 0,
			files_total int(11) NOT NULL DEFAULT 0,
			error_code varchar(100) NULL,
			error_message text NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY deployment_status (deployment_id,status),
			KEY status_scheduled (status,scheduled_at),
			KEY commit_sha (commit_sha),
			KEY created_at (created_at)
		) {$charset};";

		$statements[] = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			deployment_id bigint(20) unsigned NULL,
			job_id bigint(20) unsigned NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			event_code varchar(100) NOT NULL DEFAULT '',
			message text NULL,
			context_json longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY deployment_created (deployment_id,created_at),
			KEY job_id (job_id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset};";

		$statements[] = "CREATE TABLE {$dels} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			delivery_id varchar(100) NOT NULL DEFAULT '',
			deployment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(50) NOT NULL DEFAULT '',
			received_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			processing_status varchar(20) NOT NULL DEFAULT 'received',
			PRIMARY KEY  (id),
			UNIQUE KEY delivery_id (delivery_id),
			KEY deployment_id (deployment_id),
			KEY received_at (received_at)
		) {$charset};";

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	/**
	 * Run install when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Whether all tables exist.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( array( self::deployments(), self::jobs(), self::logs(), self::deliveries() ) as $table ) {
			// Table names contain underscores, which are LIKE wildcards, so the
			// pattern must be escaped before it is used.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}
}
