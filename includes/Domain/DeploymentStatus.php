<?php
/**
 * Status vocabulary for configurations and jobs.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * String constants (PHP 7.4 compatible: no enums).
 */
final class DeploymentStatus {

	public const ACTIVE   = 'active';
	public const PAUSED   = 'paused';
	public const ARCHIVED = 'archived';

	public const JOB_QUEUED    = 'queued';
	public const JOB_RUNNING   = 'running';
	public const JOB_RETRYING  = 'retrying';
	public const JOB_SUCCEEDED = 'succeeded';
	public const JOB_FAILED    = 'failed';
	public const JOB_CANCELLED = 'cancelled';

	/**
	 * Human label for a job status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function label( $status ) {
		$labels = array(
			self::JOB_QUEUED    => __( 'Queued', 'githubsync' ),
			self::JOB_RUNNING   => __( 'Running', 'githubsync' ),
			self::JOB_RETRYING  => __( 'Retrying', 'githubsync' ),
			self::JOB_SUCCEEDED => __( 'Succeeded', 'githubsync' ),
			self::JOB_FAILED    => __( 'Failed', 'githubsync' ),
			self::JOB_CANCELLED => __( 'Cancelled', 'githubsync' ),
			self::ACTIVE        => __( 'Active', 'githubsync' ),
			self::PAUSED        => __( 'Paused', 'githubsync' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * CSS class suffix for a status badge.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function badge_class( $status ) {
		$map = array(
			self::JOB_SUCCEEDED => 'success',
			self::JOB_RUNNING   => 'info',
			self::JOB_QUEUED    => 'info',
			self::JOB_RETRYING  => 'warning',
			self::JOB_FAILED    => 'error',
			self::JOB_CANCELLED => 'muted',
			self::ACTIVE        => 'success',
			self::PAUSED        => 'warning',
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : 'muted';
	}
}
