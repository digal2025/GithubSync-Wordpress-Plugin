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
	public const TOKEN_TRANSIENT   = 'githubsync_gh_install_token_';

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
	 * Whether a connection is configured.
	 *
	 * @return bool
	 */
	public function is_connected() {
		$connection = $this->connection();
		return in_array( $connection['mode'], array( 'app', 'pat' ), true );
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
		if ( empty( $connection['app_id'] ) || empty( $connection['installation_id'] ) ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'GitHub App ID and installation ID are required.', 'githubsync' ) );
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

		if ( '' === $app_id || '' === $installation_id ) {
			return new \WP_Error( ErrorCodes::CONFIG_INVALID, __( 'App ID and installation ID must be numeric.', 'githubsync' ) );
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
