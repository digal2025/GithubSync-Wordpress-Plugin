<?php
/**
 * GitHub App one-click setup: the signed manifest flow.
 *
 * @package GitHubSync
 */

namespace GitHubSync\GitHub;

use GitHubSync\Admin\Actions;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a GitHub App on the user's account without any pasting.
 *
 * GitHub's manifest flow works in three steps:
 *
 * 1. The browser POSTs a JSON manifest to https://github.com/settings/apps/new
 *    together with a random `state`. The owner approves it on GitHub.
 * 2. GitHub redirects to the manifest's `redirect_url` with a one-time `code`.
 *    The code is exchanged, server to server, for the App ID, private key and
 *    App-level webhook secret.
 * 3. The owner installs the App. GitHub redirects to the manifest's
 *    `setup_url` with `installation_id`, which is bound to the stored App.
 *
 * The pending manifest lives in a short-lived, single-use transient keyed by
 * the random state, so the exchange cannot be replayed.
 *
 * GitHub requires an HTTPS redirect URL (or http://localhost) for the App to
 * accept the manifest. On a plain-http site the flow is reported as
 * unavailable and the manual App form is used instead.
 */
final class AppSetup {

	/**
	 * Transient prefix for a pending manifest.
	 */
	public const TRANSIENT_PREFIX = 'githubsync_app_manifest_';

	/**
	 * Transient prefix for a finished result that the page displays once.
	 */
	public const RESULT_PREFIX = 'githubsync_app_setup_result_';

	/**
	 * Transient prefix marking an App whose key came from the manifest flow.
	 */
	public const KEY_PREFIX = 'githubsync_app_key_download_';

	/**
	 * How long the owner has to approve the App before the manifest expires.
	 */
	public const TTL = 3600;

	/**
	 * How long a finished exchange stays available for the install step.
	 */
	public const RESULT_TTL = 3600;

	/**
	 * Where GitHub creates a personal App.
	 */
	public const NEW_APP_URL = 'https://github.com/settings/apps/new';

	/**
	 * Where GitHub creates an App owned by an organisation.
	 */
	public const NEW_ORG_APP_URL = 'https://github.com/organizations/%s/settings/apps/new';

	/**
	 * Where the owner installs the App.
	 */
	public const INSTALL_URL = 'https://github.com/apps/%s/installations/new';

	/**
	 * Manifest exchange endpoint.
	 */
	public const CONVERSIONS_URL = 'https://api.github.com/app-manifests/%s/conversions';

	/**
	 * GitHub webhook events requested. Only `push` is acted on.
	 *
	 * @return array
	 */
	public static function events() {
		return array( 'push' );
	}

	/**
	 * Repository permissions GitHubSync needs and nothing else.
	 *
	 * @return array
	 */
	public static function permissions() {
		return array(
			'contents' => 'read',
			'metadata' => 'read',
		);
	}

	/**
	 * Secret used to sign the App's webhook deliveries.
	 *
	 * Generated once and kept in the settings option. The manifest's webhook URL
	 * carries it, so it has to exist before the App is created.
	 *
	 * @return string 32-byte hex string.
	 */
	public static function hook_secret() {
		$settings = get_option( Settings::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$existing = isset( $settings['app_webhook_secret'] ) ? (string) $settings['app_webhook_secret'] : '';

		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $existing ) ) {
			return $existing;
		}

		$secret                        = bin2hex( random_bytes( 32 ) );
		$settings['app_webhook_secret'] = $secret;
		update_option( Settings::OPTION, $settings, false );

		return $secret;
	}

	/**
	 * Whether the browser round trip can complete on this site.
	 *
	 * GitHub only redirects back to HTTPS (or http://localhost). A site behind
	 * a TLS-terminating proxy reports an http:// home URL, so the answer can be
	 * overridden with the `githubsync_app_setup_supported` filter.
	 *
	 * @return bool
	 */
	public function is_supported() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';

		if ( 0 === strpos( home_url( '/' ), 'https://' ) ) {
			$supported = true;
		} else {
			$supported = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		}

		return (bool) apply_filters( 'githubsync_app_setup_supported', $supported, home_url( '/' ) );
	}

	/**
	 * Admin page slug the flow returns to.
	 *
	 * @return string
	 */
	public static function page() {
		return 'githubsync-connection';
	}

	/**
	 * URL of the connection page with the one-click setup panel open.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::page() ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Manifest JSON for this site.
	 *
	 * @return array
	 */
	public function manifest() {
		// GitHub's manifest schema uses `default_permissions` / `default_events`,
		// and `hook_attributes.url` is required even when the hook is inactive.
		// The App is created with the hook off: the URL points at the site webhook
		// and deployments pick repositories by their own token, so it only becomes
		// useful once the owner activates it and selects repositories.
		return array(
			'name'               => $this->app_name(),
			'url'                => home_url( '/' ),
			/* translators: %s: site host. */
			'description'        => sprintf( __( 'Deploys WordPress themes and plugins from GitHub repositories on %s, with backups and rollback.', 'githubsync' ), $this->host() ),
			'public'             => false,
			'redirect_url'       => self::page_url( array( '_gs_return' => '1' ) ),
			'setup_url'          => self::page_url( array( '_gs_install' => '1' ) ),
			'callback_urls'      => array(),
			'hook_attributes'    => array(
				'url'    => self::webhook_url( self::hook_secret() ),
				'active' => false,
			),
			'default_permissions' => self::permissions(),
			'default_events'      => self::events(),
		);
	}

	/**
	 * Site host, without `www.`.
	 *
	 * @return string
	 */
	private function host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : 'wordpress';

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * App name. GitHub needs a globally unique name, so the host is included.
	 *
	 * @return string
	 */
	public function app_name() {
		// GitHub caps App names at 34 characters.
		return substr( 'GitHubSync ' . $this->host(), 0, 34 );
	}

	/**
	 * GitHub endpoint that accepts the manifest form.
	 *
	 * @param string $organization Organisation login, or "" for a personal App.
	 * @return string
	 */
	public static function create_url( $organization = '' ) {
		$organization = self::clean_login( $organization );

		if ( '' === $organization ) {
			return self::NEW_APP_URL;
		}

		return sprintf( self::NEW_ORG_APP_URL, rawurlencode( $organization ) );
	}

	/**
	 * Install URL for a slug, or "" when there is no slug.
	 *
	 * @param string $slug App slug.
	 * @return string
	 */
	public static function install_url( $slug ) {
		$slug = self::clean_login( $slug );

		if ( '' === $slug ) {
			return '';
		}

		return sprintf( self::INSTALL_URL, rawurlencode( $slug ) );
	}

	/**
	 * Reduce input to a GitHub-like login (lowercase, digits, hyphens).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_login( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9-]/', '', (string) $value );

		return substr( trim( (string) $value, '-' ), 0, 39 );
	}

	/**
	 * Build the manifest form values and remember the pending state.
	 *
	 * @param string $organization Organisation login, or "" for personal.
	 * @return array|\WP_Error { state, action, manifest }
	 */
	public function begin( $organization = '' ) {
		if ( ! $this->is_supported() ) {
			return new \WP_Error(
				ErrorCodes::CONFIG_INVALID,
				__( 'GitHub only redirects back to an HTTPS URL (or http://localhost), so one-click setup cannot run on this site. Use the manual App fields below.', 'githubsync' )
			);
		}

		$state   = bin2hex( random_bytes( 24 ) );
		$action  = self::create_url( $organization );
		$form    = array(
			'state'    => $state,
			'action'   => $action,
			'manifest' => (string) wp_json_encode( $this->manifest() ),
		);
		$pending = array(
			'state'      => $state,
			'created'    => time(),
			'used'       => false,
			'app_id'     => '',
			'action'     => $action,
			'manifest'   => $form['manifest'],
		);

		if ( ! set_transient( self::TRANSIENT_PREFIX . $state, $pending, self::TTL ) ) {
			return new \WP_Error(
				ErrorCodes::INTERNAL_ERROR,
				__( 'The one-time setup state could not be stored. Check that the database and object cache accept transients.', 'githubsync' )
			);
		}

		return $form;
	}

	/**
	 * The self-posting form for a pending state, or null when it is not ours.
	 *
	 * @param string $state State value.
	 * @return array|null { action, manifest, state }
	 */
	public function form( $state ) {
		$pending = $this->pending( $state );

		if ( null === $pending || ! empty( $pending['used'] ) || empty( $pending['manifest'] ) ) {
			return null;
		}

		return array(
			'action'   => isset( $pending['action'] ) ? (string) $pending['action'] : self::create_url(),
			'manifest' => (string) $pending['manifest'],
			'state'    => (string) $pending['state'],
		);
	}

	/**
	 * Load a pending manifest.
	 *
	 * @param string $state State value.
	 * @return array|null
	 */
	public function pending( $state ) {
		if ( ! self::is_state( $state ) ) {
			return null;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $state );

		return is_array( $pending ) ? $pending : null;
	}

	/**
	 * Whether a value looks like a state token we issued.
	 *
	 * @param string $state Value.
	 * @return bool
	 */
	public static function is_state( $state ) {
		return 1 === preg_match( '/^[a-f0-9]{48}$/', (string) $state );
	}

	/**
	 * Exchange a redirect code for the App's credentials and store them.
	 *
	 * Only the App ID and private key are stored here. The installation is
	 * bound afterwards by complete(), because GitHub installs the App after
	 * the redirect and the user may pick more than one account.
	 *
	 * @param string $state State value from the redirect.
	 * @param string $code  One-time code from the redirect.
	 * @return array|\WP_Error { app_id, slug, html_url, install_url, installations }
	 */
	public function exchange( $state, $code ) {
		$code    = sanitize_text_field( (string) $code );
		$pending = $this->pending( $state );

		if ( null === $pending ) {
			return new \WP_Error(
				ErrorCodes::CONFIG_INVALID,
				__( 'This setup link has expired or was already used. Start the App creation again.', 'githubsync' )
			);
		}
		if ( ! empty( $pending['used'] ) ) {
			return new \WP_Error(
				ErrorCodes::CONFIG_INVALID,
				__( 'This setup link was already used. GitHub issues each code once, so create the App again.', 'githubsync' )
			);
		}

		if ( '' === $code ) {
			return new \WP_Error(
				ErrorCodes::CONFIG_INVALID,
				__( 'GitHub did not return a setup code. Start the App creation again.', 'githubsync' )
			);
		}

		// Burn the state before the HTTP call: a duplicate redirect must never
		// spend the same code twice.
		$pending['used'] = true;
		set_transient( self::TRANSIENT_PREFIX . $pending['state'], $pending, self::TTL );

		$response = wp_remote_post(
			sprintf( self::CONVERSIONS_URL, rawurlencode( $code ) ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'GitHubSync/' . GITHUBSYNC_VERSION,
				),
				'body'        => '',
			)
		);

		$result = GitHubResponse::from_wp( $response );

		if ( ! $result->is_ok() || ! is_array( $result->data ) ) {
			if ( 404 === $result->status || 422 === $result->status ) {
				return new \WP_Error(
					ErrorCodes::CONFIG_INVALID,
					__( 'GitHub did not accept that setup code. Codes are single-use and expire within an hour; create the App again.', 'githubsync' )
				);
			}

			return new \WP_Error(
				ErrorCodes::CONFIG_INVALID,
				'' !== $result->error_message() ? $result->error_message() : __( 'GitHub could not turn the manifest into an App. Try again.', 'githubsync' )
			);
		}

		$data = $result->data;

		if ( empty( $data['id'] ) || empty( $data['pem'] ) ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'GitHub returned an App without an ID or private key.', 'githubsync' ) );
		}

		// connect_app() drops any stored PAT, so snapshot first: a half-finished
		// flow must never leave the site without its working connection.
		$snapshot = Actions::snapshot_connection();
		$auth     = new GitHubAuth();
		$app      = $auth->connect_app( (string) $data['id'], '', (string) $data['pem'] );

		if ( is_wp_error( $app ) ) {
			Actions::restore_connection( $snapshot );
			return $app;
		}

		$slug    = isset( $data['slug'] ) ? self::clean_login( $data['slug'] ) : '';
		$html    = isset( $data['html_url'] ) ? esc_url_raw( (string) $data['html_url'] ) : '';
		$secrets = array();

		if ( isset( $data['hook']['config']['secret'] ) && '' !== (string) $data['hook']['config']['secret'] ) {
			$secrets = array( 'app_webhook_secret' => (string) $data['hook']['config']['secret'] );
		}

		$auth->remember_app_meta(
			array(
				'app_id'    => (string) $data['id'],
				'slug'      => $slug,
				'html_url'  => $html,
				'client_id' => isset( $data['client_id'] ) ? (string) $data['client_id'] : '',
			)
		);

		if ( ! empty( $secrets ) ) {
			$auth->remember_secrets( $secrets );
		}

		$pending['app_id']     = (string) $data['id'];
		$pending['slug']       = $slug;
		$pending['html_url']   = $html;
		$pending['secrets_set'] = ! empty( $secrets );

		set_transient( self::TRANSIENT_PREFIX . $pending['state'], $pending, self::RESULT_TTL );

		return array(
			'state'       => $pending['state'],
			'app_id'      => (string) $data['id'],
			'slug'        => $slug,
			'html_url'    => $html,
			'install_url' => self::install_url( $slug ),
			'secrets_set' => ! empty( $secrets ),
		);
	}

	/**
	 * Finish the flow from what GitHub sent back to the setup URL.
	 *
	 * GitHub appends `installation_id` (and `setup_action=install|update`) to
	 * the manifest's `setup_url`. The installation is bound to the stored App
	 * and verified against the installation API before it is kept.
	 *
	 * @param string $state     Pending state, or "" when GitHub dropped it.
	 * @param array  $query     Query args from the redirect (installation_id, setup_action).
	 * @return array|\WP_Error { app_id, installation_id, account, display_name, slug }
	 */
	public function install( $state, array $query ) {
		$installation_id = isset( $query['installation_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $query['installation_id'] ) : '';

		if ( '' === $installation_id ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'GitHub did not send an installation ID. Install the App again from the connection page.', 'githubsync' ) );
		}

		$auth = new GitHubAuth();

		if ( ! $auth->has_app() ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'No GitHub App is stored yet. Create the App first, then install it.', 'githubsync' ) );
		}

		$app = $auth->connection();

		$pending = $this->pending( $state );
		$meta    = $auth->app_meta();
		$slug    = isset( $meta['slug'] ) ? (string) $meta['slug'] : '';

		if ( null !== $pending && ! empty( $pending['slug'] ) ) {
			$slug = (string) $pending['slug'];
		}

		$identity = $auth->installation_identity( $installation_id );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$snapshot = Actions::snapshot_connection();
		$bound    = $auth->complete_installation( (string) $app['app_id'], $installation_id, $identity );

		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		// Prove the installation token works before the page reports success.
		// The client cached the App's credentials, so point it at the bound one.
		$check = ( new GitHubApiClient( new GitHubAuth() ) )->repositories( 1, 1 );

		if ( is_wp_error( $check ) ) {
			Actions::restore_connection( $snapshot );
			return $check;
		}

		if ( null !== $pending ) {
			delete_transient( self::TRANSIENT_PREFIX . $pending['state'] );
		}

		$result = array(
			'app_id'          => (string) $app['app_id'],
			'installation_id' => $installation_id,
			'account'         => isset( $identity['account'] ) ? (string) $identity['account'] : '',
			'display_name'    => isset( $identity['display_name'] ) ? (string) $identity['display_name'] : '',
			'slug'            => $slug,
		);

		set_transient( self::RESULT_PREFIX . (string) $app['app_id'], $result, 300 );

		return $result;
	}

	/**
	 * Finish the flow straight from the query GitHub redirected with.
	 *
	 * Never redirects: the connection page renders the outcome in place, so a
	 * stray callback cannot start a redirect loop and a code is only spent
	 * once (the state is burned either way).
	 *
	 * @param array $query Query args (`code`, `state`, `installation_id`, `setup_action`, `error`).
	 * @return array|null Null when the request is not a callback. Otherwise
	 *                    { code, type, message, install_url } for the page.
	 */
	public function finish( array $query ) {
		$state           = isset( $query['state'] ) ? sanitize_text_field( (string) $query['state'] ) : '';
		$code            = isset( $query['code'] ) ? sanitize_text_field( (string) $query['code'] ) : '';
		$installation_id = isset( $query['installation_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $query['installation_id'] ) : '';
		$returned        = isset( $query['_gs_return'] ) || isset( $query['_gs_install'] );
		$error           = isset( $query['error'] ) ? sanitize_key( (string) $query['error'] ) : '';

		if ( ! $returned && '' === $installation_id && ( '' === $state || '' === $code ) && '' === $error ) {
			return null;
		}

		if ( '' !== $installation_id ) {
			$result = $this->install(
				$state,
				array(
					'installation_id' => $installation_id,
					'setup_action'    => isset( $query['setup_action'] ) ? sanitize_key( (string) $query['setup_action'] ) : '',
				)
			);

			if ( is_wp_error( $result ) ) {
				return array( 'code' => 'error', 'type' => 'error', 'message' => $result->get_error_message(), 'install_url' => '' );
			}

			return array(
				'code'        => 'app_installed',
				'type'        => 'success',
				/* translators: %s: GitHub account login. */
				'message'     => sprintf( __( 'Installed on %s. Repositories can now be browsed.', 'githubsync' ), $result['account'] ),
				'install_url' => '',
			);
		}

		if ( '' !== $state && '' !== $code ) {
			$result = $this->exchange( $state, $code );

			if ( is_wp_error( $result ) ) {
				return array( 'code' => 'error', 'type' => 'error', 'message' => $result->get_error_message(), 'install_url' => '' );
			}

			// Keep GitHub's one-time key available to the download link.
			if ( '' !== $result['app_id'] ) {
				set_transient( self::KEY_PREFIX . $result['app_id'], array( 'app_id' => $result['app_id'] ), self::RESULT_TTL );
			}

			return array(
				'code'        => 'app_created',
				'type'        => 'success',
				'message'     => __( 'GitHub App created and its key stored. Download the private key, then install the App on GitHub.', 'githubsync' ),
				'install_url' => (string) $result['install_url'],
			);
		}

		return array(
			'code'        => 'app_setup_cancelled',
			'type'        => 'warning',
			'message'     => __( 'GitHub cancelled or did not finish the App setup. Nothing was changed.', 'githubsync' ),
			'install_url' => '',
		);
	}

	/**
	 * Read and clear the result of a finished install.
	 *
	 * @param string $app_id App ID.
	 * @return array|null
	 */
	public function take_install_result( $app_id ) {
		$key    = self::RESULT_PREFIX . (string) preg_replace( '/[^0-9]/', '', (string) $app_id );
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Whether a one-click App key is available to download.
	 *
	 * @param string $app_id App ID.
	 * @return bool
	 */
	public function key_download_available( $app_id ) {
		$app_id = (string) preg_replace( '/[^0-9]/', '', (string) $app_id );

		return '' !== $app_id && is_array( get_transient( self::KEY_PREFIX . $app_id ) );
	}

	/**
	 * Decrypted private key, for the one-time download.
	 *
	 * @return string|\WP_Error
	 */
	public function private_key() {
		$stored = (string) get_option( GitHubAuth::OPTION_APP_KEY, '' );

		if ( '' === $stored ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'No GitHub App private key is stored.', 'githubsync' ) );
		}

		try {
			return CredentialVault::from_site()->decrypt( $stored );
		} catch ( \Throwable $e ) {
			return new \WP_Error( ErrorCodes::ENCRYPTION_UNAVAILABLE, ErrorCodes::message( ErrorCodes::ENCRYPTION_UNAVAILABLE ) );
		}
	}

	/**
	 * Suggested webhook payload URL for a deployment, given the stored App.
	 *
	 * @param string $token Deployment webhook token.
	 * @return string
	 */
	public static function webhook_url( $token ) {
		return rest_url( 'githubsync/v1/webhook/' . rawurlencode( (string) $token ) );
	}

	/**
	 * Settings untouched by this class but read for defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		return Settings::all();
	}
}
