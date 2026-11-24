<?php
/**
 * Authenticated encryption for stored credentials.
 *
 * Fails closed: if no site-specific key is available, nothing is stored in
 * plaintext and the admin is told exactly how to provision a key.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Security;

use GitHubSync\Domain\Exceptions\GitHubSyncException;
use GitHubSync\Support\ErrorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets at rest with libsodium secretbox (preferred) or
 * AES-256-GCM via OpenSSL.
 */
final class CredentialVault {

	private const PREFIX_SODIUM = 'v1:';
	private const PREFIX_GCM    = 'g1:';
	private const KEY_BYTES     = 32;

	/**
	 * Raw 32-byte key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key Raw 32-byte key.
	 */
	private function __construct( $key ) {
		$this->key = $key;
	}

	/**
	 * Whether an encryption key can be resolved.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$key = self::resolve_key();
		return is_string( $key ) && self::KEY_BYTES === strlen( $key );
	}

	/**
	 * Build a vault from the site key.
	 *
	 * @return self
	 * @throws GitHubSyncException When no usable key exists.
	 */
	public static function from_site() {
		$key = self::resolve_key();

		if ( ! is_string( $key ) || self::KEY_BYTES !== strlen( $key ) ) {
			throw new GitHubSyncException( ErrorCodes::ENCRYPTION_UNAVAILABLE );
		}

		return new self( $key );
	}

	/**
	 * Build a vault from an explicit key (tests, key rotation).
	 *
	 * @param string $key Raw 32-byte key.
	 * @return self
	 * @throws GitHubSyncException When the key length is wrong.
	 */
	public static function from_key( $key ) {
		if ( ! is_string( $key ) || self::KEY_BYTES !== strlen( $key ) ) {
			throw new GitHubSyncException( ErrorCodes::ENCRYPTION_UNAVAILABLE, 'Key must be exactly 32 bytes.' );
		}
		return new self( $key );
	}

	/**
	 * Generate a new base64 key for wp-config.php.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return base64_encode( random_bytes( self::KEY_BYTES ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Default key file location: outside the document root.
	 *
	 * @return string
	 */
	public static function default_key_file() {
		if ( defined( 'GITHUBSYNC_KEY_FILE' ) && is_string( GITHUBSYNC_KEY_FILE ) && '' !== GITHUBSYNC_KEY_FILE ) {
			return GITHUBSYNC_KEY_FILE;
		}
		return dirname( untrailingslashit( ABSPATH ) ) . '/githubsync-secret.key';
	}

	/**
	 * Write a new key file with restrictive permissions.
	 *
	 * @return true|\WP_Error
	 */
	public static function create_key_file() {
		$file = self::default_key_file();
		$dir  = dirname( $file );

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new \WP_Error(
				ErrorCodes::ENCRYPTION_UNAVAILABLE,
				sprintf(
					/* translators: %s: directory path. */
					__( 'Cannot write a key file to %s. Add the GITHUBSYNC_ENCRYPTION_KEY constant to wp-config.php instead.', 'githubsync' ),
					$dir
				)
			);
		}

		if ( file_exists( $file ) ) {
			return new \WP_Error( ErrorCodes::ENCRYPTION_UNAVAILABLE, __( 'A key file already exists. Refusing to overwrite it.', 'githubsync' ) );
		}

		$key = random_bytes( self::KEY_BYTES );
		$ok  = @file_put_contents( $file, base64_encode( $key ) . "\n", LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( false === $ok ) {
			return new \WP_Error( ErrorCodes::ENCRYPTION_UNAVAILABLE, __( 'Could not write the key file.', 'githubsync' ) );
		}

		@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return true;
	}

	/**
	 * Resolve the site key from constant or key file.
	 *
	 * @return string|false Raw key.
	 */
	private static function resolve_key() {
		$encoded = '';

		if ( defined( 'GITHUBSYNC_ENCRYPTION_KEY' ) && is_string( GITHUBSYNC_ENCRYPTION_KEY ) && '' !== GITHUBSYNC_ENCRYPTION_KEY ) {
			$encoded = GITHUBSYNC_ENCRYPTION_KEY;
		} else {
			$file = self::default_key_file();
			if ( is_readable( $file ) ) {
				$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( is_string( $contents ) ) {
					$encoded = trim( $contents );
				}
			}
		}

		if ( '' === $encoded ) {
			return false;
		}

		$key = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $key || self::KEY_BYTES !== strlen( $key ) ) {
			return false;
		}

		return $key;
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 * @throws GitHubSyncException When encryption is unavailable or fails.
	 */
	public function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

			return self::PREFIX_SODIUM . base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$nonce  = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );

			if ( false === $cipher ) {
				throw new GitHubSyncException( ErrorCodes::ENCRYPTION_UNAVAILABLE, 'Encryption failed.' );
			}

			return self::PREFIX_GCM . base64_encode( $nonce . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		throw new GitHubSyncException( ErrorCodes::ENCRYPTION_UNAVAILABLE, 'No supported encryption extension.' );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $payload Stored ciphertext.
	 * @return string
	 * @throws GitHubSyncException On corruption or missing key.
	 */
	public function decrypt( $payload ) {
		$payload = (string) $payload;

		if ( '' === $payload ) {
			return '';
		}

		if ( 0 === strpos( $payload, self::PREFIX_SODIUM ) ) {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

			if ( false === $raw || strlen( $raw ) <= $min || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				throw new GitHubSyncException( ErrorCodes::AUTH_INVALID, 'Stored credential is corrupted.' );
			}

			$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

			if ( false === $plain ) {
				throw new GitHubSyncException( ErrorCodes::AUTH_INVALID, 'Stored credential could not be decrypted.' );
			}

			return $plain;
		}

		if ( 0 === strpos( $payload, self::PREFIX_GCM ) ) {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX_GCM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false === $raw || strlen( $raw ) <= 28 || ! function_exists( 'openssl_decrypt' ) ) {
				throw new GitHubSyncException( ErrorCodes::AUTH_INVALID, 'Stored credential is corrupted.' );
			}

			$nonce  = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag );

			if ( false === $plain ) {
				throw new GitHubSyncException( ErrorCodes::AUTH_INVALID, 'Stored credential could not be decrypted.' );
			}

			return $plain;
		}

		throw new GitHubSyncException( ErrorCodes::AUTH_INVALID, 'Unknown credential format.' );
	}
}
