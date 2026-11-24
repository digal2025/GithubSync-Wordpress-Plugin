<?php
/**
 * Small shared helpers.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers used across layers.
 */
final class Helpers {

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Convert a MySQL datetime to a unix timestamp.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return int
	 */
	public static function to_timestamp( $datetime ) {
		if ( empty( $datetime ) ) {
			return 0;
		}
		$ts = strtotime( $datetime . ' UTC' );
		return false === $ts ? 0 : $ts;
	}

	/**
	 * Cryptographically secure hex token.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	public static function random_token( $bytes = 32 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Decode a JSON string to an array, returning an empty array on failure.
	 *
	 * @param mixed $raw Raw JSON.
	 * @return array
	 */
	public static function json_to_array( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Recursively redact secret-looking keys from a context array.
	 *
	 * @param array $context Context data.
	 * @return array
	 */
	public static function redact( array $context ) {
		$needles = array( 'token', 'secret', 'password', 'passwd', 'authorization', 'private_key', 'apikey', 'api_key', 'signature' );

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
				continue;
			}

			$lower = strtolower( (string) $key );
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					$context[ $key ] = '[redacted]';
					continue 2;
				}
			}

			if ( is_string( $value ) && preg_match( '/^(gh[pousr]_|github_pat_)/', $value ) ) {
				$context[ $key ] = '[redacted]';
			}
		}

		return $context;
	}

	/**
	 * A working directory that is not publicly readable where possible.
	 *
	 * Preference order:
	 *  1. `GITHUBSYNC_WORK_DIR` constant.
	 *  2. A sibling of the WordPress root (outside the document root).
	 *  3. uploads/githubsync-tmp, hardened with deny rules.
	 *
	 * @return string Absolute path with a trailing slash.
	 */
	public static function work_dir() {
		if ( defined( 'GITHUBSYNC_WORK_DIR' ) && is_string( GITHUBSYNC_WORK_DIR ) && '' !== GITHUBSYNC_WORK_DIR ) {
			$dir = rtrim( GITHUBSYNC_WORK_DIR, '/\\' ) . '/';
		} else {
			$outside = dirname( untrailingslashit( ABSPATH ) ) . '/githubsync-tmp';
			if ( is_dir( $outside ) || ( is_writable( dirname( $outside ) ) && @mkdir( $outside, 0700, true ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$dir = untrailingslashit( $outside ) . '/';
			} else {
				$uploads = wp_upload_dir( null, false );
				$dir     = untrailingslashit( $uploads['basedir'] ) . '/githubsync-tmp/';
			}
		}

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect_dir( $dir );
		}

		return $dir;
	}

	/**
	 * Drop deny rules into a directory that may be web-accessible.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	public static function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}

	/**
	 * Human readable byte size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function human_bytes( $bytes ) {
		$bytes = (int) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return sprintf( '%.1f %s', $bytes, $units[ $i ] );
	}

	/**
	 * Queue a one-off admin notice, stored per user.
	 *
	 * @param string $type    error|warning|success|info.
	 * @param string $message Message (already translated, unescaped).
	 * @return void
	 */
	public static function add_notice( $type, $message ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key     = 'githubsync_notices_' . $user_id;
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, array_slice( $notices, -10 ), HOUR_IN_SECONDS );
	}

	/**
	 * Drain queued notices for the current user.
	 *
	 * @return array
	 */
	public static function take_notices() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$key     = 'githubsync_notices_' . $user_id;
		$notices = get_transient( $key );
		delete_transient( $key );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Whether multisite is active (unsupported in the MVP).
	 *
	 * @return bool
	 */
	public static function is_multisite() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Shorten a commit SHA for display.
	 *
	 * @param string $sha Full SHA.
	 * @return string
	 */
	public static function short_sha( $sha ) {
		$sha = (string) $sha;
		return strlen( $sha ) > 7 ? substr( $sha, 0, 7 ) : $sha;
	}

	/**
	 * Plugin setting with a default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function setting( $key, $default = null ) {
		$settings = get_option( 'githubsync_settings', array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}
}
