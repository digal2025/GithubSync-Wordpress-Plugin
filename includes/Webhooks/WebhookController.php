<?php
/**
 * GitHub push webhook endpoint.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Webhooks;

use GitHubSync\Database\DeliveryRepository;
use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\JobRepository;
use GitHubSync\Deployment\DeploymentManager;
use GitHubSync\GitHub\AppSetup;
use GitHubSync\Queue\Queue;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Support\Helpers;
use GitHubSync\Support\Logger;
use GitHubSync\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Publicly reachable route, authenticated purely by HMAC signature.
 *
 * The endpoint never downloads or deploys inside the request: it validates,
 * records, queues and returns.
 */
final class WebhookController {

	public const REST_NAMESPACE = 'githubsync/v1';

	/**
	 * Register the route.
	 *
	 * Two kinds of token reach the same handler:
	 *
	 * - a 32-character per-deployment token, which scopes the delivery to one
	 *   deployment and signs it with that deployment's secret;
	 * - a 64-character site-wide App secret, used as the GitHub App's single
	 *   webhook URL. That delivery is signed with the site secret and is routed
	 *   to every deployment whose repository and branch it matches.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/webhook/(?P<token>[a-f0-9]{32}|[a-f0-9]{64})',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$|^[a-f0-9]{64}$/', $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( self::is_app_token( $token ) ) {
			return self::handle_app_delivery( $request, $token );
		}

		$deployments = new DeploymentRepository();
		$row         = $deployments->find_by_token( $token );

		if ( ! $row ) {
			return self::respond( 404, 'unknown_endpoint' );
		}

		if ( self::is_rate_limited() ) {
			return self::respond( 429, 'rate_limited' );
		}

		$body = self::read_body( $request );

		if ( is_wp_error( $body ) ) {
			return self::respond( (int) $body->get_error_code(), $body->get_error_message() );
		}

		$secret = self::decrypt_secret( (string) $row['webhook_secret_encrypted'] );

		if ( '' === $secret ) {
			return self::respond( 500, 'endpoint_not_configured' );
		}

		$body = (string) $body;

		if ( ! self::signature_ok( $request, $body, $secret, (int) $row['id'] ) ) {
			return self::respond( 401, 'invalid_signature' );
		}

		$event = self::event( $request );

		if ( 'ping' === $event ) {
			return self::respond( 200, 'pong' );
		}

		if ( 'push' !== $event ) {
			return self::respond( 202, 'ignored_event' );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return self::respond( 400, 'malformed_payload' );
		}

		$result = self::process( $row, $event, $payload, self::delivery_id( $request, $body ) );

		return self::respond( $result['status'], $result['result'], $result['extra'] );
	}

	/**
	 * A site-wide GitHub App delivery: one hook URL for the whole site.
	 *
	 * The App's webhook secret signs every delivery, so a valid signature proves
	 * the request came from GitHub. The payload is then offered to every active
	 * deployment that has automatic deployment on; `process()` rejects the ones
	 * whose repository or branch does not match.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $token   Site-wide App secret from the route.
	 * @return WP_REST_Response
	 */
	private static function handle_app_delivery( WP_REST_Request $request, $token ) {
		if ( self::is_rate_limited() ) {
			return self::respond( 429, 'rate_limited' );
		}

		$stored = self::app_secret();

		if ( '' === $stored || ! hash_equals( $stored, (string) $token ) ) {
			return self::respond( 404, 'unknown_endpoint' );
		}

		$body = self::read_body( $request );

		if ( is_wp_error( $body ) ) {
			return self::respond( (int) $body->get_error_code(), $body->get_error_message() );
		}

		$body = (string) $body;

		if ( ! self::signature_ok( $request, $body, $stored, null ) ) {
			return self::respond( 401, 'invalid_signature' );
		}

		$event = self::event( $request );

		if ( 'ping' === $event ) {
			return self::respond( 200, 'pong' );
		}

		if ( 'push' !== $event ) {
			return self::respond( 202, 'ignored_event' );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return self::respond( 400, 'malformed_payload' );
		}

		$delivery_id = self::delivery_id( $request, $body );
		$queued      = 0;
		$first       = '';

		foreach ( ( new DeploymentRepository() )->auto_enabled() as $row ) {
			$result = self::process( $row, $event, $payload, $delivery_id );

			if ( '' === $first ) {
				$first = $result['result'];
			}

			if ( 'queued' === $result['result'] ) {
				++$queued;
			}
		}

		if ( 0 === $queued ) {
			return self::respond( 202, '' !== $first ? $first : 'no_matching_deployment' );
		}

		return self::respond( 202, 'queued', array( 'deployments' => $queued ) );
	}

	/**
	 * Record, validate and queue one delivery for one deployment.
	 *
	 * @param array  $row         Deployment row.
	 * @param string $event       GitHub event name.
	 * @param array  $payload     Decoded payload.
	 * @param string $delivery_id Delivery ID.
	 * @return array { status: int, result: string, extra: array }
	 */
	private static function process( array $row, $event, array $payload, $delivery_id ) {
		$deployment_id = (int) $row['id'];
		$deliveries    = new DeliveryRepository();

		// An App delivery is offered to every deployment, so a deployment that
		// does not match this repository must not reserve the delivery ID from
		// the deployment that does. Validate before recording.
		$verdict = self::validate_push( $payload, $row );

		if ( 'ok' !== $verdict['status'] ) {
			return self::outcome( 202, $verdict['status'] );
		}

		if ( ! $deliveries->record( $delivery_id, $deployment_id, $event ) ) {
			return self::outcome( 202, 'duplicate_delivery' );
		}

		if ( ! (int) $row['auto_deploy'] ) {
			$deliveries->set_status( $delivery_id, 'ignored' );
			return self::outcome( 202, 'auto_deploy_disabled' );
		}

		if ( 'active' !== (string) $row['status'] ) {
			$deliveries->set_status( $delivery_id, 'ignored' );
			return self::outcome( 202, 'configuration_paused' );
		}

		$queue  = new Queue( new JobRepository() );
		$job_id = $queue->enqueue(
			$deployment_id,
			$verdict['sha'],
			'webhook',
			(int) Settings::get( 'max_attempts', 3 )
		);

		if ( is_wp_error( $job_id ) ) {
			$deliveries->set_status( $delivery_id, 'error' );
			return self::outcome( 500, 'queue_failed' );
		}

		$deliveries->set_status( $delivery_id, 'queued' );

		( new Logger() )->log(
			'info',
			'WEBHOOK_QUEUED',
			__( 'Webhook accepted and deployment queued.', 'githubsync' ),
			array(
				'delivery' => substr( $delivery_id, 0, 64 ),
				'commit'   => $verdict['sha'],
				'job'      => (int) $job_id,
			),
			$deployment_id,
			(int) $job_id
		);

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return self::outcome( 202, 'queued', array( 'job_id' => (int) $job_id ) );
	}

	/**
	 * Bundle a processing outcome.
	 *
	 * @param int    $status HTTP status.
	 * @param string $result Result code.
	 * @param array  $extra  Extra fields.
	 * @return array
	 */
	private static function outcome( $status, $result, array $extra = array() ) {
		return array(
			'status' => (int) $status,
			'result' => (string) $result,
			'extra'  => $extra,
		);
	}

	/**
	 * Read the body, refusing payloads over the configured cap.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|\WP_Error Body, or an error whose message is the result code.
	 */
	private static function read_body( WP_REST_Request $request ) {
		$max_bytes = (int) Settings::get( 'max_webhook_bytes', 1048576 );
		$body      = (string) $request->get_body();

		if ( strlen( $body ) > $max_bytes ) {
			return new \WP_Error( 413, 'payload_too_large' );
		}

		return $body;
	}

	/**
	 * Verify the HMAC signature, logging and counting a rejection.
	 *
	 * @param WP_REST_Request $request       Request.
	 * @param string          $body          Raw body.
	 * @param string          $secret        Expected secret.
	 * @param int|null        $deployment_id Deployment the delivery belongs to, if any.
	 * @return bool
	 */
	private static function signature_ok( WP_REST_Request $request, $body, $secret, $deployment_id ) {
		$signature = (string) $request->get_header( 'x-hub-signature-256' );

		if ( SignatureVerifier::verify( $body, $signature, $secret ) ) {
			return true;
		}

		self::record_failure();

		( new Logger() )->log(
			'warning',
			ErrorCodes::WEBHOOK_SIGNATURE_INVALID,
			__( 'Rejected a webhook with an invalid signature.', 'githubsync' ),
			array( 'delivery' => substr( (string) $request->get_header( 'x-github-delivery' ), 0, 64 ) ),
			$deployment_id,
			null
		);

		return false;
	}

	/**
	 * Lower-cased event name.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function event( WP_REST_Request $request ) {
		return strtolower( (string) $request->get_header( 'x-github-event' ) );
	}

	/**
	 * Delivery ID, falling back to a hash of the body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $body    Raw body.
	 * @return string
	 */
	private static function delivery_id( WP_REST_Request $request, $body ) {
		$delivery_id = (string) $request->get_header( 'x-github-delivery' );

		if ( '' === $delivery_id ) {
			$delivery_id = 'sha1-' . sha1( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_sha1
		}

		return $delivery_id;
	}

	/**
	 * Whether a token is the site-wide App secret rather than a deployment token.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function is_app_token( $token ) {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $token );
	}

	/**
	 * The stored site-wide App webhook secret, read without generating one.
	 *
	 * Reading only matters here: a webhook must never mint a secret, because
	 * that would silently invalidate the URL already registered at GitHub.
	 *
	 * @return string Empty when no App secret exists.
	 */
	private static function app_secret() {
		return AppSetup::app_webhook_secret();
	}

	/**
	 * Validate repository, ref and commit.
	 *
	 * @param array $payload Webhook payload.
	 * @param array $row     Deployment row.
	 * @return array { status: string, sha: string }
	 */
	private static function validate_push( array $payload, array $row ) {
		$repo_id   = isset( $payload['repository']['id'] ) ? (int) $payload['repository']['id'] : 0;
		$repo_name = isset( $payload['repository']['full_name'] ) ? (string) $payload['repository']['full_name'] : '';
		$ref       = isset( $payload['ref'] ) ? (string) $payload['ref'] : '';

		if ( $repo_id !== (int) $row['github_repository_id'] ) {
			return array( 'status' => 'repository_mismatch', 'sha' => '' );
		}

		if ( '' !== $repo_name && strcasecmp( $repo_name, (string) $row['repository_full_name'] ) !== 0 ) {
			return array( 'status' => 'repository_mismatch', 'sha' => '' );
		}

		$expected_ref = 'refs/heads/' . (string) $row['branch'];

		if ( ! hash_equals( $expected_ref, $ref ) ) {
			return array( 'status' => 'branch_mismatch', 'sha' => '' );
		}

		if ( ! empty( $payload['deleted'] ) ) {
			return array( 'status' => 'branch_deleted', 'sha' => '' );
		}

		$sha = isset( $payload['after'] ) ? strtolower( (string) $payload['after'] ) : '';

		if ( ! preg_match( '/^[a-f0-9]{40,64}$/', $sha ) || preg_match( '/^0+$/', $sha ) ) {
			return array( 'status' => 'invalid_commit', 'sha' => '' );
		}

		return array( 'status' => 'ok', 'sha' => $sha );
	}

	/**
	 * Decrypt the stored webhook secret.
	 *
	 * @param string $stored Ciphertext.
	 * @return string Empty on failure.
	 */
	private static function decrypt_secret( $stored ) {
		if ( '' === $stored ) {
			return '';
		}

		try {
			$vault = CredentialVault::from_site();
			return $vault->decrypt( $stored );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Build a JSON response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $result Machine-readable result.
	 * @param array  $extra  Extra fields.
	 * @return WP_REST_Response
	 */
	private static function respond( $status, $result, array $extra = array() ) {
		return new WP_REST_Response(
			array_merge(
				array(
					'githubsync' => true,
					'result'     => $result,
				),
				$extra
			),
			(int) $status
		);
	}

	/**
	 * Failure counter key for the current client.
	 *
	 * @return string
	 */
	private static function failure_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'githubsync_wh_fail_' . md5( $ip );
	}

	/**
	 * Whether this client has exceeded the invalid-webhook allowance.
	 *
	 * @return bool
	 */
	private static function is_rate_limited() {
		$count = (int) get_transient( self::failure_key() );
		return $count >= 30;
	}

	/**
	 * Increment the invalid-webhook counter.
	 *
	 * @return void
	 */
	private static function record_failure() {
		$key   = self::failure_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Payload URL for a deployment (shown in the admin).
	 *
	 * @param string $token Webhook token.
	 * @return string
	 */
	public static function payload_url( $token ) {
		return rest_url( self::REST_NAMESPACE . '/webhook/' . $token );
	}
}
