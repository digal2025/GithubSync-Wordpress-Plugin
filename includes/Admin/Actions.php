<?php
/**
 * Admin POST action handlers.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Database\LogRepository;
use GitHubSync\Deployment\BackupManager;
use GitHubSync\Deployment\DeploymentManager;
use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\GitHub\AppSetup;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\Queue\Queue;
use GitHubSync\Security\Capability;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Security\InputValidator;
use GitHubSync\Support\Helpers;
use GitHubSync\Support\Settings;
use GitHubSync\Update\Updater;
use GitHubSync\Webhooks\SignatureVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Every state-changing admin action passes capability + nonce checks and
 * redirects with a message code.
 */
final class Actions {

	/**
	 * Register admin-post handlers.
	 *
	 * @return void
	 */
	public static function register() {
		$actions = array(
			'githubsync_save_deployment',
			'githubsync_delete_deployment',
			'githubsync_deploy_now',
			'githubsync_retry_job',
			'githubsync_rollback',
			'githubsync_rollback_commit',
			'githubsync_toggle_pause',
			'githubsync_regenerate_secret',
			'githubsync_save_connection_app',
			'githubsync_save_connection_pat',
			'githubsync_begin_app_manifest',
			'githubsync_finish_app_manifest',
			'githubsync_install_app',
			'githubsync_download_app_key',
			'githubsync_disconnect',
			'githubsync_test_connection',
			'githubsync_save_settings',
			'githubsync_check_update',
			'githubsync_generate_key',
			'githubsync_clear_logs',
			'githubsync_clear_history',
			'githubsync_run_queue',
			'githubsync_prune_backups',
			'githubsync_dismiss_checklist',
			'githubsync_restore_checklist',
		);

		foreach ( $actions as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * Dispatch by the current action.
	 *
	 * @return void
	 */
	public static function dispatch() {
		Capability::assert();

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case 'githubsync_save_deployment':
				self::save_deployment();
				break;
			case 'githubsync_delete_deployment':
				self::delete_deployment();
				break;
			case 'githubsync_deploy_now':
				self::deploy_now();
				break;
			case 'githubsync_retry_job':
				self::retry_job();
				break;
			case 'githubsync_rollback':
				self::rollback();
				break;
			case 'githubsync_rollback_commit':
				self::rollback_commit();
				break;
			case 'githubsync_toggle_pause':
				self::toggle_pause();
				break;
			case 'githubsync_regenerate_secret':
				self::regenerate_secret();
				break;
			case 'githubsync_save_connection_app':
				self::save_connection_app();
				break;
			case 'githubsync_save_connection_pat':
				self::save_connection_pat();
				break;
			case 'githubsync_begin_app_manifest':
				self::begin_app_manifest();
				break;
			case 'githubsync_finish_app_manifest':
				self::finish_app_manifest();
				break;
			case 'githubsync_install_app':
				self::install_app();
				break;
			case 'githubsync_download_app_key':
				self::download_app_key();
				break;
			case 'githubsync_disconnect':
				self::disconnect();
				break;
			case 'githubsync_test_connection':
				self::test_connection();
				break;
			case 'githubsync_save_settings':
				self::save_settings();
				break;
			case 'githubsync_check_update':
				self::check_update();
				break;
			case 'githubsync_generate_key':
				self::generate_key();
				break;
			case 'githubsync_clear_logs':
				self::clear_logs();
				break;
			case 'githubsync_clear_history':
				self::clear_history();
				break;
			case 'githubsync_run_queue':
				self::run_queue();
				break;
			case 'githubsync_prune_backups':
				self::prune_backups();
				break;
			case 'githubsync_dismiss_checklist':
				self::dismiss_checklist();
				break;
			case 'githubsync_restore_checklist':
				self::restore_checklist();
				break;
			default:
				self::back( 'githubsync', 'error' );
		}
	}

	/**
	 * Verify a nonce for an action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function verify( $action ) {
		check_admin_referer( $action );
	}

	/**
	 * Redirect to an admin page with a message.
	 *
	 * @param string $page Admin page slug.
	 * @param string $msg  Message code.
	 * @param string $type Notice type.
	 * @param array  $args Extra query args.
	 * @return void
	 */
	private static function back( $page, $msg, $type = 'success', array $args = array() ) {
		$args = array_merge(
			array(
				'page'    => $page,
				'gs_msg'  => $msg,
				'gs_type' => $type,
			),
			$args
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect with a custom message.
	 *
	 * @param string $page    Page slug.
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @param array  $args    Extra args.
	 * @return void
	 */
	private static function back_message( $page, $message, $type = 'error', array $args = array() ) {
		Helpers::add_notice( $type, $message );

		$args = array_merge( array( 'page' => $page ), $args );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save (create or update) a deployment configuration.
	 *
	 * @return void
	 */
	private static function save_deployment() {
		self::verify( 'githubsync_save_deployment' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			self::back_message( 'githubsync-deployment-new', __( 'A display name is required.', 'githubsync' ) );
		}

		$repository = InputValidator::repository_full_name( isset( $_POST['repository'] ) ? wp_unslash( $_POST['repository'] ) : '' );

		if ( false === $repository ) {
			self::back_message( 'githubsync-deployment-new', __( 'Repository must be in owner/name form.', 'githubsync' ) );
		}

		$branch = InputValidator::branch( isset( $_POST['branch'] ) ? wp_unslash( $_POST['branch'] ) : '' );

		if ( false === $branch ) {
			self::back_message( 'githubsync-deployment-new', __( 'That branch name is not valid.', 'githubsync' ) );
		}

		$source = InputValidator::source_path( isset( $_POST['source_path'] ) ? wp_unslash( $_POST['source_path'] ) : '' );

		if ( false === $source ) {
			self::back_message( 'githubsync-deployment-new', __( 'The source path is not a safe relative path.', 'githubsync' ) );
		}

		$type = isset( $_POST['deployment_type'] ) ? sanitize_key( wp_unslash( $_POST['deployment_type'] ) ) : '';

		if ( ! array_key_exists( $type, DestinationPolicy::types() ) ) {
			self::back_message( 'githubsync-deployment-new', __( 'Choose a valid deployment type.', 'githubsync' ) );
		}

		$slug = InputValidator::slug( isset( $_POST['destination_slug'] ) ? wp_unslash( $_POST['destination_slug'] ) : '' );

		if ( false === $slug ) {
			self::back_message( 'githubsync-deployment-new', __( 'The destination folder name is invalid.', 'githubsync' ) );
		}

		$patterns = InputValidator::pattern_list( isset( $_POST['exclude_patterns'] ) ? wp_unslash( $_POST['exclude_patterns'] ) : '' );

		$auto   = ! empty( $_POST['auto_deploy'] ) ? 1 : 0;
		$manual = ! empty( $_POST['manual_deploy'] ) ? 1 : 0;
		$adopt  = ! empty( $_POST['adopt_existing'] ) ? 1 : 0;

		// Verify the repository and branch against authenticated GitHub data.
		$client = new GitHubApiClient();
		$repo   = $client->repository( $repository );

		if ( is_wp_error( $repo ) ) {
			self::back_message( 'githubsync-deployment-new', $repo->get_error_message() );
		}

		$head = $client->branch_head_sha( $repository, $branch );

		if ( is_wp_error( $head ) ) {
			self::back_message( 'githubsync-deployment-new', $head->get_error_message() );
		}

		$destination = DestinationPolicy::resolve( $type, $slug );

		if ( is_wp_error( $destination ) ) {
			self::back_message( 'githubsync-deployment-new', $destination->get_error_message() );
		}

		$deployments = new DeploymentRepository();

		if ( $deployments->destination_in_use( $destination['path'], $id ) ) {
			self::back_message( 'githubsync-deployment-new', __( 'Another deployment configuration already manages that destination.', 'githubsync' ) );
		}

		if ( DestinationPolicy::has_foreign_content( $destination['path'] ) && ! $adopt ) {
			self::back_message(
				'githubsync-deployment-new',
				sprintf(
					/* translators: %s: destination path. */
					__( 'The destination %s already contains files GitHubSync did not create. Tick "Adopt existing directory" to take ownership.', 'githubsync' ),
					$destination['path']
				)
			);
		}

		$data = array(
			'name'                  => $name,
			'github_repository_id'  => (int) $repo['id'],
			'repository_full_name'  => (string) $repo['full_name'],
			'repository_visibility' => (string) $repo['visibility'],
			'branch'                => $branch,
			'source_path'           => $source,
			'destination_path'      => $destination['path'],
			'deployment_type'       => $type,
			'destination_slug'      => $slug,
			'exclude_patterns'      => implode( "\n", $patterns ),
			'auto_deploy'           => $auto,
			'manual_deploy'         => $manual,
			'adopt_existing'        => $adopt,
		);

		if ( $id > 0 ) {
			$existing = $deployments->find( $id );

			if ( ! $existing ) {
				self::back( 'githubsync-deployments', 'error' );
			}

			$result = $deployments->update( $id, $data );

			if ( is_wp_error( $result ) ) {
				self::back_message( 'githubsync-deployment-edit', $result->get_error_message(), 'error', array( 'id' => $id ) );
			}

			self::back( 'githubsync-deployment', 'saved', 'success', array( 'id' => $id ) );
		}

		try {
			$vault = CredentialVault::from_site();
		} catch ( \Throwable $e ) {
			self::back_message(
				'githubsync-settings',
				__( 'Configure secure credential encryption before adding a deployment. Generate a key on the Settings page.', 'githubsync' )
			);
		}

		$data['webhook_token']            = Helpers::random_token( 16 );
		$data['webhook_secret_encrypted'] = $vault->encrypt( SignatureVerifier::generate_secret() );

		$new_id = $deployments->create( $data );

		if ( is_wp_error( $new_id ) ) {
			self::back_message( 'githubsync-deployment-new', $new_id->get_error_message() );
		}

		self::back( 'githubsync-deployment', 'saved', 'success', array( 'id' => (int) $new_id ) );
	}

	/**
	 * Delete a configuration (keeps files, backups and history).
	 *
	 * @return void
	 */
	private static function delete_deployment() {
		self::verify( 'githubsync_delete_deployment' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 || empty( $_POST['confirm'] ) ) {
			self::back( 'githubsync-deployments', 'error' );
		}

		$deployments = new DeploymentRepository();
		$deployments->delete( $id );

		( new JobRepository() )->cancel_pending( $id );

		self::back( 'githubsync-deployments', 'deleted' );
	}

	/**
	 * Queue a manual deployment.
	 *
	 * @return void
	 */
	private static function deploy_now() {
		self::verify( 'githubsync_deploy_now' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$manager = new DeploymentManager();
		$result  = $manager->deploy_now( $id );

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-deployment', $result->get_error_message(), 'error', array( 'id' => $id ) );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		self::back( 'githubsync-deployment', 'queued', 'success', array( 'id' => $id ) );
	}

	/**
	 * Retry a failed job.
	 *
	 * @return void
	 */
	private static function retry_job() {
		self::verify( 'githubsync_retry_job' );

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$dep_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$result = ( new DeploymentManager() )->retry( $job_id );

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-deployment', $result->get_error_message(), 'error', array( 'id' => $dep_id ) );
		}

		self::back( 'githubsync-deployment', 'retried', 'success', array( 'id' => $dep_id ) );
	}

	/**
	 * Queue a rollback.
	 *
	 * @return void
	 */
	private static function rollback() {
		self::verify( 'githubsync_rollback' );

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

		$result = ( new DeploymentManager() )->rollback( $id, $job_id );

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-deployment', $result->get_error_message(), 'error', array( 'id' => $id ) );
		}

		self::back( 'githubsync-deployment', 'rolledback', 'success', array( 'id' => $id ) );
	}

	/**
	 * Queue a rollback to an earlier commit from GitHub history.
	 *
	 * @return void
	 */
	private static function rollback_commit() {
		self::verify( 'githubsync_rollback_commit' );

		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$sha = isset( $_POST['commit_sha'] ) ? sanitize_text_field( wp_unslash( $_POST['commit_sha'] ) ) : '';

		$result = ( new DeploymentManager() )->rollback_to_commit( $id, $sha );

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-deployment', $result->get_error_message(), 'error', array( 'id' => $id ) );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		self::back( 'githubsync-deployment', 'rolledback', 'success', array( 'id' => $id ) );
	}

	/**
	 * Pause or resume automatic deployment.
	 *
	 * @return void
	 */
	private static function toggle_pause() {
		self::verify( 'githubsync_toggle_pause' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$deployments = new DeploymentRepository();
		$row         = $deployments->find( $id );

		if ( ! $row ) {
			self::back( 'githubsync-deployments', 'error' );
		}

		$next = 'active' === $row['status'] ? 'paused' : 'active';

		$deployments->update( $id, array( 'status' => $next ) );

		self::back( 'githubsync-deployment', 'paused' === $next ? 'paused' : 'resumed', 'success', array( 'id' => $id ) );
	}

	/**
	 * Regenerate a webhook secret.
	 *
	 * @return void
	 */
	private static function regenerate_secret() {
		self::verify( 'githubsync_regenerate_secret' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		try {
			$vault  = CredentialVault::from_site();
			$secret = $vault->encrypt( SignatureVerifier::generate_secret() );
		} catch ( \Throwable $e ) {
			self::back_message( 'githubsync-deployment', __( 'Secure encryption is not configured.', 'githubsync' ), 'error', array( 'id' => $id ) );
		}

		( new DeploymentRepository() )->update( $id, array( 'webhook_secret_encrypted' => $secret ) );

		self::back( 'githubsync-deployment', 'secret_regenerated', 'success', array( 'id' => $id ) );
	}

	/**
	 * Snapshot the three credential options so a failed connection attempt can
	 * be rolled back without wiping a working connection.
	 *
	 * @return array
	 */
	public static function snapshot_connection() {
		$keys = array( GitHubAuth::OPTION_CONNECTION, GitHubAuth::OPTION_APP_KEY, GitHubAuth::OPTION_PAT );
		$snap = array();

		foreach ( $keys as $key ) {
			$value        = get_option( $key, null );
			$snap[ $key ] = array(
				'exists' => null !== $value,
				'value'  => $value,
			);
		}

		return $snap;
	}

	/**
	 * Restore a connection snapshot.
	 *
	 * @param array $snapshot Snapshot from snapshot_connection().
	 * @return void
	 */
	public static function restore_connection( array $snapshot ) {
		foreach ( $snapshot as $key => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				update_option( $key, $entry['value'], false );
			} else {
				delete_option( $key );
			}
		}
	}

	/**
	 * Save a GitHub App connection.
	 *
	 * @return void
	 */
	private static function save_connection_app() {
		self::verify( 'githubsync_save_connection_app' );

		$app_id          = isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : '';
		$installation_id = isset( $_POST['installation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['installation_id'] ) ) : '';
		$private_key     = isset( $_POST['private_key'] ) ? (string) wp_unslash( $_POST['private_key'] ) : '';

		$snapshot = self::snapshot_connection();
		$auth     = new GitHubAuth();
		$result   = $auth->connect_app( $app_id, $installation_id, $private_key );

		if ( is_wp_error( $result ) ) {
			self::restore_connection( $snapshot );
			self::back_message( 'githubsync-connection', $result->get_error_message() );
		}

		// Prove the installation token works before keeping the connection.
		$check = ( new GitHubApiClient( $auth ) )->repositories( 1, 1 );

		if ( is_wp_error( $check ) ) {
			self::restore_connection( $snapshot );
			self::back_message( 'githubsync-connection', $check->get_error_message() );
		}

		$connection = $auth->connection();

		if ( '' === $connection['account'] && ! empty( $check['items'][0]['full_name'] ) ) {
			$parts                 = explode( '/', (string) $check['items'][0]['full_name'], 2 );
			$connection['account'] = sanitize_text_field( (string) $parts[0] );
			update_option( GitHubAuth::OPTION_CONNECTION, $connection, false );
		}

		self::back( 'githubsync-connection', 'connected' );
	}

	/**
	 * Save a PAT connection.
	 *
	 * @return void
	 */
	private static function save_connection_pat() {
		self::verify( 'githubsync_save_connection_pat' );

		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';

		$snapshot = self::snapshot_connection();
		$auth     = new GitHubAuth();
		$result   = $auth->connect_pat( $token );

		if ( is_wp_error( $result ) ) {
			self::restore_connection( $snapshot );
			self::back_message( 'githubsync-connection', $result->get_error_message() );
		}

		$client   = new GitHubApiClient( $auth );
		$identity = $client->whoami();

		if ( is_wp_error( $identity ) ) {
			self::restore_connection( $snapshot );
			self::back_message( 'githubsync-connection', $identity->get_error_message() );
		}

		$check = $client->repositories( 1, 1 );

		if ( is_wp_error( $check ) ) {
			self::restore_connection( $snapshot );
			self::back_message( 'githubsync-connection', $check->get_error_message() );
		}

		$connection = $auth->connection();

		if ( is_array( $identity ) && ( '' === $connection['account'] ) && ! empty( $identity['account'] ) ) {
			$connection['account']      = sanitize_text_field( (string) $identity['account'] );
			$connection['display_name'] = isset( $identity['display_name'] ) ? sanitize_text_field( (string) $identity['display_name'] ) : '';
			update_option( GitHubAuth::OPTION_CONNECTION, $connection, false );
		}

		self::back( 'githubsync-connection', 'connected' );
	}

	/**
	 * Start the one-click App creation: store the pending state, then send the
	 * browser to GitHub with that state, so the page can render the manifest
	 * form it must POST.
	 *
	 * @return void
	 */
	private static function begin_app_manifest() {
		self::verify( 'githubsync_begin_app_manifest' );

		// GitHub only accepts the manifest as a form POST, so this stores the
		// single-use state and the page renders the self-submitting form.
		$organization = isset( $_POST['organization'] ) ? sanitize_text_field( wp_unslash( $_POST['organization'] ) ) : '';
		$setup        = new AppSetup();
		$started      = $setup->begin( $organization );

		if ( is_wp_error( $started ) ) {
			self::back_message( 'githubsync-connection', $started->get_error_message() );
		}

		self::back( 'githubsync-connection', 'app_manifest_ready', 'success', array( 'gs_state' => $started['state'] ) );
	}

	/**
	 * Run the flow's callback when GitHub redirected to `admin-post.php`.
	 *
	 * @return void
	 */
	private static function finish_app_manifest() {
		$result = ( new AppSetup() )->finish( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GitHub callbacks carry no nonce.

		if ( null === $result ) {
			self::back_message( 'githubsync-connection', __( 'Nothing to finish here. Start again from the connection page.', 'githubsync' ) );
		}

		if ( 'error' === $result['code'] ) {
			self::back_message( 'githubsync-connection', $result['message'] );
		}

		self::back( 'githubsync-connection', $result['code'], $result['type'] );
	}

	/**
	 * Finish the flow when GitHub redirected to the connection page itself.
	 *
	 * Returns the outcome for the page to render in place, without redirecting.
	 *
	 * @return array|null { code, type, message, install_url }
	 */
	public static function page_callback() {
		return ( new AppSetup() )->finish( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GitHub callbacks carry no nonce.
	}

	/**
	 * Bind an existing installation to the stored App, chosen from the
	 * App's own installation list.
	 *
	 * @return void
	 */
	private static function install_app() {
		self::verify( 'githubsync_install_app' );

		$installation_id = isset( $_POST['installation_id'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['installation_id'] ) ) : '';

		$setup  = new AppSetup();
		$result = $setup->install( '', array( 'installation_id' => $installation_id ) );

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-connection', $result->get_error_message() );
		}

		self::back( 'githubsync-connection', 'app_installed' );
	}

	/**
	 * Download the stored App private key once, after a one-click setup.
	 *
	 * GitHub only returns the key in the manifest conversion, so this is the
	 * one chance to keep a copy outside the site.
	 *
	 * @return void
	 */
	private static function download_app_key() {
		self::verify( 'githubsync_download_app_key' );

		$key = ( new AppSetup() )->private_key();

		if ( is_wp_error( $key ) ) {
			self::back_message( 'githubsync-connection', $key->get_error_message() );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="githubsync-app-private-key.pem"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $key; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PEM download.
		exit;
	}

	/**
	 * Disconnect GitHub.
	 *
	 * @return void
	 */
	private static function disconnect() {
		self::verify( 'githubsync_disconnect' );

		( new GitHubAuth() )->disconnect();

		self::back( 'githubsync-connection', 'disconnected' );
	}

	/**
	 * Test the connection.
	 *
	 * @return void
	 */
	private static function test_connection() {
		self::verify( 'githubsync_test_connection' );

		$client = new GitHubApiClient();
		$result = $client->repositories( 1, 1 );

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-connection', $result->get_error_message() );
		}

		self::back_message( 'githubsync-connection', __( 'GitHub connection is working.', 'githubsync' ), 'success' );
	}

	/**
	 * Fetch the release feed now and refresh the WordPress update list.
	 *
	 * @return void
	 */
	private static function check_update() {
		self::verify( 'githubsync_check_update' );

		if ( ! Updater::enabled() ) {
			self::back( 'githubsync-settings', 'update_disabled', 'warning' );
		}

		Updater::flush_cache();

		$release = Updater::get_release( true );

		if ( is_wp_error( $release ) ) {
			Updater::record_error( $release );
			self::back_message( 'githubsync-settings', $release->get_error_message() );
		}

		Updater::record_release( $release );
		Updater::request_check( false );

		if ( Updater::is_newer( $release['version'] ) ) {
			self::back( 'githubsync-settings', 'update_available', 'warning' );
		}

		self::back( 'githubsync-settings', 'update_current' );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	private static function save_settings() {
		self::verify( 'githubsync_save_settings' );

		$input = isset( $_POST['githubsync'] ) && is_array( $_POST['githubsync'] ) ? wp_unslash( $_POST['githubsync'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		update_option( Settings::OPTION, Settings::sanitize( $input ), false );

		$url = isset( $_POST['update_url'] ) ? wp_unslash( $_POST['update_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		Updater::save_manifest_url( is_string( $url ) ? $url : '' );

		self::back( 'githubsync-settings', 'settings_saved' );
	}

	/**
	 * Create an encryption key file.
	 *
	 * @return void
	 */
	private static function generate_key() {
		self::verify( 'githubsync_generate_key' );

		$result = CredentialVault::create_key_file();

		if ( is_wp_error( $result ) ) {
			self::back_message( 'githubsync-settings', $result->get_error_message() );
		}

		self::back( 'githubsync-settings', 'key_created' );
	}

	/**
	 * Clear all logs.
	 *
	 * @return void
	 */
	private static function clear_logs() {
		self::verify( 'githubsync_clear_logs' );

		( new LogRepository() )->clear_all();

		self::back( 'githubsync-tools', 'logs_cleared' );
	}

	/**
	 * Delete recorded deployment history.
	 *
	 * @return void
	 */
	private static function clear_history() {
		self::verify( 'githubsync_clear_history' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		( new JobRepository() )->clear_history( $id );

		self::back( 'githubsync-history', 'history_cleared' );
	}

	/**
	 * Process due jobs now.
	 *
	 * @return void
	 */
	private static function run_queue() {
		self::verify( 'githubsync_run_queue' );

		( new Queue() )->process_due( 5 );

		self::back( 'githubsync-tools', 'queue_ran' );
	}

	/**
	 * Prune backups beyond retention.
	 *
	 * @return void
	 */
	private static function prune_backups() {
		self::verify( 'githubsync_prune_backups' );

		$fs      = new FilesystemAdapter();
		$backups = new BackupManager( $fs );
		$keep    = (int) Settings::get( 'backup_retention', 3 );

		foreach ( ( new DeploymentRepository() )->all() as $row ) {
			$backups->prune( (int) $row['id'], $keep );
		}

		self::back( 'githubsync-tools', 'backups_pruned' );
	}

	/**
	 * Hide the dashboard setup checklist.
	 *
	 * Only allowed once every step is complete: the checklist is the only
	 * in-plugin map of what a working install needs, so it is not dismissible
	 * while steps remain open. Completion is recomputed here rather than
	 * trusted from the form.
	 *
	 * @return void
	 */
	private static function dismiss_checklist() {
		self::verify( 'githubsync_dismiss_checklist' );

		if ( ! DashboardPage::setup_complete() ) {
			self::back( 'githubsync', 'checklist_incomplete', 'error' );
		}

		update_user_meta( get_current_user_id(), DashboardPage::DISMISSED_META, 1 );

		self::back( 'githubsync', 'checklist_closed' );
	}

	/**
	 * Show a closed checklist again.
	 *
	 * @return void
	 */
	private static function restore_checklist() {
		self::verify( 'githubsync_restore_checklist' );

		delete_user_meta( get_current_user_id(), DashboardPage::DISMISSED_META );

		self::back( 'githubsync', 'checklist_restored' );
	}
}
