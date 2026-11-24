<?php
/**
 * GitHub webhook signature verification.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC SHA-256 verification over the exact raw request body.
 */
final class SignatureVerifier {

	/**
	 * Verify an X-Hub-Signature-256 header value.
	 *
	 * @param string $payload   Exact raw request body.
	 * @param string $signature Header value, e.g. "sha256=abc...".
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	public static function verify( $payload, $signature, $secret ) {
		$signature = (string) $signature;
		$secret    = (string) $secret;

		if ( '' === $signature || '' === $secret ) {
			return false;
		}

		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return false;
		}

		$provided = substr( $signature, 7 );

		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $provided ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', (string) $payload, $secret );

		return hash_equals( $expected, strtolower( $provided ) );
	}

	/**
	 * Compute a signature for the given payload (used by tests and the
	 * diagnostic "test webhook" tool).
	 *
	 * @param string $payload Raw body.
	 * @param string $secret  Secret.
	 * @return string
	 */
	public static function sign( $payload, $secret ) {
		return 'sha256=' . hash_hmac( 'sha256', (string) $payload, (string) $secret );
	}

	/**
	 * Generate a new webhook secret.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}
}
