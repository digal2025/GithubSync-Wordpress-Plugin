<?php
/**
 * Plugin Name:       GitHubSync
 * Plugin URI:        https://example.com/githubsync
 * Description:       Safely deploy selected files from one or more GitHub repositories to configured WordPress directories whenever a branch is pushed.
 * Version:           0.5.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            GitHubSync
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       githubsync
 * Domain Path:       /languages
 *
 * @package GitHubSync
 */

defined( 'ABSPATH' ) || exit;

define( 'GITHUBSYNC_VERSION', '0.5.0' );
define( 'GITHUBSYNC_FILE', __FILE__ );
define( 'GITHUBSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GITHUBSYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'GITHUBSYNC_MIN_PHP', '7.4' );
define( 'GITHUBSYNC_MIN_WP', '6.2' );

require_once GITHUBSYNC_DIR . 'includes/Core/Autoloader.php';

GitHubSync\Core\Autoloader::register();

register_activation_hook( __FILE__, array( 'GitHubSync\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GitHubSync\\Core\\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded so optional integrations
 * (Action Scheduler, WP-CLI) are detectable.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( version_compare( PHP_VERSION, GITHUBSYNC_MIN_PHP, '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: 1: required PHP version, 2: current PHP version. */
								__( 'GitHubSync requires PHP %1$s or newer. This server runs %2$s.', 'githubsync' ),
								GITHUBSYNC_MIN_PHP,
								PHP_VERSION
							)
						)
					);
				}
			);
			return;
		}

		GitHubSync\Core\Plugin::instance()->boot();
	},
	5
);
