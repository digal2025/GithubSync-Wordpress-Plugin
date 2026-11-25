<?php
/**
 * Plugin settings with validated defaults.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for configurable limits.
 */
final class Settings {

	public const OPTION = 'githubsync_settings';

	/**
	 * Defaults from specification section 10.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'max_archive_mb'       => 100,
			'max_uncompressed_mb'  => 300,
			'max_files'            => 10000,
			'max_file_mb'          => 50,
			'backup_retention'     => 3,
			'log_retention_days'   => 30,
			'max_webhook_bytes'    => 1048576,
			'max_attempts'         => 3,
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback when unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitise a submitted settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$out = self::defaults();

		$out['max_archive_mb']      = self::clamp( isset( $input['max_archive_mb'] ) ? $input['max_archive_mb'] : 100, 1, 2048 );
		$out['max_uncompressed_mb'] = self::clamp( isset( $input['max_uncompressed_mb'] ) ? $input['max_uncompressed_mb'] : 300, 1, 8192 );
		$out['max_files']           = self::clamp( isset( $input['max_files'] ) ? $input['max_files'] : 10000, 1, 100000 );
		$out['max_file_mb']         = self::clamp( isset( $input['max_file_mb'] ) ? $input['max_file_mb'] : 50, 1, 1024 );
		$out['backup_retention']    = self::clamp( isset( $input['backup_retention'] ) ? $input['backup_retention'] : 3, 1, 20 );
		$out['log_retention_days']  = self::clamp( isset( $input['log_retention_days'] ) ? $input['log_retention_days'] : 30, 1, 365 );
		$out['max_webhook_bytes']   = self::clamp( isset( $input['max_webhook_bytes'] ) ? $input['max_webhook_bytes'] : 1048576, 1024, 10485760 );
		$out['max_attempts']        = self::clamp( isset( $input['max_attempts'] ) ? $input['max_attempts'] : 3, 1, 10 );
		$out['delete_data_on_uninstall'] = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;

		return $out;
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private static function clamp( $value, $min, $max ) {
		$value = (int) $value;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Byte limits for the extractor.
	 *
	 * @return array
	 */
	public static function archive_limits() {
		return array(
			'max_files'       => (int) self::get( 'max_files', 10000 ),
			'max_file_bytes'  => (int) self::get( 'max_file_mb', 50 ) * MB_IN_BYTES,
			'max_total_bytes' => (int) self::get( 'max_uncompressed_mb', 300 ) * MB_IN_BYTES,
		);
	}
}
