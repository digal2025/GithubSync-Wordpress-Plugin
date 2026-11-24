<?php
/**
 * Log persistence with retention.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for wp_githubsync_logs.
 */
final class LogRepository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::logs();
	}

	/**
	 * Insert a log row.
	 *
	 * @param array $data Column values.
	 * @return int
	 */
	public function insert( array $data ) {
		global $wpdb;

		$row = wp_parse_args(
			$data,
			array(
				'deployment_id' => null,
				'job_id'        => null,
				'level'         => 'info',
				'event_code'    => '',
				'message'       => '',
				'context_json'  => '',
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$wpdb->insert( $this->table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Logs for one deployment.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @param int $limit         Row limit.
	 * @return array[]
	 */
	public function for_deployment( $deployment_id, $limit = 100 ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE deployment_id = %d ORDER BY id DESC LIMIT %d", (int) $deployment_id, (int) $limit ),
			ARRAY_A
		);
	}

	/**
	 * Logs for one job.
	 *
	 * @param int $job_id Job ID.
	 * @return array[]
	 */
	public function for_job( $job_id ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC", (int) $job_id ),
			ARRAY_A
		);
	}

	/**
	 * Most recent entries.
	 *
	 * @param int $limit Row limit.
	 * @return array[]
	 */
	public function recent( $limit = 100 ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
	}

	/**
	 * Delete rows older than a number of days.
	 *
	 * @param int $days Retention days.
	 * @return int Deleted rows.
	 */
	public function clear_older_than( $days ) {
		global $wpdb;
		$table = $this->table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cut ) );
	}

	/**
	 * Delete all rows.
	 *
	 * @return void
	 */
	public function clear_all() {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Total row count.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
