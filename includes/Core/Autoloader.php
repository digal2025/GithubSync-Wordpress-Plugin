<?php
/**
 * Minimal PSR-4 autoloader (no Composer dependency at runtime).
 *
 * @package GitHubSync
 */

namespace GitHubSync\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps GitHubSync\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Namespace prefix.
	 */
	private const PREFIX = 'GitHubSync\\';

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', '/', $relative );
		$path     = GITHUBSYNC_DIR . 'includes/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
