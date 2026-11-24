<?php
/**
 * Staged promotion and recovery.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Promotes a staging tree into the live destination using same-filesystem
 * renames, with automatic restore on failure.
 */
final class PromotionManager {

	/**
	 * Filesystem.
	 *
	 * @var FilesystemAdapter
	 */
	private $fs;

	/**
	 * Constructor.
	 *
	 * @param FilesystemAdapter $fs Filesystem.
	 */
	public function __construct( FilesystemAdapter $fs ) {
		$this->fs = $fs;
	}

	/**
	 * Promote staging to destination.
	 *
	 * @param string $staging     Staging directory (same parent as destination).
	 * @param string $destination Destination.
	 * @return true|\WP_Error
	 */
	public function promote( $staging, $destination ) {
		$parent = dirname( $destination );

		if ( ! is_dir( $staging ) ) {
			return new \WP_Error( 'PROMOTION_FAILED', __( 'The staging directory is missing.', 'githubsync' ) );
		}

		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			return new \WP_Error( 'DESTINATION_NOT_WRITABLE', __( 'The destination parent is not writable.', 'githubsync' ) );
		}

		if ( is_link( $destination ) ) {
			return new \WP_Error( 'DESTINATION_NOT_ALLOWED', __( 'The destination is a symlink.', 'githubsync' ) );
		}

		$previous = '';

		if ( file_exists( $destination ) ) {
			$previous = $parent . '/.githubsync-old-' . Helpers::random_token( 6 );

			if ( ! $this->fs->move( $destination, $previous ) ) {
				return new \WP_Error( 'PROMOTION_FAILED', __( 'Could not move the current destination aside.', 'githubsync' ) );
			}
		}

		if ( ! $this->fs->move( $staging, $destination ) ) {
			if ( '' !== $previous ) {
				$this->fs->move( $previous, $destination );
			}

			return new \WP_Error( 'PROMOTION_FAILED', __( 'Could not move the staged files into place.', 'githubsync' ) );
		}

		if ( '' !== $previous ) {
			$this->fs->remove_tree( $previous, $parent );
		}

		return true;
	}

	/**
	 * Restore a backup over a destination, staging the copy first.
	 *
	 * @param string $backup      Verified backup directory.
	 * @param string $destination Destination.
	 * @return true|\WP_Error
	 */
	public function restore_from_backup( $backup, $destination ) {
		if ( ! is_dir( $backup ) ) {
			return new \WP_Error( 'ROLLBACK_FAILED', __( 'The backup directory is missing.', 'githubsync' ) );
		}

		$parent = dirname( $destination );

		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			return new \WP_Error( 'DESTINATION_NOT_WRITABLE', __( 'The destination parent is not writable.', 'githubsync' ) );
		}

		$temp = $parent . '/.githubsync-restore-' . Helpers::random_token( 6 );

		$copied = $this->fs->copy_tree( $backup, $temp );

		if ( is_wp_error( $copied ) ) {
			$this->fs->remove_tree( $temp, $parent );
			return new \WP_Error( 'ROLLBACK_FAILED', $copied->get_error_message() );
		}

		$failed = $parent . '/.githubsync-failed-' . Helpers::random_token( 6 );

		if ( file_exists( $destination ) && ! $this->fs->move( $destination, $failed ) ) {
			$this->fs->remove_tree( $temp, $parent );
			return new \WP_Error( 'ROLLBACK_FAILED', __( 'Could not move the current files aside.', 'githubsync' ) );
		}

		if ( ! $this->fs->move( $temp, $destination ) ) {
			if ( file_exists( $failed ) ) {
				$this->fs->move( $failed, $destination );
			}
			$this->fs->remove_tree( $temp, $parent );
			return new \WP_Error( 'ROLLBACK_FAILED', __( 'Could not restore the backup.', 'githubsync' ) );
		}

		if ( file_exists( $failed ) ) {
			$this->fs->remove_tree( $failed, $parent );
		}

		return true;
	}
}
