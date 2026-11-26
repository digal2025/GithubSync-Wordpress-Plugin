<?php
/**
 * Stable error codes and their retry classification.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Central catalogue of error codes from specification section 9.
 */
final class ErrorCodes {

	public const AUTH_INVALID            = 'AUTH_INVALID';
	public const AUTH_PERMISSION_DENIED  = 'AUTH_PERMISSION_DENIED';
	public const GITHUB_RATE_LIMIT       = 'GITHUB_RATE_LIMIT';
	public const GITHUB_NETWORK_ERROR    = 'GITHUB_NETWORK_ERROR';
	public const REPOSITORY_NOT_FOUND    = 'REPOSITORY_NOT_FOUND';
	public const BRANCH_NOT_FOUND        = 'BRANCH_NOT_FOUND';
	public const WEBHOOK_SIGNATURE_INVALID = 'WEBHOOK_SIGNATURE_INVALID';
	public const WEBHOOK_DUPLICATE       = 'WEBHOOK_DUPLICATE';
	public const ARCHIVE_TOO_LARGE       = 'ARCHIVE_TOO_LARGE';
	public const ARCHIVE_INVALID         = 'ARCHIVE_INVALID';
	public const ARCHIVE_PATH_INVALID    = 'ARCHIVE_PATH_INVALID';
	public const SOURCE_PATH_MISSING     = 'SOURCE_PATH_MISSING';
	public const DESTINATION_NOT_ALLOWED = 'DESTINATION_NOT_ALLOWED';
	public const DESTINATION_NOT_WRITABLE = 'DESTINATION_NOT_WRITABLE';
	public const DISK_SPACE_LOW          = 'DISK_SPACE_LOW';
	public const DEPLOYMENT_LOCKED       = 'DEPLOYMENT_LOCKED';
	public const BACKUP_FAILED           = 'BACKUP_FAILED';
	public const PROMOTION_FAILED        = 'PROMOTION_FAILED';
	public const ROLLBACK_FAILED         = 'ROLLBACK_FAILED';
	public const QUEUE_UNAVAILABLE       = 'QUEUE_UNAVAILABLE';
	public const CONFIG_INVALID          = 'CONFIG_INVALID';
	public const ENCRYPTION_UNAVAILABLE  = 'ENCRYPTION_UNAVAILABLE';
	public const LIMIT_EXCEEDED          = 'LIMIT_EXCEEDED';
	public const UPDATE_NOT_CONFIGURED   = 'UPDATE_NOT_CONFIGURED';
	public const UPDATE_MANIFEST_INVALID = 'UPDATE_MANIFEST_INVALID';
	public const UPDATE_PACKAGE_INVALID  = 'UPDATE_PACKAGE_INVALID';
	public const INTERNAL_ERROR          = 'INTERNAL_ERROR';

	/**
	 * Codes that may be retried with backoff. Security and validation
	 * failures are intentionally absent: retrying them cannot succeed.
	 *
	 * @return string[]
	 */
	public static function retryable() {
		return array(
			self::GITHUB_RATE_LIMIT,
			self::GITHUB_NETWORK_ERROR,
			self::DEPLOYMENT_LOCKED,
			self::QUEUE_UNAVAILABLE,
		);
	}

	/**
	 * Whether a code is retryable.
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	public static function is_retryable( $code ) {
		return in_array( $code, self::retryable(), true );
	}

	/**
	 * Safe, administrator-readable message for a code. Never contains secrets.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function message( $code ) {
		$messages = array(
			self::AUTH_INVALID             => __( 'GitHub credentials are invalid or have been revoked. Reconnect GitHub.', 'githubsync' ),
			self::AUTH_PERMISSION_DENIED   => __( 'The connected GitHub identity lacks permission for this repository.', 'githubsync' ),
			self::GITHUB_RATE_LIMIT        => __( 'GitHub API rate limit reached. The deployment will retry later.', 'githubsync' ),
			self::GITHUB_NETWORK_ERROR     => __( 'Temporary network error while contacting GitHub.', 'githubsync' ),
			self::REPOSITORY_NOT_FOUND     => __( 'The configured repository could not be found or is not accessible.', 'githubsync' ),
			self::BRANCH_NOT_FOUND         => __( 'The configured branch does not exist in the repository.', 'githubsync' ),
			self::WEBHOOK_SIGNATURE_INVALID => __( 'Webhook signature verification failed.', 'githubsync' ),
			self::WEBHOOK_DUPLICATE        => __( 'This webhook delivery has already been processed.', 'githubsync' ),
			self::ARCHIVE_TOO_LARGE        => __( 'The repository archive exceeds the configured size limit.', 'githubsync' ),
			self::ARCHIVE_INVALID          => __( 'The repository archive is invalid or unreadable.', 'githubsync' ),
			self::ARCHIVE_PATH_INVALID     => __( 'The repository archive contains an unsafe path or link and was rejected.', 'githubsync' ),
			self::SOURCE_PATH_MISSING      => __( 'The configured source directory does not exist in the repository.', 'githubsync' ),
			self::DESTINATION_NOT_ALLOWED  => __( 'The destination is outside the permitted deployment roots.', 'githubsync' ),
			self::DESTINATION_NOT_WRITABLE => __( 'The destination directory is not writable by WordPress.', 'githubsync' ),
			self::DISK_SPACE_LOW           => __( 'There is not enough free disk space to deploy safely.', 'githubsync' ),
			self::DEPLOYMENT_LOCKED        => __( 'Another deployment for this configuration is already running.', 'githubsync' ),
			self::BACKUP_FAILED            => __( 'A verified backup of the current destination could not be created.', 'githubsync' ),
			self::PROMOTION_FAILED         => __( 'New files could not be promoted into the destination. Manual recovery may be required.', 'githubsync' ),
			self::ROLLBACK_FAILED          => __( 'The rollback failed. Backups were preserved for manual recovery.', 'githubsync' ),
			self::QUEUE_UNAVAILABLE        => __( 'The background queue is unavailable. Check WP-Cron.', 'githubsync' ),
			self::CONFIG_INVALID           => __( 'The deployment configuration is invalid.', 'githubsync' ),
			self::ENCRYPTION_UNAVAILABLE   => __( 'Secure credential encryption is not configured.', 'githubsync' ),
			self::LIMIT_EXCEEDED           => __( 'A configured file count or size limit was exceeded.', 'githubsync' ),
			self::UPDATE_NOT_CONFIGURED    => __( 'No plugin update feed is configured.', 'githubsync' ),
			self::UPDATE_MANIFEST_INVALID  => __( 'The plugin update manifest is invalid or could not be read.', 'githubsync' ),
			self::UPDATE_PACKAGE_INVALID   => __( 'The plugin update package failed its checksum verification.', 'githubsync' ),
			self::INTERNAL_ERROR           => __( 'An unexpected internal error occurred.', 'githubsync' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Unknown error.', 'githubsync' );
	}
}
