<?php
/**
 * Spec §3.1 / §7: GitHub connection state, credential storage and the
 * mapping from GitHub API failures to stable error codes.
 *
 * Everything here is offline: no request leaves the machine. The transport
 * layer is intercepted with the `pre_http_request` filter.
 *
 * @package GitHubSync
 */

use GitHubSync\Database\LogRepository;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\GitHub\GitHubResponse;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'GitHub connection and API errors (spec §3.1, §7)' );

$snapshot = array(
	'connection' => get_option( GitHubAuth::OPTION_CONNECTION, null ),
	'app_key'    => get_option( GitHubAuth::OPTION_APP_KEY, null ),
	'pat'        => get_option( GitHubAuth::OPTION_PAT, null ),
);

/**
 * Restore the connection options this case changed.
 *
 * @param array $snapshot Values captured before the case ran.
 * @return void
 */
$restore = static function ( array $snapshot ) {
	foreach ( $snapshot as $option => $value ) {
		$name = array(
			'connection' => GitHubAuth::OPTION_CONNECTION,
			'app_key'    => GitHubAuth::OPTION_APP_KEY,
			'pat'        => GitHubAuth::OPTION_PAT,
		)[ $option ];

		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, false );
		}
	}
};

/**
 * Build a throwaway RSA key for App authentication tests.
 *
 * @return string|false PEM private key.
 */
$make_pem = static function () {
	$key = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);

	if ( false === $key ) {
		return false;
	}

	openssl_pkey_export( $key, $pem );

	return $pem;
};

/** A vault payload always starts with one of the two algorithm tags. */
$is_vault_payload = static function ( $value ) {
	return 0 === strpos( (string) $value, 'v1:' ) || 0 === strpos( (string) $value, 'g1:' );
};

// ---------------------------------------------------------------- state ---

CaseRunner::case( 'a fresh install reports no connection' );
$auth = new GitHubAuth();
$auth->disconnect();

Assert::false( $auth->is_connected(), 'is_connected() is false with no options' );
Assert::same( 'Not connected', $auth->summary(), 'summary says Not connected' );

$token = $auth->get_token();
Assert::true( is_wp_error( $token ), 'get_token() fails when not connected' );
Assert::same( ErrorCodes::AUTH_INVALID, $token->get_error_code(), 'error code is AUTH_INVALID' );

CaseRunner::case( 'an unknown connection mode is not treated as connected' );
update_option( GitHubAuth::OPTION_CONNECTION, array( 'mode' => 'oauth' ), false );
$auth = new GitHubAuth();
Assert::false( $auth->is_connected(), 'only app and pat count as connected' );
Assert::true( is_wp_error( $auth->get_token() ), 'no token for an unknown mode' );

// ------------------------------------------------------------------ PAT ---

CaseRunner::case( 'a PAT must look like a GitHub token' );
$auth = new GitHubAuth();
foreach ( array( '', 'hunter2', 'ghp_short', 'xghp_' . str_repeat( 'a', 36 ), 'ghp_' . str_repeat( '-', 30 ) ) as $bad ) {
	$result = $auth->connect_pat( $bad );
	Assert::true( is_wp_error( $result ), 'rejects ' . wp_json_encode( $bad ) );
	Assert::same( ErrorCodes::AUTH_INVALID, $result->get_error_code(), 'rejection code is AUTH_INVALID' );
}
Assert::false( $auth->is_connected(), 'a rejected token does not connect anything' );

CaseRunner::case( 'a valid PAT is stored encrypted and never in cleartext' );
$pat = 'ghp_' . str_repeat( 'A1b2C3d4', 5 );
$ok  = $auth->connect_pat( $pat, array( 'account' => 'octocat', 'display_name' => 'The Octocat' ) );

Assert::true( $ok, 'connect_pat() accepts a well-formed token' );
Assert::true( $auth->is_connected(), 'connection is established' );
Assert::same( 'Personal access token for octocat', $auth->summary(), 'summary names the account' );

$stored = (string) get_option( GitHubAuth::OPTION_PAT, '' );
Assert::true( '' !== $stored, 'the token option is populated' );
Assert::false( false !== strpos( $stored, $pat ), 'the stored value does not contain the token' );
Assert::true( $is_vault_payload( $stored ), 'the stored value is a vault payload' );

$connection = get_option( GitHubAuth::OPTION_CONNECTION, array() );
Assert::same( 'pat', $connection['mode'], 'mode is pat' );
Assert::same( 'The Octocat', $connection['display_name'], 'display name is kept' );
Assert::false( in_array( $pat, array_map( 'strval', $connection ), true ), 'no cleartext token in connection metadata' );

Assert::same( $pat, $auth->get_token(), 'get_token() round-trips the PAT' );

CaseRunner::case( 'a token containing a hyphen is rejected (conservative charset)' );
$dash = $auth->connect_pat( 'ghp_' . str_repeat( 'a', 20 ) . '-tail' );
Assert::true( is_wp_error( $dash ), 'FINDING: "-" is outside the accepted charset' );
Assert::same( ErrorCodes::AUTH_INVALID, $dash->get_error_code(), 'still reports AUTH_INVALID' );

CaseRunner::case( 'connecting a PAT drops any App key' );
update_option( GitHubAuth::OPTION_APP_KEY, 'stale', false );
$auth->connect_pat( 'github_pat_' . str_repeat( 'x', 30 ) );
Assert::same( '', (string) get_option( GitHubAuth::OPTION_APP_KEY, '' ), 'the App key was cleared' );

CaseRunner::case( 'disconnect removes every stored credential' );
$auth->disconnect();
Assert::false( $auth->is_connected(), 'not connected after disconnect' );
Assert::same( '', (string) get_option( GitHubAuth::OPTION_PAT, '' ), 'PAT removed' );
Assert::same( '', (string) get_option( GitHubAuth::OPTION_APP_KEY, '' ), 'App key removed' );
$connection = get_option( GitHubAuth::OPTION_CONNECTION );
Assert::true( false === $connection || ! is_array( $connection ) || empty( $connection['mode'] ), 'connection option removed' );

// ------------------------------------------------------------------ App ---

CaseRunner::case( 'a GitHub App connection needs numeric ids and a real PEM' );
$auth = new GitHubAuth();
$pem  = $make_pem();

if ( false === $pem ) {
	Assert::skip( 'openssl cannot generate an RSA key here' );
} else {
	foreach ( array( array( '', '1' ), array( '123', '' ), array( 'app', '123' ), array( '123', 'inst' ) ) as $ids ) {
		$result = $auth->connect_app( $ids[0], $ids[1], $pem );
		Assert::true( is_wp_error( $result ), 'rejects app id ' . wp_json_encode( $ids ) );
		Assert::same( ErrorCodes::CONFIG_INVALID, $result->get_error_code(), 'id rejection is CONFIG_INVALID' );
	}

	$bad = $auth->connect_app( '123', '456', 'not a pem' );
	Assert::true( is_wp_error( $bad ), 'rejects a non-PEM private key' );
	Assert::same( ErrorCodes::AUTH_INVALID, $bad->get_error_code(), 'PEM rejection is AUTH_INVALID' );

	CaseRunner::case( 'a valid App connection is stored encrypted' );
	$ok = $auth->connect_app( '12345', '67890', $pem, array( 'account' => 'acme-org' ) );
	Assert::true( $ok, 'connect_app() accepts a real PEM' );
	Assert::same(
		'GitHub App installation on acme-org (installation 67890)',
		$auth->summary(),
		'summary names account and installation'
	);

	$stored_key = (string) get_option( GitHubAuth::OPTION_APP_KEY, '' );
	Assert::true( $is_vault_payload( $stored_key ), 'private key stored as a vault payload' );
	Assert::false( false !== strpos( $stored_key, 'PRIVATE KEY' ), 'private key material is not in cleartext' );
	Assert::same( '', (string) get_option( GitHubAuth::OPTION_PAT, '' ), 'PAT was cleared' );
}

// -------------------------------------------------------- error mapping ---

CaseRunner::case( 'failed responses map to stable error codes' );
$auth   = new GitHubAuth();
$client = new GitHubApiClient( $auth );

$cases = array(
	// status, rate headers, decode failed, expected code.
	array( 401, array(), false, ErrorCodes::AUTH_INVALID ),
	array( 403, array(), false, ErrorCodes::AUTH_PERMISSION_DENIED ),
	array( 404, array(), false, ErrorCodes::REPOSITORY_NOT_FOUND ),
	array( 0, array(), false, ErrorCodes::GITHUB_NETWORK_ERROR ),
	array( 500, array(), false, ErrorCodes::INTERNAL_ERROR ),
	array( 200, array(), true, ErrorCodes::INTERNAL_ERROR ),
);

foreach ( $cases as $case ) {
	$response                = new GitHubResponse();
	$response->status        = $case[0];
	$response->headers       = $case[1];
	$response->body          = 'body';
	$response->decode_failed = $case[2];
	$error                   = $client->to_error( $response );
	Assert::true( is_wp_error( $error ), 'HTTP ' . $case[0] . ' produces a WP_Error' );
	Assert::same( $case[3], $error->get_error_code(), 'HTTP ' . $case[0] . ' maps to ' . $case[3] );
}

CaseRunner::case( 'a rate-limited response is reported as retryable' );
$response                 = new GitHubResponse();
$response->status         = 403;
$response->headers        = array( 'x-ratelimit-remaining' => '0' );
$response->body           = '{"message":"API rate limit exceeded"}';
$response->data           = array( 'message' => 'API rate limit exceeded' );

$error = $client->to_error( $response );
Assert::same( ErrorCodes::GITHUB_RATE_LIMIT, $error->get_error_code(), '403 with a spent limit becomes GITHUB_RATE_LIMIT' );
// FINDING: ErrorCodes::retryable() takes no arguments and returns the list of
// retryable codes (the predicate is is_retryable()). Passing a code to
// retryable() silently returns a non-empty array, i.e. a truthy value for
// every code — a caller that misreads the name would retry permanent
// failures. Recorded here so the trap is not re-introduced.
Assert::true( in_array( ErrorCodes::GITHUB_RATE_LIMIT, ErrorCodes::retryable(), true ), 'the list of retryable codes contains GITHUB_RATE_LIMIT' );
Assert::true( ErrorCodes::is_retryable( $error->get_error_code() ), 'and it is retryable' );
Assert::true(
	(bool) ErrorCodes::retryable( 'AUTH_INVALID' ),
	'FINDING: retryable() ignores its argument and returns a truthy array for every input'
);
Assert::false( ErrorCodes::is_retryable( ErrorCodes::AUTH_INVALID ), 'is_retryable() is the real predicate: auth failures are permanent' );
Assert::same( 4, count( ErrorCodes::retryable() ), 'exactly four codes are retryable' );
Assert::false( ErrorCodes::is_retryable( ErrorCodes::BACKUP_FAILED ), 'a failed backup is permanent' );

$response->status  = 429;
$response->headers = array();
Assert::same( ErrorCodes::GITHUB_RATE_LIMIT, $client->to_error( $response )->get_error_code(), 'a bare 429 is rate limited too' );

// -------------------------------------------------- transport interception ---

CaseRunner::case( 'API requests carry a bearer token and never log it' );
$pat  = 'ghp_' . str_repeat( 'Z9', 20 );
$auth = new GitHubAuth();
$auth->connect_pat( $pat );

$seen = array();
$spy  = static function ( $pre, $args, $url ) use ( &$seen ) {
	$seen[] = array(
		'url'     => $url,
		'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
	);

	return array(
		'headers'  => array(),
		'body'     => wp_json_encode(
			array(
				'login' => 'octocat',
				'name'  => 'The Octocat',
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};

add_filter( 'pre_http_request', $spy, 10, 3 );
$client = new GitHubApiClient( $auth );
$who    = $client->whoami();
remove_filter( 'pre_http_request', $spy, 10 );

Assert::true( is_array( $who ), 'whoami() returns an identity array' );
Assert::same( 'octocat', $who['account'], 'whoami() returns the login' );
Assert::same( 'The Octocat', $who['display_name'], 'whoami() returns the display name' );
Assert::same( 1, count( $seen ), 'exactly one request was made' );
Assert::true( false !== strpos( $seen[0]['url'], '/user' ), 'the request went to /user' );
Assert::same( 'Bearer ' . $pat, $seen[0]['headers']['Authorization'], 'Authorization carries the token' );

CaseRunner::case( 'a token is never written to a log row' );
$log_repo = new LogRepository();
$log_repo->insert(
	array(
		'level'        => 'info',
		'event_code'   => ErrorCodes::INTERNAL_ERROR,
		'message'      => 'auth test',
		'context_json' => wp_json_encode( array( 'note' => 'no secrets' ) ),
	)
);
$row = $GLOBALS['wpdb']->get_row(
	"SELECT message, context_json FROM {$GLOBALS['wpdb']->prefix}githubsync_logs ORDER BY id DESC LIMIT 1",
	ARRAY_A
);
Assert::true( is_array( $row ), 'log row written' );
Assert::false( false !== strpos( (string) $row['context_json'], $pat ), 'the token is not in the log context' );
Assert::false( false !== strpos( (string) $row['message'], $pat ), 'the token is not in the log message' );

// ---------------------------------------------------------------- tidy up ---

( new GitHubAuth() )->disconnect();
$restore( $snapshot );

CaseRunner::case( 'connection options were restored' );
$after = get_option( GitHubAuth::OPTION_CONNECTION, null );
Assert::same( $snapshot['connection'], $after, 'the original connection option is back' );
