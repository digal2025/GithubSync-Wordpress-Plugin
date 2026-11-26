<?php
/**
 * Uninstall routine.
 *
 * Data is only removed when the administrator explicitly opted in. Deployed
 * files and backups are never deleted automatically.
 *
 * @package GitHubSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'githubsync_settings', array() );

if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

$tables = array(
	$wpdb->prefix . 'githubsync_deliveries',
	$wpdb->prefix . 'githubsync_logs',
	$wpdb->prefix . 'githubsync_jobs',
	$wpdb->prefix . 'githubsync_deployments',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

$options = array(
	'githubsync_settings',
	'githubsync_schema_version',
	'githubsync_github_connection',
	'githubsync_github_app_key',
	'githubsync_github_pat',
	'githubsync_activated_at',
	'githubsync_update_status',
	'githubsync_update_url',
	'githubsync_update_checked_at',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove per-deployment locks.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'githubsync_lock_' ) . '%'
	)
);

// Remove plugin transients.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_githubsync_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_githubsync_' ) . '%'
	)
);
