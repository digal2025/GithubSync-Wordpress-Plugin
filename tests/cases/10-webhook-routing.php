<?php
/**
 * Webhook routing: per-deployment tokens and the site-wide App secret.
 *
 * The controller is exercised directly (`WebhookController::handle()`) with a
 * synthetic request, so no HTTP server is involved. Deliveries, jobs and
 * deployment rows are created and cleaned up in the real tables, because the
 * point of these cases is the routing between the two token shapes, which is
 * exactly what the database lookups decide.
 *
 * @package GitHubSync
 */

use GitHubSync\Database\DeliveryRepository;
use GitHubSync\Database\DeploymentRepository;
use GitHubSync\Database\Schema;
use GitHubSync\GitHub\AppSetup;
use GitHubSync\Support\Settings;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;
use GitHubSync\Webhooks\WebhookController;

CaseRunner::group( 'Webhook routing (deployment token vs App secret)' );

global $wpdb;

$app_secret = bin2hex( random_bytes( 32 ) );
$old_secret = AppSetup::app_webhook_secret();
$old_option = array( get_option( Settings::OPTION, null ), false !== get_option( Settings::OPTION, false ) );

/**
 * Store a site-wide App secret for the duration of this file.
 *
 * @param string $secret Secret, or "" to remove it.
 * @return void
 */
$set_app_secret = static function ( $secret ) {
	$settings = get_option( Settings::OPTION, array() );
	$settings = is_array( $settings ) ? $settings : array();

	if ( '' === $secret ) {
		unset( $settings['app_webhook_secret'] );
	} else {
		$settings['app_webhook_secret'] = $secret;
	}

	update_option( Settings::OPTION, $settings, false );
};

/**
 * Insert a deployment row.
 *
 * @param DeploymentRepository $repo          Repository.
 * @param string               $suffix        Token suffix to keep rows apart.
 * @param int                  $repository_id Fake GitHub repository ID.
 * @return int
 */
$make_deployment = static function ( DeploymentRepository $repo, $suffix, $repository_id ) {
	$id = $repo->create(
		array(
			'name'                 => 'Webhook route ' . $suffix,
			'github_repository_id' => (int) $repository_id,
			'repository_full_name' => 'route/test-' . $suffix,
			'branch'               => 'main',
			'destination_path'     => GITHUBSYNC_TEST_TMP . '/route-' . $suffix,
			'deployment_type'      => 'plugin',
			'destination_slug'     => 'route-' . $suffix,
			'auto_deploy'          => 1,
			'webhook_token'        => md5( 'route-' . $suffix ),
		)
	);

	return (int) $id;
};

$repo     = new DeploymentRepository();
$run_id   = (int) ( 700000 + ( getmypid() % 9000 ) * 7 );
$suffix   = $run_id . 'x' . substr( (string) time(), -5 );
$matching = $make_deployment( $repo, $suffix, $run_id );
$other    = $make_deployment( $repo, 'other' . $suffix, $run_id + 1 );
$row      = $repo->find( $matching );

$push = array(
	'repository' => array(
		'id'        => $run_id,
		'full_name' => 'route/test-' . $suffix,
	),
	'ref'        => 'refs/heads/main',
	'after'      => str_repeat( 'a', 40 ),
);

$delivery = 'route-' . md5( $suffix );

/**
 * Build a request the way `register_rest_route()` would hand one over.
 *
 * @param string $token     Route token.
 * @param string $event     X-GitHub-Event value.
 * @param array  $payload   Payload.
 * @param string $sign_with Secret to sign with, or "" to omit the header.
 * @param string $delivery  Delivery ID.
 * @return WP_REST_Request
 */
$request = static function ( $token, $event, array $payload, $sign_with, $delivery = '' ) {
	$body    = (string) wp_json_encode( $payload );
	$request = new WP_REST_Request( 'POST', '/githubsync/v1/webhook/' . $token );

	$request->set_param( 'token', $token );
	$request->set_body( $body );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'x-github-event', $event );

	if ( '' !== $delivery ) {
		$request->set_header( 'x-github-delivery', $delivery );
	}

	if ( '' !== $sign_with ) {
		$request->set_header( 'x-hub-signature-256', 'sha256=' . hash_hmac( 'sha256', $body, $sign_with ) );
	}

	return $request;
};

/**
 * Result code, status and payload of a response.
 *
 * @param WP_REST_Response $response Response.
 * @return array { status: int, result: string, data: array }
 */
$read = static function ( $response ) {
	$data = $response->get_data();

	return array(
		'status' => (int) $response->get_status(),
		'result' => isset( $data['result'] ) ? (string) $data['result'] : '',
		'data'   => is_array( $data ) ? $data : array(),
	);
};

try {
	CaseRunner::case( 'the App secret is 64 hex characters and is only created on demand' );
	$set_app_secret( '' );
	Assert::same( '', AppSetup::app_webhook_secret(), 'reading does not mint a secret' );
	Assert::same( 64, strlen( AppSetup::hook_secret() ), 'hook_secret() creates a 32-byte hex secret' );
	Assert::same( AppSetup::hook_secret(), AppSetup::app_webhook_secret(), 'hook_secret() returns the stored value' );
	$set_app_secret( $app_secret );

	CaseRunner::case( 'a valid App delivery queues the matching deployment only' );
	$before = ( new DeliveryRepository() )->purge_older_than( 0 );
	$out    = $read( WebhookController::handle( $request( $app_secret, 'push', $push, $app_secret, $delivery ) ) );
	Assert::same( 202, $out['status'], 'App delivery answers 202' );
	Assert::same( 'queued', $out['result'], 'App delivery is queued' );
	Assert::same( 1, isset( $out['data']['deployments'] ) ? $out['data']['deployments'] : 0, 'exactly one deployment queued' );
	Assert::true( $before >= 0, 'delivery purge ran' );

	$deliveries = new DeliveryRepository();
	$recorded   = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::deliveries() . ' WHERE delivery_id = %s', $delivery ) // phpcs:ignore WordPress.DB
	);
	Assert::same( 1, $recorded, 'the delivery ID is recorded once, for the matching deployment' );
	Assert::same( 'queued', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT processing_status FROM ' . Schema::deliveries() . ' WHERE delivery_id = %s AND deployment_id = %d', $delivery, $matching ) ), 'the matching deployment owns the delivery' );

	CaseRunner::case( 'the same delivery cannot be replayed into the matching deployment' );
	$replay = $read( WebhookController::handle( $request( $app_secret, 'push', $push, $app_secret, $delivery ) ) );
	Assert::false( isset( $replay['data']['deployments'] ), 'nothing is queued on replay' );
	Assert::same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::jobs() . ' WHERE deployment_id = %d', $matching ) ), 'the replay creates no second job' );
	Assert::same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::deliveries() . ' WHERE delivery_id = %s', $delivery ) ), 'the replay records nothing new' );

	CaseRunner::case( 'a repository mismatch does not reserve the delivery for the other deployment' );
	$foreign = 'route-foreign-' . $suffix;
	$miss    = $push;
	$miss['repository']['id']        = 123456;
	$miss['repository']['full_name'] = 'nobody/elsewhere';
	$out = $read( WebhookController::handle( $request( $app_secret, 'push', $miss, $app_secret, $foreign ) ) );
	Assert::same( 202, $out['status'], 'a non-matching App delivery answers 202' );
	Assert::same( 'repository_mismatch', $out['result'], 'the mismatch is reported' );
	$leftover = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::deliveries() . ' WHERE delivery_id = %s', $foreign ) ); // phpcs:ignore WordPress.DB
	Assert::same( 0, $leftover, 'a non-matching deployment records nothing' );

	CaseRunner::case( 'a 64-character token that is not the App secret is unknown' );
	$wrong = $read( WebhookController::handle( $request( str_repeat( 'f', 64 ), 'push', $push, $app_secret, 'route-wrong-' . $suffix ) ) );
	Assert::same( 404, $wrong['status'], 'an unregistered 64-hex token answers 404' );
	Assert::same( 'unknown_endpoint', $wrong['result'], 'and says unknown_endpoint' );

	CaseRunner::case( 'no stored App secret means no App endpoint' );
	$set_app_secret( '' );
	$absent = $read( WebhookController::handle( $request( $app_secret, 'push', $push, $app_secret, 'route-absent-' . $suffix ) ) );
	Assert::same( 404, $absent['status'], 'without a stored secret the route is unknown' );
	Assert::same( 'unknown_endpoint', $absent['result'], 'no secret, no endpoint' );
	$set_app_secret( $app_secret );

	CaseRunner::case( 'an App delivery signed with the wrong key is rejected' );
	$bad = $read( WebhookController::handle( $request( $app_secret, 'push', $push, str_repeat( 'b', 64 ), 'route-bad-' . $suffix ) ) );
	Assert::same( 401, $bad['status'], 'a bad signature answers 401' );
	Assert::same( 'invalid_signature', $bad['result'], 'and says invalid_signature' );
	$unsigned = $read( WebhookController::handle( $request( $app_secret, 'push', $push, '', 'route-unsigned-' . $suffix ) ) );
	Assert::same( 401, $unsigned['status'], 'a missing signature answers 401' );

	CaseRunner::case( 'a ping on the App route is acknowledged without queueing' );
	$ping = $read( WebhookController::handle( $request( $app_secret, 'ping', array( 'zen' => 'hi' ), $app_secret, 'route-ping-' . $suffix ) ) );
	Assert::same( 200, $ping['status'], 'ping answers 200' );
	Assert::same( 'pong', $ping['result'], 'ping answers pong' );

	CaseRunner::case( 'a deployment token still scopes the delivery to that deployment' );
	$token = (string) $row['webhook_token'];
	$dep   = '';

	try {
		$vault     = \GitHubSync\Security\CredentialVault::from_site();
		$dep_secret = \GitHubSync\Webhooks\SignatureVerifier::generate_secret();
		$dep       = $vault->encrypt( $dep_secret );
	} catch ( \Throwable $e ) {
		$dep = '';
	}

	if ( '' === $dep ) {
		Assert::skip( 'no encryption key configured: the per-deployment secret cannot be stored' );
	} else {
		$wpdb->update( Schema::deployments(), array( 'webhook_secret_encrypted' => $dep ), array( 'id' => $matching ) ); // phpcs:ignore WordPress.DB

		$dep_delivery = 'route-dep-' . $suffix;
		$ok           = $read( WebhookController::handle( $request( $token, 'push', $push, $dep_secret, $dep_delivery ) ) );
		Assert::same( 202, $ok['status'], 'a signed deployment delivery answers 202' );
		Assert::same( 'queued', $ok['result'], 'a signed deployment delivery is queued' );
		Assert::false( isset( $ok['data']['deployments'] ), 'a deployment token reports no App fan-out' );

		$other_delivery = 'route-dep-other-' . $suffix;
		$foreign        = $read( WebhookController::handle( $request( str_repeat( 'c', 32 ), 'push', $push, $dep_secret, $other_delivery ) ) );
		Assert::same( 404, $foreign['status'], 'the deployment token only opens its own deployment' );
	}

	CaseRunner::case( 'a non-push event on the App route is acknowledged but not queued' );
	$issues = $read( WebhookController::handle( $request( $app_secret, 'issues', $push, $app_secret, 'route-issues-' . $suffix ) ) );
	Assert::same( 202, $issues['status'], 'an unsupported event answers 202' );
	Assert::same( 'ignored_event', $issues['result'], 'and is ignored' );

	CaseRunner::case( 'the stored option survives a settings save' );
	$set_app_secret( str_repeat( 'e', 64 ) );
	Assert::same( str_repeat( 'e', 64 ), AppSetup::app_webhook_secret(), 'a stored 64-hex secret is read back verbatim' );
	$set_app_secret( 'not-hex' );
	Assert::same( '', AppSetup::app_webhook_secret(), 'a malformed stored secret is refused' );
	$set_app_secret( str_repeat( 'a', 32 ) );
	Assert::same( '', AppSetup::app_webhook_secret(), 'a 32-character deployment-sized value is not an App secret' );
	$set_app_secret( $app_secret );

	CaseRunner::case( 'an unknown 32-character deployment token is unknown' );
	$unknown = $read( WebhookController::handle( $request( str_repeat( 'c', 32 ), 'push', $push, $app_secret, 'route-legacy-' . $suffix ) ) );
	Assert::same( 404, $unknown['status'], 'an unregistered deployment token answers 404' );
} finally {
	$set_app_secret( $old_secret );
	if ( null === $old_option[0] || ! $old_option[1] ) {
		delete_option( Settings::OPTION );
	} else {
		update_option( Settings::OPTION, $old_option[0], false );
	}

	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::jobs() . ' WHERE deployment_id IN (%d, %d)', $matching, $other ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::deliveries() . ' WHERE deployment_id IN (%d, %d)', $matching, $other ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM " . Schema::deliveries() . " WHERE delivery_id LIKE 'route-%%'" ); // phpcs:ignore WordPress.DB
	$repo->delete( $matching );
	$repo->delete( $other );
	delete_transient( 'githubsync_wh_fail_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' ) );
}
