<?php
/**
 * GitHub authentication: App installation tokens (preferred) or PAT.
 *
 * @package GitHubSync
 */

namespace GitHubSync\GitHub;

use GitHubSync\Domain\Exceptions\GitHubSyncException;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Support\Helpers;
use GitHubSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a bearer token for the GitHub API and manages connection state.
 *
 * Connection metadata lives in `githubsync_github_connection`; secrets live
 * encrypted in their own options so they never appear alongside display data.
 */
final class GitHubAuth {

	public const OPTION_CONNECTION = 'githubsync_github_connection';
	public const OPTION_APP_KEY    = 'githubsync_github_app_key';
	public const OPTION_PAT        = 'githubsync_github_pat';
	public const OPTION_SETUP      = 'githubsync_app_setup';
	public const TOKEN_TRANSIENT   = 'githubsync_gh_install_token_';
	public const SETUP_TRANSIENT   = 'githubsync_app_setup_result_';

	/**
	 * Cached installation token for this request.
	 *
	 * @var string|null
	 */
	private $runtime_token = null;

	/**
	 * Stored connection metadata.
	 *
	 * @return array
	 */
	public function connection() {
		$stored = get_option( self::OPTION_CONNECTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return wp_parse_args(
			$stored,
			array(
				'mode'             => '',
				'app_id'           => '',
				'installation_id'  => '',
				'account'          => '',
				'display_name'     => '',
				'connected_at'     => '',
				'token_expires_at' => 0,
			)
		);
	}

	/**
	 * Whether a usable connection is configured.
	 *
	 * An App created by the manifest flow is stored before it is installed,
	 * so `mode => app` with a blank installation ID is not a connection yet;
	 * use `has_app()` to detect that state.
	 *
	 * @return bool
	 */
	public function is_connected() {
		$connection = $this->connection();

		if ( 'pat' === $connection['mode'] ) {
			return true;
		}

		return 'app' === $connection['mode'] && '' !== (string) $connection['installation_id'];
	}

	/**
	 * Whether an App ID and private key are stored, installed or not.
	 *
	 * The one-click manifest flow stores the App before it is installed, so
	 * this can be true while `is_connected()` is still false.
	 *
	 * @return bool
	 */
	public function has_app() {
		return '' !== $this->stored_app_id() && '' !== (string) get_option( self::OPTION_APP_KEY, '' );
	}

	/**
	 * Human-readable connection summary.
	 *
	 * @return string
	 */
	public function summary() {
		$connection = $this->connection();

		if ( 'app' === $connection['mode'] ) {
			return sprintf(
				/* translators: 1: GitHub account, 2: installation id. */
				__( 'GitHub App installation on %1$s (installation %2$s)', 'githubsync' ),
				$connection['account'] ? $connection['account'] : '—',
				$connection['installation_id']
			);
		}

		if ( 'pat' === $connection['mode'] ) {
			return sprintf(
				/* translators: %s: GitHub login. */
				__( 'Personal access token for %s', 'githubsync' ),
				$connection['account'] ? $connection['account'] : '—'
			);
		}

		return __( 'Not connected', 'githubsync' );
	}

	/**
	 * Resolve a bearer token for API calls.
	 *
	 * @return string|\WP_Error
	 */
	public function get_token() {
		if ( is_string( $this->runtime_token ) && '' !== $this->runtime_token ) {
			return $this->runtime_token;
		}

		$connection = $this->connection();

		try {
			$vault = CredentialVault::from_site();
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( ErrorCodes::ENCRYPTION_UNAVAILABLE, ErrorCodes::message( ErrorCodes::ENCRYPTION_UNAVAILABLE ) );
		}

		if ( 'pat' === $connection['mode'] ) {
			$stored = (string) get_option( self::OPTION_PAT, '' );

			if ( '' === $stored ) {
				return new \WP_Error( ErrorCodes::AUTH_INVALID, ErrorCodes::message( ErrorCodes::AUTH_INVALID ) );
			}

			try {
				$token = $vault->decrypt( $stored );
			} catch ( GitHubSyncException $e ) {
				return new \WP_Error( $e->get_error_code(), $e->getMessage() );
			}

			$this->runtime_token = $token;
			return $token;
		}

		if ( 'app' === $connection['mode'] ) {
			return $this->installation_token( $vault, $connection );
		}

		return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'GitHub is not connected.', 'githubsync' ) );
	}

	/**
	 * Mint or reuse a GitHub App installation token.
	 *
	 * @param CredentialVault $vault      Vault.
	 * @param array           $connection Connection metadata.
	 * @return string|\WP_Error
	 */
	private function installation_token( CredentialVault $vault, array $connection ) {
		// An App that is created but not installed has no installation ID yet.
		if ( ! isset( $connection['installation_id'] ) || '' === preg_replace( '/[^0-9]/', '', (string) $connection['installation_id'] ) ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'The GitHub App is not installed on any account yet. Install it, then connect again.', 'githubsync' ) );
		}

		$cache_key = self::TOKEN_TRANSIENT . $connection['installation_id'];
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			try {
				$token               = $vault->decrypt( $cached );
				$this->runtime_token = $token;
				return $token;
			} catch ( GitHubSyncException $e ) {
				delete_transient( $cache_key );
			}
		}

		$jwt = $this->app_jwt( $vault, $connection );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$url = sprintf( 'https://api.github.com/app/installations/%s/access_tokens', rawurlencode( (string) $connection['installation_id'] ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'        => 'Bearer ' . $jwt,
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'GitHubSync/' . GITHUBSYNC_VERSION,
				),
				'body'        => '{}',
			)
		);

		$result = GitHubResponse::from_wp( $response );

		if ( ! $result->is_ok() || ! is_array( $result->data ) || empty( $result->data['token'] ) ) {
			if ( 401 === $result->status ) {
				return new \WP_Error( ErrorCodes::AUTH_INVALID, ErrorCodes::message( ErrorCodes::AUTH_INVALID ) );
			}
			return new \WP_Error(
				ErrorCodes::AUTH_PERMISSION_DENIED,
				$result->error_message() ? $result->error_message() : ErrorCodes::message( ErrorCodes::AUTH_PERMISSION_DENIED )
			);
		}

		$token      = (string) $result->data['token'];
		$expires_at = isset( $result->data['expires_at'] ) ? strtotime( (string) $result->data['expires_at'] ) : 0;
		$ttl        = $expires_at > 0 ? max( 60, $expires_at - time() - 120 ) : 3000;

		try {
			set_transient( $cache_key, $vault->encrypt( $token ), min( $ttl, 3300 ) );
		} catch ( GitHubSyncException $e ) {
			// A cache failure must not block the request; the token still works.
			unset( $e );
		}

		$this->runtime_token = $token;

		$connection['token_expires_at'] = $expires_at;
		update_option( self::OPTION_CONNECTION, $connection, false );

		return $token;
	}

	/**
	 * Build a signed RS256 JWT for the GitHub App.
	 *
	 * @param CredentialVault $vault      Vault.
	 * @param array           $connection Connection metadata.
	 * @return string|\WP_Error
	 */
	private function app_jwt( CredentialVault $vault, array $connection ) {
		// The JWT is App-level: only the App ID is needed, and this also covers
		// app-only calls that pass an installation ID of "0". Empty() is not used
		// because it would treat "0" as missing.
		if ( ! isset( $connection['app_id'] ) || '' === preg_replace( '/[^0-9]/', '', (string) $connection['app_id'] ) ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'A GitHub App ID is required.', 'githubsync' ) );
		}

		$stored_key = (string) get_option( self::OPTION_APP_KEY, '' );

		if ( '' === $stored_key ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'No GitHub App private key is stored.', 'githubsync' ) );
		}

		try {
			$private_key = $vault->decrypt( $stored_key );
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		$key_resource = openssl_pkey_get_private( $private_key );
		if ( false === $key_resource ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'The stored GitHub App private key is not a valid PEM key.', 'githubsync' ) );
		}

		$now = time();

		$header  = $this->base64url( (string) wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$payload = $this->base64url(
			(string) wp_json_encode(
				array(
					'iat' => $now - 60,
					'exp' => $now + 540,
					'iss' => (string) $connection['app_id'],
				)
			)
		);

		$signing_input = $header . '.' . $payload;
		$signature     = '';

		if ( ! openssl_sign( $signing_input, $signature, $key_resource, OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( ErrorCodes::INTERNAL_ERROR, __( 'Could not sign the GitHub App JWT.', 'githubsync' ) );
		}

		return $signing_input . '.' . $this->base64url( $signature );
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function base64url( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Store a GitHub App connection.
	 *
	 * @param string $app_id          App ID.
	 * @param string $installation_id Installation ID.
	 * @param string $private_key     PEM private key.
	 * @param array  $identity        Optional account identity.
	 * @return true|\WP_Error
	 */
	public function connect_app( $app_id, $installation_id, $private_key, array $identity = array() ) {
		$app_id          = preg_replace( '/[^0-9]/', '', (string) $app_id );
		$installation_id = preg_replace( '/[^0-9]/', '', (string) $installation_id );

		// The one-click manifest flow creates the App before it is installed,
		// so it stores the key with no installation and adds one afterwards.
		if ( '' === $app_id || ( '' === $installation_id && '' === trim( (string) $private_key ) ) ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'App ID and installation ID must be numeric.', 'githubsync' ) );
		}

		if ( '' === trim( (string) $private_key ) ) {
			$stored = (string) get_option( self::OPTION_APP_KEY, '' );

			if ( '' === $stored ) {
				return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'A private key is required.', 'githubsync' ) );
			}

			try {
				$private_key = CredentialVault::from_site()->decrypt( $stored );
			} catch ( GitHubSyncException $e ) {
				return new \WP_Error( $e->get_error_code(), $e->getMessage() );
			}
		}

		if ( false === openssl_pkey_get_private( $private_key ) ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'That does not look like a valid PEM private key.', 'githubsync' ) );
		}

		try {
			$vault = CredentialVault::from_site();
			$enc   = $vault->encrypt( $private_key );
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		update_option( self::OPTION_APP_KEY, $enc, false );
		delete_option( self::OPTION_PAT );

		update_option(
			self::OPTION_CONNECTION,
			array(
				'mode'             => 'app',
				'app_id'           => $app_id,
				'installation_id'  => $installation_id,
				'account'          => isset( $identity['account'] ) ? sanitize_text_field( (string) $identity['account'] ) : '',
				'display_name'     => isset( $identity['display_name'] ) ? sanitize_text_field( (string) $identity['display_name'] ) : '',
				'connected_at'     => Helpers::now(),
				'token_expires_at' => 0,
			),
			false
		);

		delete_transient( self::TOKEN_TRANSIENT . $installation_id );
		$this->runtime_token = null;

		return true;
	}

	/**
	 * Store a PAT connection.
	 *
	 * @param string $token Personal access token.
	 * @param array  $identity Optional account identity.
	 * @return true|\WP_Error
	 */
	public function connect_pat( $token, array $identity = array() ) {
		$token = trim( (string) $token );

		if ( ! preg_match( '/^(gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}$/', $token ) ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'That does not look like a GitHub token.', 'githubsync' ) );
		}

		try {
			$vault = CredentialVault::from_site();
			$enc   = $vault->encrypt( $token );
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		update_option( self::OPTION_PAT, $enc, false );
		delete_option( self::OPTION_APP_KEY );

		update_option(
			self::OPTION_CONNECTION,
			array(
				'mode'             => 'pat',
				'app_id'           => '',
				'installation_id'  => '',
				'account'          => isset( $identity['account'] ) ? sanitize_text_field( (string) $identity['account'] ) : '',
				'display_name'     => isset( $identity['display_name'] ) ? sanitize_text_field( (string) $identity['display_name'] ) : '',
				'connected_at'     => Helpers::now(),
				'token_expires_at' => 0,
			),
			false
		);

		$this->runtime_token = null;

		return true;
	}

	/**
	 * Remember display metadata about the App (slug, owner page).
	 *
	 * Kept apart from the connection so the page can offer "Install" and
	 * "Open on GitHub" links before an installation exists.
	 *
	 * @param array $meta { app_id, slug, html_url, client_id }.
	 * @return void
	 */
	public function remember_app_meta( array $meta ) {
		$current = get_option( self::OPTION_SETUP, array() );
		$current = is_array( $current ) ? $current : array();

		update_option(
			self::OPTION_SETUP,
			array_merge(
				$current,
				array(
					'app_id'    => isset( $meta['app_id'] ) ? (string) preg_replace( '/[^0-9]/', '', (string) $meta['app_id'] ) : '',
					'slug'      => isset( $meta['slug'] ) ? sanitize_key( (string) $meta['slug'] ) : '',
					'html_url'  => isset( $meta['html_url'] ) ? esc_url_raw( (string) $meta['html_url'] ) : '',
					'client_id' => isset( $meta['client_id'] ) ? sanitize_text_field( (string) $meta['client_id'] ) : '',
				)
			),
			false
		);
	}

	/**
	 * Display metadata for the stored App.
	 *
	 * @return array { app_id, slug, html_url, client_id }
	 */
	public function app_meta() {
		$stored = get_option( self::OPTION_SETUP, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return wp_parse_args(
			$stored,
			array(
				'app_id'    => '',
				'slug'      => '',
				'html_url'  => '',
				'client_id' => '',
			)
		);
	}

	/**
	 * Encrypt and store app-level webhook secrets in the settings option.
	 *
	 * The manifest conversion returns a webhook secret; it is stored with the
	 * same vault as every other credential so it never sits in plain text.
	 *
	 * @param array $secrets Map of settings key => plaintext secret.
	 * @return true|\WP_Error
	 */
	public function remember_secrets( array $secrets ) {
		if ( ! CredentialVault::is_configured() ) {
			return new \WP_Error( ErrorCodes::ENCRYPTION_UNAVAILABLE, ErrorCodes::message( ErrorCodes::ENCRYPTION_UNAVAILABLE ) );
		}

		try {
			$vault   = CredentialVault::from_site();
			$encrypt = array();

			foreach ( $secrets as $key => $value ) {
				$encrypt[ sanitize_key( (string) $key ) ] = $vault->encrypt( (string) $value );
			}
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		$settings = Settings::all();

		update_option( Settings::OPTION, array_merge( $settings, $encrypt ), false );

		return true;
	}

	/**
	 * Account identity behind an installation, looked up with the App JWT.
	 *
	 * @param string $installation_id Installation ID.
	 * @return array|\WP_Error { account, display_name, account_id, target_type }
	 */
	public function installation_identity( $installation_id ) {
		$installation_id = preg_replace( '/[^0-9]/', '', (string) $installation_id );
		$error           = $this->app_only_credentials();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( '' === $installation_id ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'Installation ID must be numeric.', 'githubsync' ) );
		}

		try {
			$vault = CredentialVault::from_site();
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		$jwt = $this->app_jwt( $vault, array( 'app_id' => $this->stored_app_id(), 'installation_id' => '0' ) );

		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_get(
			'https://api.github.com/app/installations/' . rawurlencode( $installation_id ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'        => 'Bearer ' . $jwt,
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'GitHubSync/' . GITHUBSYNC_VERSION,
				),
			)
		);

		$result = GitHubResponse::from_wp( $response );

		if ( ! $result->is_ok() || ! is_array( $result->data ) ) {
			if ( 404 === $result->status ) {
				return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'GitHub does not know that installation for this App. Install the App again.', 'githubsync' ) );
			}

			if ( 401 === $result->status ) {
				return new \WP_Error( ErrorCodes::AUTH_INVALID, ErrorCodes::message( ErrorCodes::AUTH_INVALID ) );
			}

			return new \WP_Error(
				ErrorCodes::AUTH_PERMISSION_DENIED,
				$result->error_message() ? $result->error_message() : ErrorCodes::message( ErrorCodes::AUTH_PERMISSION_DENIED )
			);
		}

		$data    = $result->data;
		$account = isset( $data['account']['login'] ) ? (string) $data['account']['login'] : '';

		return array(
			'installation_id' => $installation_id,
			'account'         => $account,
			'display_name'    => $account,
			'account_id'      => isset( $data['account']['id'] ) ? (string) (int) $data['account']['id'] : '',
			'target_type'     => isset( $data['target_type'] ) ? (string) $data['target_type'] : '',
		);
	}

	/**
	 * Installations visible to the stored App, as picked by the user.
	 *
	 * Each entry is { installation_id, account, account_id, target_type }.
	 * Used to let the owner choose an installation without copying IDs.
	 *
	 * @return array|\WP_Error
	 */
	public function app_installations() {
		$error = $this->app_only_credentials();

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		try {
			$vault = CredentialVault::from_site();
		} catch ( GitHubSyncException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage() );
		}

		$jwt = $this->app_jwt( $vault, array( 'app_id' => $this->stored_app_id(), 'installation_id' => '0' ) );

		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_get(
			'https://api.github.com/app/installations?per_page=100',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'        => 'Bearer ' . $jwt,
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'GitHubSync/' . GITHUBSYNC_VERSION,
				),
			)
		);

		$result = GitHubResponse::from_wp( $response );

		if ( ! $result->is_ok() || ! is_array( $result->data ) ) {
			if ( 401 === $result->status ) {
				return new \WP_Error( ErrorCodes::AUTH_INVALID, ErrorCodes::message( ErrorCodes::AUTH_INVALID ) );
			}

			return new \WP_Error(
				ErrorCodes::AUTH_PERMISSION_DENIED,
				$result->error_message() ? $result->error_message() : ErrorCodes::message( ErrorCodes::AUTH_PERMISSION_DENIED )
			);
		}

		$installations = array();

		foreach ( $result->data as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$installations[] = array(
				'installation_id' => (string) (int) $item['id'],
				'account'         => isset( $item['account']['login'] ) ? (string) $item['account']['login'] : '',
				'account_id'      => isset( $item['account']['id'] ) ? (string) (int) $item['account']['id'] : '',
				'target_type'     => isset( $item['target_type'] ) ? (string) $item['target_type'] : '',
			);
		}

		return $installations;
	}

	/**
	 * Attach an installation to an App whose key is already stored.
	 *
	 * @param string $app_id          App ID.
	 * @param string $installation_id Installation ID.
	 * @param array  $identity        Optional { account, display_name }.
	 * @return true|\WP_Error
	 */
	public function complete_installation( $app_id, $installation_id, array $identity = array() ) {
		$app_id          = preg_replace( '/[^0-9]/', '', (string) $app_id );
		$installation_id = preg_replace( '/[^0-9]/', '', (string) $installation_id );

		if ( '' === $app_id || '' === $installation_id ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'App ID and installation ID must be numeric.', 'githubsync' ) );
		}

		$connection = $this->connection();

		if ( 'app' !== $connection['mode'] || (string) $connection['app_id'] !== $app_id ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'The stored App does not match this setup. Start again from the connection page.', 'githubsync' ) );
		}

		if ( ! empty( $connection['installation_id'] ) && (string) $connection['installation_id'] !== $installation_id ) {
			delete_transient( self::TOKEN_TRANSIENT . $connection['installation_id'] );
		}

		$connection['installation_id'] = $installation_id;
		$connection['account']         = isset( $identity['account'] ) ? sanitize_text_field( (string) $identity['account'] ) : $connection['account'];
		$connection['display_name']    = isset( $identity['display_name'] ) ? sanitize_text_field( (string) $identity['display_name'] ) : $connection['display_name'];
		$connection['connected_at']    = Helpers::now();

		update_option( self::OPTION_CONNECTION, $connection, false );

		$this->runtime_token = null;

		return true;
	}

	/**
	 * Store a finished setup result so the connection page can show it.
	 *
	 * @param string $state  Setup state.
	 * @param array  $result Setup metadata.
	 * @return void
	 */
	public function remember_setup_result( $state, array $result ) {
		set_transient( self::SETUP_TRANSIENT . (string) $state, $result, 300 );
	}

	/**
	 * Read and clear a finished setup result.
	 *
	 * @param string $state Setup state.
	 * @return array|null
	 */
	public function take_setup_result( $state ) {
		$key    = self::SETUP_TRANSIENT . (string) $state;
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * The stored App ID, from the connection or from the App key record.
	 *
	 * @return string
	 */
	private function stored_app_id() {
		$connection = $this->connection();

		if ( '' !== (string) $connection['app_id'] ) {
			return (string) $connection['app_id'];
		}

		$setup = get_option( self::OPTION_SETUP, array() );

		return is_array( $setup ) && ! empty( $setup['app_id'] ) ? (string) $setup['app_id'] : '';
	}

	/**
	 * Require a stored App ID and private key, with no installation needed yet.
	 *
	 * @return true|\WP_Error
	 */
	private function app_only_credentials() {
		if ( '' === $this->stored_app_id() ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'No GitHub App ID is stored yet.', 'githubsync' ) );
		}

		if ( '' === (string) get_option( self::OPTION_APP_KEY, '' ) ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, __( 'No GitHub App private key is stored.', 'githubsync' ) );
		}

		return true;
	}

	/**
	 * Remove stored credentials.
	 *
	 * @return void
	 */
	public function disconnect() {
		$connection = $this->connection();

		if ( ! empty( $connection['installation_id'] ) ) {
			delete_transient( self::TOKEN_TRANSIENT . $connection['installation_id'] );
		}

		delete_option( self::OPTION_APP_KEY );
		delete_option( self::OPTION_PAT );
		delete_option( self::OPTION_CONNECTION );

		$this->runtime_token = null;
	}
}
