<?php
/**
 * Spec §3.4 / §2: credentials encrypted at rest, fail closed.
 *
 * @package GitHubSync
 */

use GitHubSync\Security\CredentialVault;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Credential vault (spec §3.4)' );

$vault = CredentialVault::from_key( str_repeat( 'k', 32 ) );

CaseRunner::case( 'round trip' );
$plain   = 'ghp_1234567890abcdefghijklmnopqrstuvwx';
$payload = $vault->encrypt( $plain );
Assert::true( is_string( $payload ) && '' !== $payload, 'ciphertext produced' );
Assert::false( false !== strpos( $payload, $plain ), 'no plaintext in ciphertext' );
Assert::true( 0 === strpos( $payload, 'v1:' ) || 0 === strpos( $payload, 'g1:' ), 'versioned format prefix' );
Assert::same( $plain, $vault->decrypt( $payload ), 'decrypts back to the original' );
Assert::true( $payload !== $vault->encrypt( $plain ), 'nonce makes each ciphertext unique' );

CaseRunner::case( 'empty and unicode values' );
// FINDING: encrypt('') produces a NONCE+MAC-only payload that decrypt()
// rejects (it requires length > nonce+MAC). An empty plaintext therefore
// cannot round-trip. Recorded against the implementation.
Assert::throws(
	static function () use ( $vault ) {
		$vault->decrypt( $vault->encrypt( '' ) );
	},
	'GitHubSync\Domain\Exceptions\GitHubSyncException',
	'AUTH_INVALID',
	'FINDING: empty plaintext cannot round-trip (encrypt( "" ) is unreadable)'
);
Assert::same( '', $vault->decrypt( '' ), 'empty stored value is treated as "no credentials"' );
$unicode = "tökén-ü-🔑";
Assert::same( $unicode, $vault->decrypt( $vault->encrypt( $unicode ) ), 'multibyte round-trips' );

CaseRunner::case( 'tampering is rejected, never silently wrong' );
$raw     = base64_decode( substr( $payload, 3 ), true );
$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF );
$tampered = substr( $payload, 0, 3 ) . base64_encode( $raw );
Assert::true( $tampered !== $payload, 'fixture really differs' );
Assert::throws(
	static function () use ( $vault, $tampered ) {
		$vault->decrypt( $tampered );
	},
	'GitHubSync\Domain\Exceptions\GitHubSyncException',
	'AUTH_INVALID',
	'tampered ciphertext throws'
);

CaseRunner::case( 'garbage and truncated payloads are rejected' );
foreach ( array( 'not-encrypted', 'v1:', 'v1:zzzz', substr( $payload, 0, 10 ), 'g1:abc' ) as $junk ) {
	Assert::throws(
		static function () use ( $vault, $junk ) {
			$vault->decrypt( $junk );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'AUTH_INVALID',
		'garbage rejected: ' . $junk
	);
}

CaseRunner::case( 'a different key cannot decrypt' );
$other = CredentialVault::from_key( str_repeat( 'z', 32 ) );
Assert::throws(
	static function () use ( $other, $payload ) {
		$other->decrypt( $payload );
	},
	'GitHubSync\Domain\Exceptions\GitHubSyncException',
	'AUTH_INVALID',
	'wrong key fails authentication'
);

CaseRunner::case( 'key length validation' );
Assert::throws(
	static function () {
		CredentialVault::from_key( 'too-short' );
	},
	'GitHubSync\Domain\Exceptions\GitHubSyncException',
	'ENCRYPTION_UNAVAILABLE',
	'short key rejected'
);
Assert::throws(
	static function () {
		CredentialVault::from_key( str_repeat( 'k', 33 ) );
	},
	'GitHubSync\Domain\Exceptions\GitHubSyncException',
	'ENCRYPTION_UNAVAILABLE',
	'over-long key rejected'
);

CaseRunner::case( 'key generation and key file permissions' );
$key = CredentialVault::generate_key();
Assert::same( 44, strlen( $key ), 'generated key is base64 (44 chars) for wp-config.php' );
Assert::same( 32, strlen( base64_decode( $key, true ) ), 'base64 key decodes to the 32 raw bytes the cipher needs' );
Assert::true( $key !== CredentialVault::generate_key(), 'keys are unique' );
Assert::true( CredentialVault::is_configured(), 'vault is configured on this site (key file present)' );

$key_file = CredentialVault::default_key_file();
Assert::true( is_file( $key_file ), 'key file exists at ' . $key_file );
Assert::same( '0600', substr( sprintf( '%o', fileperms( $key_file ) ), -4 ), 'key file is owner-read/write only' );
Assert::true( 0 !== strpos( wp_normalize_path( $key_file ), wp_normalize_path( ABSPATH ) ), 'key file lives outside the document root' );
