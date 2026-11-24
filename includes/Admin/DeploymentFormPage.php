<?php
/**
 * Add/edit deployment form.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\GitHub\RepositoryPrefill;
use GitHubSync\Security\Capability;
use GitHubSync\Security\InputValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered wizard-style form. Branch suggestions are loaded on demand
 * and cached briefly.
 */
final class DeploymentFormPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$row = null;

		if ( $id > 0 ) {
			$row = ( new DeploymentRepository() )->find( $id );

			if ( ! $row ) {
				wp_die( esc_html__( 'That deployment configuration does not exist.', 'githubsync' ), 404 );
			}
		}

		$repo_param = isset( $_GET['gs_repo'] ) ? sanitize_text_field( wp_unslash( $_GET['gs_repo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $repo_param && $row ) {
			$repo_param = (string) $row['repository_full_name'];
		}

		$branches     = array();
		$branch_error = '';
		$meta         = null;
		$meta_error   = '';

		$valid_repo = InputValidator::repository_full_name( $repo_param );

		if ( false !== $valid_repo ) {
			$cache_key = 'githubsync_branches_' . md5( strtolower( $valid_repo ) );
			$cached    = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				$branches = $cached;
			} else {
				$result = ( new GitHubApiClient() )->branches( $valid_repo, 1, 100 );

				if ( is_wp_error( $result ) ) {
					$branch_error = $result->get_error_message();
				} else {
					$branches = $result['items'];
					set_transient( $cache_key, $branches, 5 * MINUTE_IN_SECONDS );
				}
			}

			$lookup = RepositoryPrefill::metadata( null, $valid_repo );

			if ( is_wp_error( $lookup ) ) {
				$meta_error = $lookup->get_error_message();
			} else {
				$meta = $lookup;
			}
		}

		$data = array(
			'id'           => $id,
			'row'          => $row,
			'types'        => DestinationPolicy::types(),
			'branches'     => $branches,
			'branch_error' => $branch_error,
			'repo_param'   => $repo_param,
			'repo_meta'    => $meta,
			'repo_error'   => $meta_error,
		);

		include GITHUBSYNC_DIR . 'templates/deployment-form.php';
	}
}
