<?php
/**
 * Deployment job persistence.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for wp_githubsync_jobs.
 */
final class JobRepository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::jobs();
	}

	/**
	 * Insert a job.
	 *
	 * @param array $data Column values.
	 * @return int|\WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = wp_parse_args(
			$data,
			array(
				'deployment_id' => 0,
				'commit_sha'    => '',
				'trigger_type'  => 'manual',
				'status'        => 'queued',
				'attempt_count' => 0,
				'max_attempts'  => 3,
				'scheduled_at'  => $now,
				'started_at'    => null,
				'finished_at'   => null,
				'lock_token'    => null,
				'error_code'    => null,
				'error_message' => null,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		$ok = $wpdb->insert( $this->table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $ok ) {
			return new \WP_Error( 'DB_INSERT_FAILED', __( 'The deployment job could not be created.', 'githubsync' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a job.
	 *
	 * @param int $id Job ID.
	 * @return array|null
	 */
	public function find( $id ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * An active (queued/running/retrying) job for a configuration.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param string $sha           Optional commit SHA to match.
	 * @return array|null
	 */
	public function active_for_deployment( $deployment_id, $sha = '' ) {
		global $wpdb;
		$table = $this->table();

		$sql    = "SELECT * FROM {$table} WHERE deployment_id = %d AND status IN ('queued','running','retrying')";
		$params = array( (int) $deployment_id );

		if ( '' !== $sha ) {
			$sql     .= ' AND commit_sha = %s';
			$params[] = $sha;
		}

		$sql .= ' ORDER BY id DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Jobs that are due to run.
	 *
	 * @param int $limit Maximum jobs.
	 * @return array[]
	 */
	public function due( $limit = 5 ) {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('queued','retrying') AND scheduled_at <= %s ORDER BY id ASC LIMIT %d",
				$now,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Update a job by ID.
	 *
	 * @param int   $id   Job ID.
	 * @param array $data Column values.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update( $this->table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Atomically claim a queued job for execution.
	 *
	 * @param int $id Job ID.
	 * @return bool True when this process claimed the job.
	 */
	public function claim( $id ) {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', started_at = %s, updated_at = %s WHERE id = %d AND status IN ('queued','retrying')",
				$now,
				$now,
				(int) $id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Recover jobs whose lock expired while marked running.
	 *
	 * @param int $stale_seconds Age threshold.
	 * @return int Number recovered.
	 */
	public function recover_stale( $stale_seconds = 3600 ) {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$old   = gmdate( 'Y-m-d H:i:s', time() - (int) $stale_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', error_code = 'INTERNAL_ERROR', error_message = %s, finished_at = %s, updated_at = %s WHERE status = 'running' AND started_at < %s",
				__( 'Job was abandoned by a previous request.', 'githubsync' ),
				$now,
				$now,
				$old
			)
		);
	}

	/**
	 * Recent jobs, optionally scoped to one deployment.
	 *
	 * @param int $deployment_id Deployment ID (0 for all).
	 * @param int $limit         Row limit.
	 * @return array[]
	 */
	public function recent( $deployment_id = 0, $limit = 50 ) {
		global $wpdb;
		$table = $this->table();

		if ( $deployment_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE deployment_id = %d ORDER BY id DESC LIMIT %d", (int) $deployment_id, (int) $limit ),
				ARRAY_A
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
	}

	/**
	 * Successful jobs that produced a backup (rollback candidates).
	 *
	 * @param int $deployment_id Deployment ID.
	 * @param int $limit         Row limit.
	 * @return array[]
	 */
	public function successful( $deployment_id, $limit = 10 ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE deployment_id = %d AND status = 'succeeded' ORDER BY id DESC LIMIT %d",
				(int) $deployment_id,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Count jobs by status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public function count_by_status( $status ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", (string) $status ) );
	}

	/**
	 * Cancel outstanding jobs for a configuration.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @return void
	 */
	public function cancel_pending( $deployment_id ) {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled', finished_at = %s, updated_at = %s WHERE deployment_id = %d AND status IN ('queued','retrying')",
				$now,
				$now,
				(int) $deployment_id
			)
		);
	}
}
