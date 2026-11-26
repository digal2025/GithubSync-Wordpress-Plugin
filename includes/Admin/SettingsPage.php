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
use GitHubSync\Update\Updater;

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
			'update_on'     => Updater::has_pending_update(),
			'update_locked' => defined( 'GITHUBSYNC_DISABLE_SELF_UPDATE' ) && GITHUBSYNC_DISABLE_SELF_UPDATE,
			'update_status' => Updater::describe_status(),
			'update_url'    => Updater::manifest_url(),
		);

		include GITHUBSYNC_DIR . 'templates/settings.php';
	}
}
