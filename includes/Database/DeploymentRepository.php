<?php
/**
 * Deployment configuration persistence.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for wp_githubsync_deployments, using prepared statements.
 */
final class DeploymentRepository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::deployments();
	}

	/**
	 * All configurations, newest first.
	 *
	 * @return array[]
	 */
	public function all() {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
	}

	/**
	 * Configurations with automatic deployment enabled and active status.
	 *
	 * @return array[]
	 */
	public function auto_enabled() {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE auto_deploy = 1 AND status = %s ORDER BY id DESC", 'active' ),
			ARRAY_A
		);
	}

	/**
	 * Find by primary key.
	 *
	 * @param int $id Deployment ID.
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
	 * Find by webhook token.
	 *
	 * @param string $token Webhook token.
	 * @return array|null
	 */
	public function find_by_token( $token ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE webhook_token = %s", (string) $token ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether another configuration already owns a destination path.
	 *
	 * @param string $path      Absolute destination path.
	 * @param int    $exclude_id Configuration to ignore.
	 * @return bool
	 */
	public function destination_in_use( $path, $exclude_id = 0 ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE destination_path = %s AND id <> %d", (string) $path, (int) $exclude_id )
		);

		return $count > 0;
	}

	/**
	 * Insert a configuration.
	 *
	 * @param array $data Column values.
	 * @return int|\WP_Error New ID.
	 */
	public function create( array $data ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = wp_parse_args(
			$data,
			array(
				'name'                        => '',
				'github_repository_id'        => 0,
				'repository_full_name'        => '',
				'repository_visibility'       => '',
				'branch'                      => '',
				'source_path'                 => '',
				'destination_path'            => '',
				'deployment_type'             => 'plugin',
				'destination_slug'            => '',
				'exclude_patterns'            => '',
				'auto_deploy'                 => 0,
				'manual_deploy'               => 1,
				'adopt_existing'              => 0,
				'webhook_id'                  => null,
				'webhook_token'               => '',
				'webhook_secret_encrypted'    => '',
				'last_deployed_sha'           => null,
				'last_successful_deployment_id' => null,
				'status'                      => 'active',
				'created_at'                  => $now,
				'updated_at'                  => $now,
			)
		);

		$ok = $wpdb->insert( $this->table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $ok ) {
			return new \WP_Error( 'DB_INSERT_FAILED', __( 'The deployment configuration could not be saved.', 'githubsync' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a configuration.
	 *
	 * @param int   $id   Deployment ID.
	 * @param array $data Column values.
	 * @return bool|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$ok = $wpdb->update( $this->table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $ok ) {
			return new \WP_Error( 'DB_UPDATE_FAILED', __( 'The deployment configuration could not be updated.', 'githubsync' ) );
		}

		return true;
	}

	/**
	 * Delete a configuration row (history is retained).
	 *
	 * @param int $id Deployment ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Count configurations by status.
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
	 * Record a successful deployment against a configuration.
	 *
	 * @param int    $id      Deployment ID.
	 * @param string $sha     Commit SHA.
	 * @param int    $job_id  Job ID that succeeded.
	 * @return void
	 */
	public function record_success( $id, $sha, $job_id ) {
		$this->update(
			$id,
			array(
				'last_deployed_sha'              => $sha,
				'last_successful_deployment_id'  => (int) $job_id,
			)
		);
	}
}
