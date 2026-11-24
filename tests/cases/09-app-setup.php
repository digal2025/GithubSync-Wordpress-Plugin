<?php
/**
 * GitHub App one-click setup (manifest flow): begin, exchange, install, finish.
 *
 * Everything here is offline. The GitHub transport layer is intercepted with
 * the `pre_http_request` filter, so no request leaves the machine. Nothing in
 * this file proves the live GitHub behaviour of the flow; it proves the
 * plugin's own state machine.
 *
 * @package GitHubSync
 */

use GitHubSync\GitHub\AppSetup;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'GitHub App one-click setup (manifest flow)' );

$setup_snapshot = array(
	GitHubAuth::OPTION_CONNECTION,
	GitHubAuth::OPTION_APP_KEY,
	GitHubAuth::OPTION_PAT,
	GitHubAuth::OPTION_SETUP,
);

/**
 * Capture the options this file changes.
 *
 * @param array $keys Option names.
 * @return array
 */
$capture = static function ( array $keys ) {
	$values = array();

	foreach ( $keys as $key ) {
		$values[ $key ] = array( get_option( $key, null ), null !== get_option( $key, null ) );
	}

	return $values;
};

/**
 * Put the options back exactly as they were.
 *
 * @param array $values Result of $capture().
 * @return void
 */
$put_back = static function ( array $values ) {
	foreach ( $values as $key => $entry ) {
		if ( $entry[1] ) {
			update_option( $key, $entry[0], false );
		} else {
			delete_option( $key );
		}
	}
};

$original = $capture( $setup_snapshot );

/**
 * Lift the HTTPS requirement for this suite.
 *
 * The Local site is plain HTTP, so `is_supported()` is false and `begin()`
 * would refuse. The filter is the same seam a TLS-terminating proxy needs
 * (see AppSetup::is_supported()). The honesty check below runs without it.
 *
 * @return bool
 */
$allow_setup = static function () {
	return true;
};

$key = openssl_pkey_new(
	array(
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
		'private_key_bits' => 2048,
	)
);
openssl_pkey_export( $key, $pem );

/**
 * Fake GitHub for the manifest flow.
 *
 * @param array $routes Map of URL substring => array( status, body ) or callable.
 * @param array $seen   Collected requests (by reference).
 * @return callable
 */
$fake_github = static function ( array $routes, array &$seen ) {
	return static function ( $pre, $args, $url ) use ( $routes, &$seen ) {
		$seen[] = array(
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'url'    => $url,
			'body'   => isset( $args['body'] ) ? $args['body'] : '',
		);

		foreach ( $routes as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $args );
				}

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 200 === $reply[0] ? 'OK' : 'Error',
					),
					'cookies'  => array(),
				);
			}
		}

		return new \WP_Error( 'unexpected_request', 'Unexpected request to ' . $url );
	};
};

$conversion = array(
	'id'             => 515151,
	'slug'           => 'githubsync-test',
	'html_url'       => 'https://github.com/apps/githubsync-test',
	'client_id'      => 'Iv1.abc',
	'client_secret'  => 'client-secret',
	'pem'            => $pem,
	'webhook_secret' => 'hook-secret',
	'hook'           => array( 'config' => array( 'secret' => 'hook-secret' ) ),
	'owner'          => array( 'login' => 'octocat' ),
);

$installation = array(
	'id'           => 777777,
	'account'      => array(
		'id'    => 99,
		'login' => 'octocat',
	),
	'target_type'  => 'User',
);

// GitHub answers a token request with a token and an expiry.
$installation_token = array(
	'token'      => 'ghs_installation_token',
	'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
);

/**
 * Prepare a clean connection with no App stored.
 *
 * @return GitHubAuth
 */
$blank_auth = static function () {
	$auth = new GitHubAuth();
	$auth->disconnect();
	delete_option( GitHubAuth::OPTION_SETUP );
	return $auth;
};

CaseRunner::case( 'begin() refuses a site GitHub cannot redirect to' );
$real = new AppSetup();

if ( $real->is_supported() ) {
	CaseRunner::skip( 'this site is already HTTPS-safe, so the refusal cannot be exercised' );
} else {
	$blocked = $real->begin();
	Assert::true( is_wp_error( $blocked ), 'this plain-HTTP site is refused' );
	Assert::same( ErrorCodes::CONFIG_INVALID, $blocked->get_error_code(), 'the error code is CONFIG_INVALID' );
	Assert::true( str_contains( $blocked->get_error_message(), 'HTTPS' ), 'the message explains the HTTPS requirement' );
}

add_filter( 'githubsync_app_setup_supported', $allow_setup );

CaseRunner::case( 'begin() builds a self-posting manifest form' );
$blank_auth();
$setup = new AppSetup();
$form  = $setup->begin();

Assert::true( is_array( $form ), 'begin() returns a form' );
Assert::true( AppSetup::is_state( $form['state'] ), 'the state is 48 hex characters' );
Assert::true( '' !== $form['action'] && false !== strpos( $form['action'], 'github.com' ), 'the form posts to GitHub' );

$manifest = json_decode( $form['manifest'], true );
Assert::true( is_array( $manifest ), 'the manifest is JSON' );
Assert::true( isset( $manifest['redirect_url'] ) && false !== strpos( $manifest['redirect_url'], '_gs_return=1' ), 'the redirect URL carries the callback marker' );
Assert::true( isset( $manifest['setup_url'] ) && false !== strpos( $manifest['setup_url'], '_gs_install=1' ), 'the setup URL carries the install marker' );
Assert::true( isset( $manifest['hook_attributes']['active'] ) && false === $manifest['hook_attributes']['active'], 'the App webhook starts inactive (deployments need repositories selected)' );
Assert::false( isset( $manifest['hook_attributes']['url'] ) && '' === $manifest['hook_attributes']['url'], 'no empty hook URL is sent to GitHub' );

$again = $setup->form( $form['state'] );
Assert::true( is_array( $again ), 'form() re-renders the pending form after a redirect' );
Assert::same( $form['manifest'], $again['manifest'], 'the re-rendered manifest is identical' );

// -------------------------------------------------------------- exchange ---

CaseRunner::case( 'exchange() turns a code into a stored App' );
$blank_auth();
$setup  = new AppSetup();
$form   = $setup->begin();
$seen   = array();
$filter = $fake_github(
	array(
		'/app-manifests/test-code/conversions' => array( 201, $conversion ),
	),
	$seen
);

add_filter( 'pre_http_request', $filter, 10, 3 );
$stored = $setup->exchange( $form['state'], 'test-code' );
remove_filter( 'pre_http_request', $filter, 10 );

Assert::true( is_array( $stored ), 'exchange() succeeds' );
Assert::same( '515151', $stored['app_id'], 'the App ID is stored' );
Assert::same( 'githubsync-test', $stored['slug'], 'the slug is stored' );
Assert::same( 'https://github.com/apps/githubsync-test/installations/new', $stored['install_url'], 'the install URL is built from the slug' );
Assert::same( 1, count( $seen ), 'one request was made' );
Assert::same( 'POST', $seen[0]['method'], 'the conversion is a POST' );

$auth = new GitHubAuth();
Assert::true( $auth->has_app(), 'the App is recognised' );
Assert::false( $auth->is_connected(), 'but it is not a connection before installation' );
Assert::same( '515151', $auth->app_meta()['app_id'], 'app_meta() keeps the App ID' );
Assert::same( 'githubsync-test', $auth->app_meta()['slug'], 'app_meta() keeps the slug' );

CaseRunner::case( 'the installation model is reached in finish()' );
$seen   = array();
$filter = $fake_github(
	array(
		'/app/installations/777777/access_tokens' => array( 201, $installation_token ),
		'/app/installations/777777'               => array( 200, $installation ),
		'/installation/repositories'              => array( 200, array( 'total_count' => 0, 'repositories' => array() ) ),
	),
	$seen
);

add_filter( 'pre_http_request', $filter, 10, 3 );
$outcome = $setup->finish(
	array(
		'installation_id' => '777777',
		'setup_action'    => 'install',
		'state'           => $form['state'],
	)
);
remove_filter( 'pre_http_request', $filter, 10 );

Assert::true( is_array( $outcome ), 'finish() returns a result for the page' );
Assert::same( 'success', $outcome['type'], 'the outcome is a success' );
Assert::same( 'app_installed', $outcome['code'], 'the outcome code is app_installed' );
Assert::true( str_contains( $outcome['message'], 'octocat' ), 'the message names the account' );
Assert::same( '', $outcome['install_url'], 'no install link is offered once installed' );
Assert::true( $auth->is_connected(), 'the App is connected after installation' );
Assert::same( '777777', $auth->connection()['installation_id'], 'the installation ID is stored' );

CaseRunner::case( 'finish() stores the key marker while the App is uninstalled' );
$blank_auth();
$setup  = new AppSetup();
$form   = $setup->begin();
$seen   = array();
$filter = $fake_github( array( '/app-manifests/test-code/conversions' => array( 201, $conversion ) ), $seen );

add_filter( 'pre_http_request', $filter, 10, 3 );
$outcome = $setup->finish( array( 'code' => 'test-code', 'state' => $form['state'] ) );
remove_filter( 'pre_http_request', $filter, 10 );

Assert::same( 'app_created', $outcome['code'], 'the outcome code is app_created' );
Assert::true( $setup->key_download_available( '515151' ), 'the one-time key download is offered' );
Assert::false( $setup->key_download_available( '111' ), 'an unknown App ID has no download' );
Assert::same( $pem, $setup->private_key(), 'the stored key can be read back for the download' );
Assert::true( '' !== $outcome['install_url'], 'an install link is offered' );

CaseRunner::case( 'a used state cannot be exchanged twice' );
$seen   = array();
$filter = $fake_github( array( '/app-manifests/second-code/conversions' => array( 201, $conversion ) ), $seen );

add_filter( 'pre_http_request', $filter, 10, 3 );
$replay = $setup->exchange( $form['state'], 'second-code' );
remove_filter( 'pre_http_request', $filter, 10 );

Assert::true( is_wp_error( $replay ), 'the second exchange fails' );
Assert::same( ErrorCodes::CONFIG_INVALID, $replay->get_error_code(), 'the code is CONFIG_INVALID' );
Assert::same( 0, count( $seen ), 'no request was made for the replayed state' );

CaseRunner::case( 'finish() ignores an unrelated request' );
Assert::true( null === $setup->finish( array() ), 'an empty request is not a callback' );
Assert::true( null === $setup->finish( array( 'page' => 'githubsync-connection' ) ), 'an unrelated query is not a callback' );

CaseRunner::case( 'finish() reports a cancelled setup without changing the connection' );
$before = get_option( GitHubAuth::OPTION_CONNECTION, null );
$cancel = $setup->finish( array( 'error' => 'access_denied', '_gs_return' => '1' ) );

Assert::same( 'warning', $cancel['type'], 'a cancel is a warning' );
Assert::same( 'app_setup_cancelled', $cancel['code'], 'the cancel code is app_setup_cancelled' );
Assert::same( $before, get_option( GitHubAuth::OPTION_CONNECTION, null ), 'the connection is untouched' );

CaseRunner::case( 'finish() reports an expired state as an error' );
$expired = $setup->finish( array( 'code' => 'x', 'state' => str_repeat( 'a', 48 ), '_gs_return' => '1' ) );

Assert::same( 'error', $expired['type'], 'an expired state is an error' );
Assert::true( str_contains( $expired['message'], 'expired' ), 'the message explains the state expired' );

CaseRunner::case( 'exchange() reports a rejected code' );
$blank_auth();
$seen   = array();
$filter = $fake_github( array( '/app-manifests/bad-code/conversions' => array( 404, array( 'message' => 'Not Found' ) ) ), $seen );
$fresh  = $setup->begin();

add_filter( 'pre_http_request', $filter, 10, 3 );
$rejected = $setup->exchange( $fresh['state'], 'bad-code' );
remove_filter( 'pre_http_request', $filter, 10 );

Assert::true( is_wp_error( $rejected ), 'a 404 conversion is an error' );
Assert::same( ErrorCodes::CONFIG_INVALID, $rejected->get_error_code(), 'the code is CONFIG_INVALID' );
Assert::false( ( new GitHubAuth() )->has_app(), 'no App is stored for a rejected code' );

CaseRunner::case( 'install() requires a stored App' );
$blank_auth();
$denied = $setup->install( '', array( 'installation_id' => '5' ) );

Assert::true( is_wp_error( $denied ), 'an install without an App fails' );
Assert::same( ErrorCodes::CONFIG_INVALID, $denied->get_error_code(), 'the code is CONFIG_INVALID' );
Assert::true( str_contains( $denied->get_error_message(), 'Create the App first' ), 'the message points at App creation' );

CaseRunner::case( 'install() rolls back when the installation cannot be verified' );
$blank_auth();
$setup  = new AppSetup();
$form   = $setup->begin();
$seen   = array();
$filter = $fake_github( array( '/app-manifests/test-code/conversions' => array( 201, $conversion ) ), $seen );

add_filter( 'pre_http_request', $filter, 10, 3 );
$setup->exchange( $form['state'], 'test-code' );
remove_filter( 'pre_http_request', $filter, 10 );

$seen   = array();
$filter = $fake_github(
	array(
		'/app/installations/777777' => array( 200, $installation ),
		'/installation/repositories' => array( 403, array( 'message' => 'Forbidden' ) ),
	),
	$seen
);

add_filter( 'pre_http_request', $filter, 10, 3 );
$broken = $setup->finish( array( 'installation_id' => '777777', '_gs_install' => '1' ) );
remove_filter( 'pre_http_request', $filter, 10 );

Assert::same( 'error', $broken['type'], 'a failed verification is an error' );
Assert::same( 2, count( $seen ), 'the installation was looked up and the token checked' );
$after = new GitHubAuth();
Assert::true( $after->has_app(), 'the App itself is kept' );
Assert::false( $after->is_connected(), 'but the broken installation is rolled back' );

// ---------------------------------------------------------------- tidy up ---

$put_back( $original );

CaseRunner::case( 'the connection options were restored after the setup cases' );
Assert::same( $original[ GitHubAuth::OPTION_CONNECTION ][0], get_option( GitHubAuth::OPTION_CONNECTION, null ), 'the connection option is back' );
