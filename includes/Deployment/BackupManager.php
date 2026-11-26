<?php
/**
 * Verified backups and retention.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Backups are stored under uploads/githubsync-backups/<deployment>/<job>.
 * A backup is verified by comparing a SHA-1 map (or counts for very large
 * trees) before any destructive promotion is allowed.
 */
final class BackupManager {

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
	 * Backup root.
	 *
	 * @return string
	 */
	public function root() {
		$uploads = wp_upload_dir( null, false );
		return rtrim( wp_normalize_path( $uploads['basedir'] ), '/' ) . '/githubsync-backups';
	}

	/**
	 * Deterministic backup path for a job.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param int    $job_id        Job ID.
	 * @param string $sha           Commit SHA.
	 * @return string
	 */
	public function path_for( $deployment_id, $job_id, $sha ) {
		$short = '' !== (string) $sha ? substr( (string) $sha, 0, 8 ) : 'unknown';

		return $this->root() . '/' . (int) $deployment_id . '/' . (int) $job_id . '-' . $short;
	}

	/**
	 * Create and verify a backup of the live destination.
	 *
	 * @param string $live          Live destination.
	 * @param int    $deployment_id Deployment ID.
	 * @param int    $job_id        Job ID.
	 * @param string $sha           Commit SHA being deployed.
	 * @return array|\WP_Error { path, files, bytes }
	 */
	public function create( $live, $deployment_id, $job_id, $sha ) {
		if ( ! is_dir( $live ) ) {
			return array(
				'path'  => '',
				'files' => 0,
				'bytes' => 0,
			);
		}

		$target = $this->path_for( $deployment_id, $job_id, $sha );

		if ( ! $this->fs->ensure_dir( $target, 0755 ) ) {
			return new \WP_Error( 'BACKUP_FAILED', __( 'Could not create the backup directory.', 'githubsync' ) );
		}

		Helpers::protect_dir( $this->root() );

		$copied = $this->fs->copy_tree( $live, $target );

		if ( is_wp_error( $copied ) ) {
			return new \WP_Error( 'BACKUP_FAILED', $copied->get_error_message() );
		}

		$verified = $this->verify( $live, $target );

		if ( is_wp_error( $verified ) ) {
			$this->fs->remove_tree( $target, $this->root() );
			return new \WP_Error( 'BACKUP_FAILED', $verified->get_error_message() );
		}

		$manifest = array(
			'deployment_id' => (int) $deployment_id,
			'job_id'        => (int) $job_id,
			'commit'        => (string) $sha,
			'files'         => (int) $copied['files'],
			'bytes'         => (int) $copied['bytes'],
			'created_at'    => Helpers::now(),
			'verified_by'   => $verified,
		);

		$this->fs->write_file( $target . '/.githubsync-backup.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		return array(
			'path'  => $target,
			'files' => (int) $copied['files'],
			'bytes' => (int) $copied['bytes'],
		);
	}

	/**
	 * Verify that a backup matches its source.
	 *
	 * @param string $source Original tree.
	 * @param string $backup Backup tree.
	 * @return string|\WP_Error Method used ("hash" or "count").
	 */
	public function verify( $source, $backup ) {
		$source_hashes = $this->fs->hash_tree( $source );
		$backup_hashes = $this->fs->hash_tree( $backup );

		if ( ! is_wp_error( $source_hashes ) && ! is_wp_error( $backup_hashes ) ) {
			if ( $source_hashes !== $backup_hashes ) {
				return new \WP_Error( 'BACKUP_FAILED', __( 'The backup does not match the source tree.', 'githubsync' ) );
			}
			return 'hash';
		}

		$source_stats = $this->fs->stats( $source );
		$backup_stats = $this->fs->stats( $backup );

		// The manifest file is written after verification, so counts match here.
		if ( $source_stats['files'] !== $backup_stats['files'] || $source_stats['bytes'] !== $backup_stats['bytes'] ) {
			return new \WP_Error( 'BACKUP_FAILED', __( 'The backup file count or size does not match the source.', 'githubsync' ) );
		}

		return 'count';
	}

	/**
	 * Whether a verified backup exists for a job.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param int    $job_id        Job ID.
	 * @param string $sha           Commit SHA.
	 * @return bool
	 */
	public function exists( $deployment_id, $job_id, $sha ) {
		$path = $this->path_for( $deployment_id, $job_id, $sha );
		return is_dir( $path ) && file_exists( $path . '/.githubsync-backup.json' );
	}

	/**
	 * Delete all but the newest N backups for a configuration.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @param int $keep          Number to keep.
	 * @return int Number deleted.
	 */
	public function prune( $deployment_id, $keep = 3 ) {
		$keep  = max( 1, (int) $keep );
		$dir   = $this->root() . '/' . (int) $deployment_id;
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $items ) ) {
			return 0;
		}

		$entries = array();

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$parts = explode( '-', $item, 2 );
			$entries[] = array(
				'name' => $item,
				'job'  => (int) $parts[0],
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return $b['job'] <=> $a['job'];
			}
		);

		$deleted = 0;

		foreach ( array_slice( $entries, $keep ) as $entry ) {
			if ( $this->fs->remove_tree( $dir . '/' . $entry['name'], $this->root() ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Number of verified backups across all deployments.
	 *
	 * @return int
	 */
	public function count() {
		$deployments = @scandir( $this->root() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $deployments ) ) {
			return 0;
		}

		$total = 0;

		foreach ( $deployments as $deployment ) {
			if ( ! ctype_digit( $deployment ) ) {
				continue;
			}

			$dir   = $this->root() . '/' . $deployment;
			$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( ! preg_match( '/^\d+-[a-zA-Z0-9]{8}$/', $item ) ) {
					continue;
				}

				if ( file_exists( $dir . '/' . $item . '/.githubsync-backup.json' ) ) {
					$total++;
				}
			}
		}

		return $total;
	}

	/**
	 * Total bytes used by all backups.
	 *
	 * @return int
	 */
	public function usage() {
		$stats = $this->fs->stats( $this->root() );
		return (int) $stats['bytes'];
	}
}
