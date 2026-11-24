<?php
/**
 * Suggest deployment form values from GitHub repository metadata.
 *
 * GitHub knows the repository name, the default branch and whether a branch
 * is the default one. It cannot know which WordPress deployment type the site
 * owner intends, which subfolder holds the code, or what should be excluded,
 * so only the fields it can actually answer are filled in. Every suggestion
 * carries the reason it was made, so the screen can say where the value came
 * from instead of looking like a guess.
 *
 * @package GitHubSync
 */

namespace GitHubSync\GitHub;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\Security\InputValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Builds prefill values from repository metadata and branches already loaded.
 */
final class RepositoryPrefill {

	/**
	 * Cache key for repository metadata.
	 *
	 * @param string $full_name owner/repo.
	 * @return string
	 */
	public static function cache_key( $full_name ) {
		return 'githubsync_repo_meta_' . md5( strtolower( trim( (string) $full_name ) ) );
	}

	/**
	 * Repository metadata, from a five-minute cache when possible.
	 *
	 * Only successful lookups are cached, so a repository that starts out
	 * unreadable is retried on the next render rather than staying broken.
	 *
	 * @param GitHubApiClient|null $client    Client to use.
	 * @param string               $full_name owner/repo.
	 * @return array|\WP_Error
	 */
	public static function metadata( ?GitHubApiClient $client, $full_name ) {
		$full_name = (string) $full_name;
		$cache_key = self::cache_key( $full_name );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$client = $client ? $client : new GitHubApiClient();
		$result = $client->repository( $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Names of the destinations that already exist for a type.
	 *
	 * Used to tell the user that the suggested folder is already deployed, so
	 * ticking "adopt" is a choice they make knowingly.
	 *
	 * @param string $type Deployment type.
	 * @param int    $skip_id Deployment id to ignore (the one being edited).
	 * @return string[]
	 */
	public static function deployed_slugs( $type, $skip_id = 0 ) {
		$slugs = array();

		foreach ( ( new DeploymentRepository() )->all() as $row ) {
			if ( (int) $row['id'] === (int) $skip_id ) {
				continue;
			}
			if ( (string) $row['deployment_type'] !== (string) $type ) {
				continue;
			}
			$slugs[] = (string) $row['destination_slug'];
		}

		return $slugs;
	}

	/**
	 * Whether a slug already exists on disk for a type.
	 *
	 * @param string $type Deployment type.
	 * @param string $slug Destination folder name.
	 * @return bool
	 */
	public static function slug_exists_on_disk( $type, $slug ) {
		$resolved = DestinationPolicy::resolve( $type, (string) $slug );

		if ( is_wp_error( $resolved ) || ! isset( $resolved['path'] ) ) {
			return false;
		}

		return is_dir( (string) $resolved['path'] );
	}

	/**
	 * Suggest values for a new deployment.
	 *
	 * Fields the user already typed are never overwritten: a suggestion is
	 * only produced when the submitted value is empty.
	 *
	 * @param array|null $meta    Repository metadata from {@see metadata()}, or null.
	 * @param array      $branches Branches already loaded for the repository.
	 * @param array      $current  Current form values: branch, source_path, deployment_type, destination_slug.
	 * @param int        $skip_id  Deployment id to ignore when checking the current slug.
	 * @return array {
	 *     @type array $fields Slug => array( 'value' => string, 'reason' => string ).
	 *     @type bool  $branch_is_default Whether the branch equals the default branch.
	 * }
	 */
	public static function suggest( $meta, array $branches, array $current, $skip_id = 0 ) {
		$fields      = array();
		$default     = is_array( $meta ) && ! empty( $meta['default_branch'] ) ? (string) $meta['default_branch'] : '';
		$branch      = isset( $current['branch'] ) ? (string) $current['branch'] : '';
		$is_default  = '' !== $default && $branch === $default;
		$source      = isset( $current['source_path'] ) ? (string) $current['source_path'] : '';
		$type        = isset( $current['deployment_type'] ) ? (string) $current['deployment_type'] : DestinationPolicy::TYPE_PLUGIN;
		$slug        = isset( $current['destination_slug'] ) ? (string) $current['destination_slug'] : '';
		$branch_list = array();

		foreach ( $branches as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$branch_list[] = (string) $item['name'];
			}
		}

		if ( '' === $branch && '' !== $default && ( empty( $branch_list ) || in_array( $default, $branch_list, true ) ) ) {
			$fields['branch'] = array(
				'value'  => $default,
				/* translators: %s: branch name. */
				'reason' => sprintf( __( 'default branch on GitHub (%s)', 'githubsync' ), $default ),
			);
		}

		if ( '' === $source && $is_default && is_array( $meta ) && ! empty( $meta['full_name'] ) ) {
			$fields['source_path'] = array(
				'value'  => '',
				'reason' => __( 'files are read from the top of the repository; empty means repository root', 'githubsync' ),
			);
		}

		if ( '' === $slug && is_array( $meta ) && ! empty( $meta['full_name'] ) ) {
			$parts = explode( '/', (string) $meta['full_name'] );
			$name  = strtolower( end( $parts ) );
			$valid = InputValidator::slug( $name );

			if ( false !== $valid && strtolower( (string) $valid ) !== 'githubsync' ) {
				$reason = __( 'repository name', 'githubsync' );

				if ( self::slug_exists_on_disk( $type, $valid ) ) {
					$reason = __( 'repository name; that folder already exists, so adoption is needed', 'githubsync' );
				}

				$fields['destination_slug'] = array(
					'value'  => (string) $valid,
					'reason' => $reason,
				);
			}
		}

		return array(
			'fields'            => $fields,
			'branch_is_default' => $is_default,
			'slug_deployed'     => '' !== $slug && in_array( $slug, self::deployed_slugs( $type, $skip_id ), true ),
		);
	}
}
