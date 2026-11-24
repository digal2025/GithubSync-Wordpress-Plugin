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
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/webhook/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
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

		$deployments = new DeploymentRepository();
		$row         = $deployments->find_by_token( $token );

		if ( ! $row ) {
			return self::respond( 404, 'unknown_endpoint' );
		}

		$deployment_id = (int) $row['id'];

		if ( self::is_rate_limited() ) {
			return self::respond( 429, 'rate_limited' );
		}

		$max_bytes = (int) Settings::get( 'max_webhook_bytes', 1048576 );
		$body      = (string) $request->get_body();

		if ( strlen( $body ) > $max_bytes ) {
			return self::respond( 413, 'payload_too_large' );
		}

		$secret = self::decrypt_secret( (string) $row['webhook_secret_encrypted'] );

		if ( '' === $secret ) {
			return self::respond( 500, 'endpoint_not_configured' );
		}

		$signature = (string) $request->get_header( 'x-hub-signature-256' );

		if ( ! SignatureVerifier::verify( $body, $signature, $secret ) ) {
			self::record_failure();

			( new Logger() )->log(
				'warning',
				ErrorCodes::WEBHOOK_SIGNATURE_INVALID,
				__( 'Rejected a webhook with an invalid signature.', 'githubsync' ),
				array( 'delivery' => substr( (string) $request->get_header( 'x-github-delivery' ), 0, 64 ) ),
				$deployment_id,
				null
			);

			return self::respond( 401, 'invalid_signature' );
		}

		$event = strtolower( (string) $request->get_header( 'x-github-event' ) );

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

		$delivery_id = (string) $request->get_header( 'x-github-delivery' );
		if ( '' === $delivery_id ) {
			$delivery_id = 'sha1-' . sha1( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_sha1
		}

		$deliveries = new DeliveryRepository();

		if ( ! $deliveries->record( $delivery_id, $deployment_id, $event ) ) {
			return self::respond( 202, 'duplicate_delivery' );
		}

		$verdict = self::validate_push( $payload, $row );

		if ( 'ok' !== $verdict['status'] ) {
			$deliveries->set_status( $delivery_id, 'ignored' );
			return self::respond( 202, $verdict['status'] );
		}

		if ( ! (int) $row['auto_deploy'] ) {
			$deliveries->set_status( $delivery_id, 'ignored' );
			return self::respond( 202, 'auto_deploy_disabled' );
		}

		if ( 'active' !== (string) $row['status'] ) {
			$deliveries->set_status( $delivery_id, 'ignored' );
			return self::respond( 202, 'configuration_paused' );
		}

		$jobs    = new JobRepository();
		$queue   = new Queue( $jobs );
		$job_id  = $queue->enqueue(
			$deployment_id,
			$verdict['sha'],
			'webhook',
			(int) Settings::get( 'max_attempts', 3 )
		);

		if ( is_wp_error( $job_id ) ) {
			$deliveries->set_status( $delivery_id, 'error' );
			return self::respond( 500, 'queue_failed' );
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

		return self::respond( 202, 'queued', array( 'job_id' => (int) $job_id ) );
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
