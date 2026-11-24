<?php
/**
 * Per-configuration deployment lock.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the options table's unique option_name index for an atomic acquire.
 * The lock value contains an expiry so abandoned locks can be stolen safely.
 */
final class DeploymentLock {

	/**
	 * Default TTL in seconds.
	 */
	private const DEFAULT_TTL = 1800;

	/**
	 * Option name for a deployment.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @return string
	 */
	private function option_name( $deployment_id ) {
		return 'githubsync_lock_' . (int) $deployment_id;
	}

	/**
	 * Acquire the lock.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @param int $ttl           Time to live in seconds.
	 * @return string|false Lock token on success.
	 */
	public function acquire( $deployment_id, $ttl = self::DEFAULT_TTL ) {
		$name  = $this->option_name( $deployment_id );
		$token = Helpers::random_token( 16 );
		$value = (string) wp_json_encode(
			array(
				'token'   => $token,
				'expires' => time() + (int) $ttl,
			)
		);

		if ( add_option( $name, $value, '', 'no' ) ) {
			return $token;
		}

		$existing = get_option( $name );
		$decoded  = is_string( $existing ) ? json_decode( $existing, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['expires'] ) ) {
			// Corrupt lock: remove and retry once.
			delete_option( $name );
			return add_option( $name, $value, '', 'no' ) ? $token : false;
		}

		if ( (int) $decoded['expires'] > time() ) {
			return false;
		}

		global $wpdb;

		// Atomic steal: only succeeds if the stored value is still the one we read.
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				$existing
			)
		);

		wp_cache_delete( $name, 'options' );

		return 1 === (int) $updated ? $token : false;
	}

	/**
	 * Extend a held lock.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param string $token         Lock token.
	 * @param int    $ttl           New TTL.
	 * @return bool
	 */
	public function heartbeat( $deployment_id, $token, $ttl = self::DEFAULT_TTL ) {
		$name     = $this->option_name( $deployment_id );
		$existing = get_option( $name );
		$decoded  = is_string( $existing ) ? json_decode( $existing, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['token'] ) || ! hash_equals( (string) $decoded['token'], (string) $token ) ) {
			return false;
		}

		$value = (string) wp_json_encode(
			array(
				'token'   => $token,
				'expires' => time() + (int) $ttl,
			)
		);

		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				$existing
			)
		);

		wp_cache_delete( $name, 'options' );

		return 1 === (int) $updated;
	}

	/**
	 * Release a held lock.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param string $token         Lock token.
	 * @return void
	 */
	public function release( $deployment_id, $token ) {
		$name     = $this->option_name( $deployment_id );
		$existing = get_option( $name );
		$decoded  = is_string( $existing ) ? json_decode( $existing, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['token'] ) || ! hash_equals( (string) $decoded['token'], (string) $token ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name,
				$existing
			)
		);

		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Whether a lock is currently held.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @return bool
	 */
	public function is_locked( $deployment_id ) {
		$existing = get_option( $this->option_name( $deployment_id ) );
		$decoded  = is_string( $existing ) ? json_decode( $existing, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['expires'] ) ) {
			return false;
		}

		return (int) $decoded['expires'] > time();
	}
}
