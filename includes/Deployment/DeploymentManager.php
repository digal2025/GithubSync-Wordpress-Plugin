<?php
/**
 * The deployment engine.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Domain\DeploymentConfiguration;
use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\Domain\Exceptions\GitHubSyncException;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\Queue\Queue;
use GitHubSync\Security\PathGuard;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Support\Helpers;
use GitHubSync\Support\Logger;
use GitHubSync\Support\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a single deployment job: lock, download, extract, stage,
 * back up, promote, verify and record.
 */
final class DeploymentManager {

	/** @var DeploymentRepository */
	private $deployments;

	/** @var JobRepository */
	private $jobs;

	/** @var Logger */
	private $logger;

	/** @var DeploymentLock */
	private $lock;

	/** @var FilesystemAdapter */
	private $fs;

	/** @var GitHubApiClient */
	private $api;

	/** @var ArchiveDownloader */
	private $archive;

	/** @var SafeArchiveExtractor */
	private $extractor;

	/** @var StagingManager */
	private $staging;

	/** @var BackupManager */
	private $backups;

	/** @var PromotionManager */
	private $promotion;

	/** @var string|null */
	private $active_work = null;

	/** @var string|null */
	private $active_staging = null;

	/** @var string|null */
	private $active_staging_root = null;

	/**
	 * Constructor with optional injection for tests.
	 *
	 * @param array $deps Optional service overrides.
	 */
	public function __construct( array $deps = array() ) {
		$this->fs = isset( $deps['fs'] ) ? $deps['fs'] : new FilesystemAdapter();

		$this->deployments = isset( $deps['deployments'] ) ? $deps['deployments'] : new DeploymentRepository();
		$this->jobs        = isset( $deps['jobs'] ) ? $deps['jobs'] : new JobRepository();
		$this->logger      = isset( $deps['logger'] ) ? $deps['logger'] : new Logger();
		$this->lock        = isset( $deps['lock'] ) ? $deps['lock'] : new DeploymentLock();
		$this->api         = isset( $deps['api'] ) ? $deps['api'] : new GitHubApiClient();
		$this->archive     = isset( $deps['archive'] ) ? $deps['archive'] : new ArchiveDownloader();
		$this->extractor   = isset( $deps['extractor'] ) ? $deps['extractor'] : new SafeArchiveExtractor();
		$this->staging     = isset( $deps['staging'] ) ? $deps['staging'] : new StagingManager( $this->fs );
		$this->backups     = isset( $deps['backups'] ) ? $deps['backups'] : new BackupManager( $this->fs );
		$this->promotion   = isset( $deps['promotion'] ) ? $deps['promotion'] : new PromotionManager( $this->fs );
	}

	/**
	 * Cron/queue entry point.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public static function handle_job( $job_id ) {
		$manager = new self();
		$manager->run_job( (int) $job_id );
	}

	/**
	 * Run a job.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function run_job( $job_id ) {
		$job = $this->jobs->find( $job_id );

		if ( ! $job ) {
			return;
		}

		if ( ! in_array( $job['status'], array( 'queued', 'retrying' ), true ) ) {
			return;
		}

		$deployment_id = (int) $job['deployment_id'];

		if ( ! $this->jobs->claim( $job_id ) ) {
			return;
		}

		$lock_token = $this->lock->acquire( $deployment_id, 1800 );

		if ( ! $lock_token ) {
			$this->jobs->update(
				$job_id,
				array(
					'status'        => 'retrying',
					'error_code'    => ErrorCodes::DEPLOYMENT_LOCKED,
					'error_message' => ErrorCodes::message( ErrorCodes::DEPLOYMENT_LOCKED ),
					'scheduled_at'  => gmdate( 'Y-m-d H:i:s', time() + 120 ),
				)
			);

			Queue::schedule( $job_id, time() + 120 );

			$this->logger->log( 'warning', ErrorCodes::DEPLOYMENT_LOCKED, ErrorCodes::message( ErrorCodes::DEPLOYMENT_LOCKED ), array(), $deployment_id, $job_id );

			return;
		}

		$job = $this->jobs->find( $job_id );

		try {
			$this->execute( $job, $lock_token );
		} catch ( GitHubSyncException $e ) {
			$this->logger->log(
				'error',
				$e->get_error_code(),
				$e->getMessage(),
				$e->get_context(),
				$deployment_id,
				$job_id
			);
			$this->fail( $job, $e->get_error_code(), $e->getMessage() );
		} catch ( Throwable $e ) {
			$this->logger->log(
				'error',
				ErrorCodes::INTERNAL_ERROR,
				$e->getMessage(),
				array( 'exception' => get_class( $e ) ),
				$deployment_id,
				$job_id
			);
			$this->fail( $job, ErrorCodes::INTERNAL_ERROR, ErrorCodes::message( ErrorCodes::INTERNAL_ERROR ) );
		} finally {
			$this->cleanup();
			$this->lock->release( $deployment_id, $lock_token );
		}
	}

	/**
	 * Execute one deployment.
	 *
	 * @param array  $job        Job row.
	 * @param string $lock_token Lock token.
	 * @return void
	 * @throws GitHubSyncException
	 */
	private function execute( array $job, $lock_token ) {
		$job_id = (int) $job['id'];
		$dep_id = (int) $job['deployment_id'];
		$row    = $this->deployments->find( $dep_id );

		if ( ! $row ) {
			throw new GitHubSyncException( ErrorCodes::CONFIG_INVALID, 'The deployment configuration no longer exists.' );
		}

		$config = new DeploymentConfiguration( $row );

		if ( '' === $config->repository() || '' === $config->branch() ) {
			throw new GitHubSyncException( ErrorCodes::CONFIG_INVALID, 'Repository and branch are required.' );
		}

		if ( 'rollback' === $job['trigger_type'] ) {
			$this->execute_rollback( $job, $config, $lock_token );
			return;
		}

		$sha = $this->resolve_sha( $job, $config );

		$destination = $this->resolve_destination( $config );

		if ( ! $this->fs->is_writable( $destination ) ) {
			throw new GitHubSyncException( ErrorCodes::DESTINATION_NOT_WRITABLE );
		}

		if ( DestinationPolicy::has_foreign_content( $destination ) && ! $config->adopt_existing() ) {
			throw new GitHubSyncException(
				ErrorCodes::DESTINATION_NOT_ALLOWED,
				__( 'The destination already contains files GitHubSync did not create. Edit the configuration and confirm adoption.', 'githubsync' )
			);
		}

		$this->active_work = Helpers::work_dir() . 'job-' . $job_id . '-' . substr( (string) $lock_token, 0, 8 );

		if ( ! $this->fs->ensure_dir( $this->active_work, 0700 ) ) {
			throw new GitHubSyncException( ErrorCodes::INTERNAL_ERROR, 'Could not create a working directory.' );
		}

		$this->logger->log(
			'info',
			'ARCHIVE_DOWNLOAD_STARTED',
			__( 'Downloading the commit archive.', 'githubsync' ),
			array(
				'repository' => $config->repository(),
				'commit'     => $sha,
			),
			$dep_id,
			$job_id
		);

		$archive_path = $this->active_work . '/archive.zip';

		$this->archive->fetch(
			$this->api,
			$config->repository(),
			$sha,
			$archive_path,
			(int) Settings::get( 'max_archive_mb', 100 ) * MB_IN_BYTES
		);

		$this->lock->heartbeat( $dep_id, $lock_token );

		$extracted = $this->active_work . '/extracted';

		if ( ! $this->fs->ensure_dir( $extracted, 0700 ) ) {
			throw new GitHubSyncException( ErrorCodes::INTERNAL_ERROR, 'Could not create the extraction directory.' );
		}

		$meta = $this->extractor->extract( $archive_path, $extracted, Settings::archive_limits() );

		$source = $extracted;

		if ( '' !== $config->source_path() ) {
			$joined = PathGuard::join( $extracted, $config->source_path() );

			if ( false === $joined || ! is_dir( $joined ) || ! PathGuard::resolves_inside( $joined, $extracted ) ) {
				throw new GitHubSyncException(
					ErrorCodes::SOURCE_PATH_MISSING,
					sprintf(
						/* translators: %s: source path. */
						__( 'The configured source directory "%s" was not found in the archive.', 'githubsync' ),
						$config->source_path()
					)
				);
			}

			$source = $joined;
		}

		$staging = $this->staging->create( $destination );

		if ( is_wp_error( $staging ) ) {
			throw new GitHubSyncException( $staging->get_error_code(), $staging->get_error_message() );
		}

		$this->active_staging      = $staging;
		$this->active_staging_root = dirname( $destination );

		$built = $this->staging->build( $source, $staging, $config->file_filter() );

		if ( is_wp_error( $built ) ) {
			throw new GitHubSyncException( $built->get_error_code(), $built->get_error_message() );
		}

		if ( 0 === (int) $built['files'] ) {
			throw new GitHubSyncException(
				ErrorCodes::ARCHIVE_INVALID,
				__( 'No deployable files were found after applying the exclusion rules.', 'githubsync' )
			);
		}

		$preserved = $this->staging->preserve_excluded( $destination, $staging, $config->file_filter() );

		$this->staging->write_marker( $staging, $config, $sha );

		$new_stats = $this->fs->stats( $staging );
		$free      = $this->fs->free_space( dirname( $destination ) );

		if ( $free >= 0 && $free < ( (int) $new_stats['bytes'] * 2 + 10 * MB_IN_BYTES ) ) {
			throw new GitHubSyncException( ErrorCodes::DISK_SPACE_LOW );
		}

		$old_hashes = $this->safe_hashes( $destination );
		$backup     = array(
			'path'  => '',
			'files' => 0,
			'bytes' => 0,
		);

		if ( is_dir( $destination ) ) {
			$backup = $this->backups->create( $destination, $dep_id, $job_id, $sha );

			if ( is_wp_error( $backup ) ) {
				throw new GitHubSyncException( ErrorCodes::BACKUP_FAILED, $backup->get_error_message() );
			}

			$this->logger->log(
				'info',
				'BACKUP_CREATED',
				__( 'A verified backup of the current destination was created.', 'githubsync' ),
				array(
					'files' => (int) $backup['files'],
					'bytes' => (int) $backup['bytes'],
				),
				$dep_id,
				$job_id
			);
		}

		$this->lock->heartbeat( $dep_id, $lock_token );

		$promoted = $this->promotion->promote( $staging, $destination );

		if ( is_wp_error( $promoted ) ) {
			if ( ! empty( $backup['path'] ) ) {
				$restored = $this->promotion->restore_from_backup( $backup['path'], $destination );

				if ( is_wp_error( $restored ) ) {
					$this->logger->log(
						'error',
						ErrorCodes::ROLLBACK_FAILED,
						__( 'Promotion failed and the previous files could not be restored. Backups were preserved.', 'githubsync' ),
						array( 'backup' => $backup['path'] ),
						$dep_id,
						$job_id
					);

					throw new GitHubSyncException( ErrorCodes::ROLLBACK_FAILED, $restored->get_error_message() );
				}
			}

			throw new GitHubSyncException( ErrorCodes::PROMOTION_FAILED, $promoted->get_error_message() );
		}

		$this->active_staging = null;

		$after = $this->fs->stats( $destination );

		if ( (int) $after['files'] < 1 ) {
			throw new GitHubSyncException( ErrorCodes::PROMOTION_FAILED, __( 'The destination is empty after promotion.', 'githubsync' ) );
		}

		$diff = $this->diff( $old_hashes, $this->safe_hashes( $destination ) );

		$this->jobs->update(
			$job_id,
			array(
				'status'        => 'succeeded',
				'finished_at'   => Helpers::now(),
				'error_code'    => null,
				'error_message' => null,
				'files_added'   => (int) $diff['added'],
				'files_updated' => (int) $diff['updated'],
				'files_removed' => (int) $diff['removed'],
				'files_total'   => (int) $after['files'],
			)
		);

		$this->deployments->record_success( $dep_id, $sha, $job_id );
		$this->backups->prune( $dep_id, (int) Settings::get( 'backup_retention', 3 ) );

		$this->logger->log(
			'info',
			'DEPLOYMENT_SUCCEEDED',
			__( 'Deployment completed and verified.', 'githubsync' ),
			array(
				'commit'      => $sha,
				'files'       => (int) $after['files'],
				'bytes'       => (int) $after['bytes'],
				'added'       => (int) $diff['added'],
				'updated'     => (int) $diff['updated'],
				'removed'     => (int) $diff['removed'],
				'preserved'   => (int) $preserved,
				'extracted'   => (int) $meta['files'],
				'destination' => $destination,
			),
			$dep_id,
			$job_id
		);
	}

	/**
	 * Restore a previous successful deployment from its verified backup.
	 *
	 * @param array                   $job        Job row.
	 * @param DeploymentConfiguration $config     Configuration.
	 * @param string                  $lock_token Lock token.
	 * @return void
	 * @throws GitHubSyncException
	 */
	private function execute_rollback( array $job, DeploymentConfiguration $config, $lock_token ) {
		$job_id    = (int) $job['id'];
		$dep_id    = (int) $job['deployment_id'];
		$source_id = (int) $job['rollback_of'];
		$source    = $this->jobs->find( $source_id );

		if ( ! $source || 'succeeded' !== $source['status'] ) {
			throw new GitHubSyncException( ErrorCodes::ROLLBACK_FAILED, __( 'The deployment being rolled back to is not available.', 'githubsync' ) );
		}

		$sha         = (string) $source['commit_sha'];
		$backup_path = $this->backups->path_for( $dep_id, $source_id, $sha );

		if ( ! is_dir( $backup_path ) || ! file_exists( $backup_path . '/.githubsync-backup.json' ) ) {
			throw new GitHubSyncException( ErrorCodes::ROLLBACK_FAILED, __( 'The backup for that deployment is missing.', 'githubsync' ) );
		}

		$destination = $this->resolve_destination( $config );

		if ( ! $this->fs->is_writable( $destination ) ) {
			throw new GitHubSyncException( ErrorCodes::DESTINATION_NOT_WRITABLE );
		}

		$staging = $this->staging->create( $destination );

		if ( is_wp_error( $staging ) ) {
			throw new GitHubSyncException( $staging->get_error_code(), $staging->get_error_message() );
		}

		$this->active_staging      = $staging;
		$this->active_staging_root = dirname( $destination );

		$copied = $this->fs->copy_tree( $backup_path, $staging );

		if ( is_wp_error( $copied ) ) {
			throw new GitHubSyncException( ErrorCodes::ROLLBACK_FAILED, $copied->get_error_message() );
		}

		// The backup manifest is metadata, not site content.
		$manifest = $staging . '/.githubsync-backup.json';
		if ( file_exists( $manifest ) ) {
			@unlink( $manifest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$stats = $this->fs->stats( $staging );

		if ( (int) $stats['files'] < 1 ) {
			throw new GitHubSyncException( ErrorCodes::ROLLBACK_FAILED, __( 'The backup contains no files.', 'githubsync' ) );
		}

		// Re-assert ownership so the next automatic deployment does not mistake
		// the restored tree for foreign content.
		$this->staging->write_marker( $staging, $config, $sha );

		// Preserve the current live state before replacing it, so a rollback is
		// itself reversible.
		if ( is_dir( $destination ) ) {
			$safety = $this->backups->create( $destination, $dep_id, $job_id, $sha );

			if ( is_wp_error( $safety ) ) {
				throw new GitHubSyncException( ErrorCodes::BACKUP_FAILED, $safety->get_error_message() );
			}
		}

		$this->lock->heartbeat( $dep_id, $lock_token );

		$promoted = $this->promotion->promote( $staging, $destination );

		if ( is_wp_error( $promoted ) ) {
			throw new GitHubSyncException( ErrorCodes::ROLLBACK_FAILED, $promoted->get_error_message() );
		}

		$this->active_staging = null;

		$this->jobs->update(
			$job_id,
			array(
				'status'      => 'succeeded',
				'finished_at' => Helpers::now(),
				'files_total' => (int) $stats['files'],
			)
		);

		$this->deployments->record_success( $dep_id, $sha, $job_id );

		$this->logger->log(
			'info',
			'ROLLBACK_SUCCEEDED',
			__( 'Rollback completed.', 'githubsync' ),
			array(
				'commit' => $sha,
				'files'  => (int) $stats['files'],
			),
			$dep_id,
			$job_id
		);
	}

	/**
	 * Resolve and validate the destination for a configuration.
	 *
	 * @param DeploymentConfiguration $config Configuration.
	 * @return string
	 * @throws GitHubSyncException
	 */
	private function resolve_destination( DeploymentConfiguration $config ) {
		$resolved = DestinationPolicy::resolve( $config->type(), $config->destination_slug() );

		if ( is_wp_error( $resolved ) ) {
			throw new GitHubSyncException( ErrorCodes::DESTINATION_NOT_ALLOWED, $resolved->get_error_message() );
		}

		if ( $config->destination_path() !== $resolved['path'] ) {
			$this->deployments->update( $config->id(), array( 'destination_path' => $resolved['path'] ) );
		}

		return $resolved['path'];
	}

	/**
	 * Resolve the immutable commit SHA for a job.
	 *
	 * @param array                   $job    Job row.
	 * @param DeploymentConfiguration $config Configuration.
	 * @return string
	 * @throws GitHubSyncException
	 */
	private function resolve_sha( array $job, DeploymentConfiguration $config ) {
		$sha = (string) $job['commit_sha'];

		if ( preg_match( '/^[a-f0-9]{40,64}$/i', $sha ) ) {
			return strtolower( $sha );
		}

		$resolved = $this->api->branch_head_sha( $config->repository(), $config->branch() );

		if ( is_wp_error( $resolved ) ) {
			throw new GitHubSyncException( $resolved->get_error_code(), $resolved->get_error_message() );
		}

		$this->jobs->update( (int) $job['id'], array( 'commit_sha' => $resolved ) );

		return $resolved;
	}

	/**
	 * Record a failure, retrying only when the code is retryable.
	 *
	 * @param array  $job     Job row.
	 * @param string $code    Error code.
	 * @param string $message Safe message.
	 * @return void
	 */
	private function fail( array $job, $code, $message ) {
		$job_id  = (int) $job['id'];
		$attempt = (int) $job['attempt_count'] + 1;
		$max     = max( 1, (int) $job['max_attempts'] );
		$message = substr( (string) $message, 0, 2000 );

		if ( ErrorCodes::is_retryable( $code ) && $attempt < $max ) {
			$delay = (int) min( 300, 30 * pow( 2, $attempt - 1 ) ) + random_int( 0, 15 );

			$this->jobs->update(
				$job_id,
				array(
					'status'        => 'retrying',
					'attempt_count' => $attempt,
					'error_code'    => $code,
					'error_message' => $message,
					'scheduled_at'  => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				)
			);

			Queue::schedule( $job_id, time() + $delay );

			return;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'        => 'failed',
				'attempt_count' => $attempt,
				'finished_at'   => Helpers::now(),
				'error_code'    => $code,
				'error_message' => $message,
			)
		);

		Helpers::add_notice(
			'error',
			sprintf(
				/* translators: 1: job id, 2: error message. */
				__( 'Deployment job #%1$d failed: %2$s', 'githubsync' ),
				$job_id,
				$message
			)
		);
	}

	/**
	 * Remove working and staging directories.
	 *
	 * @return void
	 */
	private function cleanup() {
		if ( is_string( $this->active_staging ) && is_dir( $this->active_staging ) ) {
			$root = is_string( $this->active_staging_root ) ? $this->active_staging_root : dirname( $this->active_staging );
			$this->fs->remove_tree( $this->active_staging, $root );
		}

		if ( is_string( $this->active_work ) && is_dir( $this->active_work ) ) {
			$this->fs->remove_tree( $this->active_work, Helpers::work_dir() );
		}

		$this->active_staging      = null;
		$this->active_staging_root = null;
		$this->active_work         = null;
	}

	/**
	 * Hash a tree, returning null when it is too large to hash.
	 *
	 * @param string $dir Directory.
	 * @return array|null
	 */
	private function safe_hashes( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$hashes = $this->fs->hash_tree( $dir );

		if ( is_wp_error( $hashes ) ) {
			return null;
		}

		// The ownership marker is rewritten on every deployment, so counting it
		// as a changed file would inflate every diff.
		unset( $hashes['.githubsync-owner.json'] );

		return $hashes;
	}

	/**
	 * Compute added/updated/removed counts.
	 *
	 * @param array|null $old Old hashes.
	 * @param array|null $new New hashes.
	 * @return array
	 */
	private function diff( $old, $new ) {
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return array(
				'added'   => is_array( $new ) ? count( $new ) : 0,
				'updated' => 0,
				'removed' => 0,
			);
		}

		$added   = 0;
		$updated = 0;
		$removed = 0;

		foreach ( $new as $path => $hash ) {
			if ( ! isset( $old[ $path ] ) ) {
				$added++;
			} elseif ( $old[ $path ] !== $hash ) {
				$updated++;
			}
		}

		foreach ( $old as $path => $hash ) {
			if ( ! isset( $new[ $path ] ) ) {
				$removed++;
			}
		}

		return array(
			'added'   => $added,
			'updated' => $updated,
			'removed' => $removed,
		);
	}

	/**
	 * Queue a manual deployment at the current branch head.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param string $sha           Optional explicit SHA.
	 * @return int|\WP_Error Job ID.
	 */
	public function deploy_now( $deployment_id, $sha = '' ) {
		$row = $this->deployments->find( $deployment_id );

		if ( ! $row ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'Configuration not found.', 'githubsync' ) );
		}

		$config = new DeploymentConfiguration( $row );

		if ( ! $config->manual_deploy() ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'Manual deployment is disabled for this configuration.', 'githubsync' ) );
		}

		if ( '' === $sha ) {
			$sha = $this->api->branch_head_sha( $config->repository(), $config->branch() );

			if ( is_wp_error( $sha ) ) {
				return $sha;
			}
		}

		$queue = new Queue( $this->jobs );

		return $queue->enqueue( $deployment_id, (string) $sha, 'manual', (int) Settings::get( 'max_attempts', 3 ) );
	}

	/**
	 * Queue a rollback to a previous successful job.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @param int $source_job_id Job to restore.
	 * @return int|\WP_Error Job ID.
	 */
	public function rollback( $deployment_id, $source_job_id ) {
		$source = $this->jobs->find( $source_job_id );

		if ( ! $source || (int) $source['deployment_id'] !== (int) $deployment_id ) {
			return new \WP_Error( ErrorCodes::ROLLBACK_FAILED, __( 'That deployment does not belong to this configuration.', 'githubsync' ) );
		}

		if ( 'succeeded' !== $source['status'] ) {
			return new \WP_Error( ErrorCodes::ROLLBACK_FAILED, __( 'Only successful deployments can be restored.', 'githubsync' ) );
		}

		if ( ! $this->backups->exists( $deployment_id, $source_job_id, (string) $source['commit_sha'] ) ) {
			return new \WP_Error( ErrorCodes::ROLLBACK_FAILED, __( 'No verified backup exists for that deployment.', 'githubsync' ) );
		}

		$queue = new Queue( $this->jobs );

		return $queue->enqueue( $deployment_id, (string) $source['commit_sha'], 'rollback', 1, (int) $source_job_id );
	}

	/**
	 * Requeue a failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return true|\WP_Error
	 */
	public function retry( $job_id ) {
		$job = $this->jobs->find( $job_id );

		if ( ! $job ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'Job not found.', 'githubsync' ) );
		}

		if ( ! in_array( $job['status'], array( 'failed', 'cancelled' ), true ) ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'Only failed or cancelled jobs can be retried.', 'githubsync' ) );
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'        => 'queued',
				'attempt_count' => 0,
				'scheduled_at'  => Helpers::now(),
				'started_at'    => null,
				'finished_at'   => null,
				'error_code'    => null,
				'error_message' => null,
			)
		);

		Queue::schedule( $job_id, time() );

		return true;
	}
}
