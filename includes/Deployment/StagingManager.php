<?php
/**
 * Builds the deployment tree in a staging directory beside the destination.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Domain\DeploymentConfiguration;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\Security\FileFilter;
use GitHubSync\Security\PathGuard;
use GitHubSync\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Staging lives in the destination's parent directory so the final rename is
 * guaranteed to be on the same filesystem.
 */
final class StagingManager {

	/**
	 * Filesystem.
	 *
	 * @var FilesystemAdapter
	 */
	private $fs;

	/**
	 * Constructor.
	 *
	 * @param FilesystemAdapter $fs Filesystem adapter.
	 */
	public function __construct( FilesystemAdapter $fs ) {
		$this->fs = $fs;
	}

	/**
	 * Create a unique staging directory next to the destination.
	 *
	 * @param string $destination Absolute destination path.
	 * @return string|\WP_Error
	 */
	public function create( $destination ) {
		$parent = dirname( $destination );

		if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			return new \WP_Error( 'DESTINATION_NOT_WRITABLE', __( 'The destination parent directory is not writable.', 'githubsync' ) );
		}

		$staging = $parent . '/.githubsync-staging-' . Helpers::random_token( 8 );

		if ( ! $this->fs->ensure_dir( $staging, 0755 ) ) {
			return new \WP_Error( 'DESTINATION_NOT_WRITABLE', __( 'Could not create a staging directory.', 'githubsync' ) );
		}

		return $staging;
	}

	/**
	 * Copy deployable files from the extracted source into staging, applying
	 * the exclusion policy.
	 *
	 * @param string     $source  Extracted source directory.
	 * @param string     $staging Staging directory.
	 * @param FileFilter $filter  File filter.
	 * @return array|\WP_Error { files, bytes, skipped }
	 */
	public function build( $source, $staging, FileFilter $filter ) {
		$files = $this->fs->files( $source );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$copied  = 0;
		$skipped = 0;
		$bytes   = 0;

		foreach ( $files as $relative ) {
			if ( $filter->is_excluded( $relative ) ) {
				$skipped++;
				continue;
			}

			$from = rtrim( $source, '/' ) . '/' . $relative;
			$to   = PathGuard::join( $staging, $relative );

			if ( false === $to ) {
				return new \WP_Error( 'ARCHIVE_PATH_INVALID', __( 'An extracted path was unsafe.', 'githubsync' ) );
			}

			if ( ! $this->fs->copy_file( $from, $to ) ) {
				return new \WP_Error(
					'INTERNAL_ERROR',
					sprintf(
						/* translators: %s: relative path. */
						__( 'Could not stage %s.', 'githubsync' ),
						$relative
					)
				);
			}

			$copied++;
			$bytes += (int) filesize( $to );
		}

		return array(
			'files'   => $copied,
			'bytes'   => $bytes,
			'skipped' => $skipped,
		);
	}

	/**
	 * Copy excluded/protected files that already exist in the live
	 * destination into staging so promotion never deletes them.
	 *
	 * @param string     $destination Live destination.
	 * @param string     $staging     Staging directory.
	 * @param FileFilter $filter      File filter.
	 * @return int Number preserved.
	 */
	public function preserve_excluded( $destination, $staging, FileFilter $filter ) {
		if ( ! is_dir( $destination ) ) {
			return 0;
		}

		$files = $this->fs->files( $destination );

		if ( is_wp_error( $files ) ) {
			return 0;
		}

		$preserved = 0;

		foreach ( $files as $relative ) {
			if ( ! $filter->is_excluded( $relative ) ) {
				continue;
			}

			$target = PathGuard::join( $staging, $relative );

			if ( false === $target || file_exists( $target ) ) {
				continue;
			}

			if ( $this->fs->copy_file( rtrim( $destination, '/' ) . '/' . $relative, $target ) ) {
				$preserved++;
			}
		}

		return $preserved;
	}

	/**
	 * Write an ownership marker so later runs can tell this directory is
	 * managed by GitHubSync.
	 *
	 * @param string                  $staging Staging directory.
	 * @param DeploymentConfiguration $config  Configuration.
	 * @param string                  $sha     Commit SHA.
	 * @return void
	 */
	public function write_marker( $staging, DeploymentConfiguration $config, $sha ) {
		$marker = array(
			'plugin'        => 'githubsync',
			'deployment_id' => $config->id(),
			'repository'    => $config->repository(),
			'branch'        => $config->branch(),
			'commit'        => $sha,
			'written_at'    => Helpers::now(),
		);

		$this->fs->write_file(
			rtrim( $staging, '/' ) . '/.githubsync-owner.json',
			(string) wp_json_encode( $marker, JSON_PRETTY_PRINT )
		);
	}
}
