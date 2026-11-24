<?php
/**
 * Background queue.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Queue;

use GitHubSync\Database\JobRepository;
use GitHubSync\Deployment\DeploymentManager;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler when available, WP-Cron otherwise. The hook name and
 * arguments are identical for both adapters so job handlers stay simple.
 */
final class Queue {

	public const HOOK       = 'githubsync_run_job';
	public const AS_GROUP   = 'githubsync';
	public const TICK_HOOK  = 'githubsync_queue_tick';

	/**
	 * Jobs repository.
	 *
	 * @var JobRepository
	 */
	private $jobs;

	/**
	 * Constructor.
	 *
	 * @param JobRepository|null $jobs Jobs repository.
	 */
	public function __construct( ?JobRepository $jobs = null ) {
		$this->jobs = $jobs ? $jobs : new JobRepository();
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function uses_action_scheduler() {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Enqueue a deployment job. Idempotent for an already-active job with the
	 * same commit.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param string $sha           Immutable commit SHA.
	 * @param string $trigger       manual|webhook|retry|rollback.
	 * @param int    $max_attempts  Maximum attempts.
	 * @param int    $rollback_of   Source job ID for rollbacks.
	 * @return int|\WP_Error Job ID.
	 */
	public function enqueue( $deployment_id, $sha, $trigger = 'manual', $max_attempts = 3, $rollback_of = 0 ) {
		// A rollback always creates its own job: the dedupe lookup cannot tell
		// a rollback apart from an ordinary deployment of the same commit.
		if ( $rollback_of <= 0 ) {
			$existing = $this->jobs->active_for_deployment( $deployment_id, $sha );

			if ( $existing ) {
				return (int) $existing['id'];
			}
		}

		$job_id = $this->jobs->create(
			array(
				'deployment_id' => (int) $deployment_id,
				'commit_sha'    => (string) $sha,
				'trigger_type'  => in_array( $trigger, array( 'manual', 'webhook', 'retry', 'rollback' ), true ) ? $trigger : 'manual',
				'status'        => 'queued',
				'max_attempts'  => max( 1, (int) $max_attempts ),
				'scheduled_at'  => Helpers::now(),
				'rollback_of'   => $rollback_of > 0 ? (int) $rollback_of : null,
			)
		);

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		self::schedule( $job_id, time() );

		return $job_id;
	}

	/**
	 * Schedule the handler for a job.
	 *
	 * @param int $job_id    Job ID.
	 * @param int $timestamp Unix timestamp.
	 * @return void
	 */
	public static function schedule( $job_id, $timestamp ) {
		$timestamp = max( time(), (int) $timestamp );

		if ( self::uses_action_scheduler() ) {
			as_schedule_single_action( $timestamp, self::HOOK, array( (int) $job_id ), self::AS_GROUP );
			return;
		}

		wp_schedule_single_event( $timestamp, self::HOOK, array( (int) $job_id ) );
	}

	/**
	 * Process jobs that are due but whose cron event was missed. Runs on the
	 * recovery tick and from the admin "Run queue now" action.
	 *
	 * @param int $limit Maximum jobs.
	 * @return int Number processed.
	 */
	public function process_due( $limit = 3 ) {
		$processed = 0;

		foreach ( $this->jobs->due( $limit ) as $job ) {
			DeploymentManager::handle_job( (int) $job['id'] );
			$processed++;
		}

		return $processed;
	}

	/**
	 * Whether WP-Cron is disabled, which would stall the queue.
	 *
	 * @return bool
	 */
	public static function cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Schedule the recurring recovery tick.
	 *
	 * @return void
	 */
	public static function schedule_tick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 300, 'githubsync_five_minutes', self::TICK_HOOK );
		}
	}

	/**
	 * Remove all scheduled events owned by the plugin.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( 'githubsync_cleanup' );
	}

	/**
	 * Register the custom five-minute interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules['githubsync_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (GitHubSync)', 'githubsync' ),
		);

		return $schedules;
	}
}
