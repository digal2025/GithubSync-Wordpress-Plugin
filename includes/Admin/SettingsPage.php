<?php
/**
 * Settings page.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Admin;

use GitHubSync\Security\Capability;
use GitHubSync\Security\CredentialVault;
use GitHubSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Limits, retention, encryption key and uninstall behaviour.
 */
final class SettingsPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		Capability::assert();

		$data = array(
			'settings'      => Settings::all(),
			'encryption_ok' => CredentialVault::is_configured(),
			'key_file'      => CredentialVault::default_key_file(),
			'has_constant'  => defined( 'GITHUBSYNC_ENCRYPTION_KEY' ),
		);

		include GITHUBSYNC_DIR . 'templates/settings.php';
	}
}
