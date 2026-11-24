<?php
/**
 * Webhook delivery deduplication.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Records GitHub delivery IDs so a replay cannot queue a second deployment.
 */
final class DeliveryRepository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::deliveries();
	}

	/**
	 * Record a delivery. Returns false when the delivery ID already exists
	 * (the unique index makes this race-free).
	 *
	 * @param string $delivery_id     X-GitHub-Delivery value.
	 * @param int    $deployment_id   Deployment ID.
	 * @param string $event_type      X-GitHub-Event value.
	 * @return bool True when newly recorded, false on duplicate.
	 */
	public function record( $delivery_id, $deployment_id, $event_type ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array(
				'delivery_id'       => substr( (string) $delivery_id, 0, 100 ),
				'deployment_id'     => (int) $deployment_id,
				'event_type'        => substr( (string) $event_type, 0, 50 ),
				'received_at'       => gmdate( 'Y-m-d H:i:s' ),
				'processing_status' => 'received',
			)
		);

		$wpdb->suppress_errors( $suppress );

		return false !== $ok;
	}

	/**
	 * Update the processing status for a delivery.
	 *
	 * @param string $delivery_id Delivery ID.
	 * @param string $status      Status.
	 * @return void
	 */
	public function set_status( $delivery_id, $status ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table(),
			array( 'processing_status' => substr( (string) $status, 0, 20 ) ),
			array( 'delivery_id' => substr( (string) $delivery_id, 0, 100 ) )
		);
	}

	/**
	 * Delete deliveries older than a retention window.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public function purge_older_than( $days ) {
		global $wpdb;
		$table = $this->table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE received_at < %s", $cut ) );
	}
}
