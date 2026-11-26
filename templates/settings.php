<?php
/**
 * Settings template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;

$gs_settings = $data['settings'];
?>
<div class="wrap gs-wrap">
	<?php
	\GitHubSync\Admin\AdminMenu::page_head(
		__( 'GitHubSync Settings', 'githubsync' ),
		__( 'Credential storage, deployment guardrails and retention. The defaults suit most sites.', 'githubsync' ),
		'',
		'settings'
	);

	\GitHubSync\Admin\AdminMenu::notice(
		$data['encryption_ok'] ? 'success' : 'error',
		$data['encryption_ok']
			? __( '<strong>Credential encryption is on.</strong> A site key is available, so GitHub credentials are encrypted at rest with authenticated encryption.', 'githubsync' )
			: __( '<strong>Credential encryption is off.</strong> No key is configured, so nothing will be stored until one exists. There is no plaintext fallback.', 'githubsync' )
	);
	?>

	<div class="gs-card">
		<h2><?php \GitHubSync\Admin\AdminMenu::icon( 'shield' ); ?> <?php esc_html_e( 'Credential encryption', 'githubsync' ); ?></h2>

		<?php if ( $data['encryption_ok'] ) : ?>
			<p class="gs-muted">
				<?php if ( $data['has_constant'] ) : ?>
					<?php esc_html_e( 'Key source: GITHUBSYNC_ENCRYPTION_KEY constant in wp-config.php.', 'githubsync' ); ?>
				<?php else : ?>
					<?php
					printf(
						/* translators: %s: key file path. */
						esc_html__( 'Key source: %s', 'githubsync' ),
						'<code>' . esc_html( $data['key_file'] ) . '</code>'
					);
					?>
				<?php endif; ?>
			</p>
			<p class="gs-muted"><?php esc_html_e( 'GitHub tokens, App private keys and webhook secrets are encrypted before they are written to the database.', 'githubsync' ); ?></p>
		<?php else : ?>
			<div class="gs-check-group">
				<p><strong><?php esc_html_e( 'Option A:', 'githubsync' ); ?></strong> <?php esc_html_e( 'let GitHubSync create a key file outside the web root.', 'githubsync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form">
					<?php wp_nonce_field( 'githubsync_generate_key' ); ?>
					<input type="hidden" name="action" value="githubsync_generate_key">
					<button type="submit" class="button button-primary"><?php \GitHubSync\Admin\AdminMenu::icon( 'key' ); ?> <?php esc_html_e( 'Generate key file', 'githubsync' ); ?></button>
				</form>
			</div>
			<p><strong><?php esc_html_e( 'Option B:', 'githubsync' ); ?></strong> <?php esc_html_e( 'add a constant to wp-config.php using a 32-byte base64 key:', 'githubsync' ); ?></p>
			<pre class="gs-code">define( 'GITHUBSYNC_ENCRYPTION_KEY', 'base64-of-32-random-bytes' );</pre>
		<?php endif; ?>
	</div>

	<div class="gs-card">
		<h2><?php \GitHubSync\Admin\AdminMenu::icon( 'tools' ); ?> <?php esc_html_e( 'Limits and retention', 'githubsync' ); ?></h2>
		<p class="gs-muted"><?php esc_html_e( 'These limits protect the server from very large or unusual deployments. The defaults suit most sites.', 'githubsync' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form gs-form--dense">
			<?php wp_nonce_field( 'githubsync_save_settings' ); ?>
			<input type="hidden" name="action" value="githubsync_save_settings">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gs-max-archive"><?php esc_html_e( 'Maximum archive download (MB)', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-max-archive" name="githubsync[max_archive_mb]" min="1" max="2048" value="<?php echo esc_attr( (string) $gs_settings['max_archive_mb'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-max-uncompressed"><?php esc_html_e( 'Maximum uncompressed size (MB)', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-max-uncompressed" name="githubsync[max_uncompressed_mb]" min="1" max="8192" value="<?php echo esc_attr( (string) $gs_settings['max_uncompressed_mb'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-max-files"><?php esc_html_e( 'Maximum file count', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-max-files" name="githubsync[max_files]" min="1" max="100000" value="<?php echo esc_attr( (string) $gs_settings['max_files'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-max-file"><?php esc_html_e( 'Maximum individual file size (MB)', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-max-file" name="githubsync[max_file_mb]" min="1" max="1024" value="<?php echo esc_attr( (string) $gs_settings['max_file_mb'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-retention"><?php esc_html_e( 'Backups to keep per deployment', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-retention" name="githubsync[backup_retention]" min="1" max="20" value="<?php echo esc_attr( (string) $gs_settings['backup_retention'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-log-retention"><?php esc_html_e( 'Log retention (days)', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-log-retention" name="githubsync[log_retention_days]" min="1" max="365" value="<?php echo esc_attr( (string) $gs_settings['log_retention_days'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-webhook-bytes"><?php esc_html_e( 'Maximum webhook body (bytes)', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-webhook-bytes" name="githubsync[max_webhook_bytes]" min="1024" max="10485760" value="<?php echo esc_attr( (string) $gs_settings['max_webhook_bytes'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-attempts"><?php esc_html_e( 'Maximum attempts per job', 'githubsync' ); ?></label></th>
					<td><input type="number" id="gs-attempts" name="githubsync[max_attempts]" min="1" max="10" value="<?php echo esc_attr( (string) $gs_settings['max_attempts'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Self-updates', 'githubsync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="githubsync[self_update_enabled]" value="1" <?php checked( (int) $gs_settings['self_update_enabled'], 1 ); ?> <?php disabled( $data['update_locked'] ); ?>>
							<?php esc_html_e( 'Show GitHubSync updates on the Plugins screen and allow one-click updates', 'githubsync' ); ?>
						</label>
						<?php if ( $data['update_locked'] ) : ?>
							<p class="description"><?php esc_html_e( 'Controlled by the GITHUBSYNC_DISABLE_SELF_UPDATE constant.', 'githubsync' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-update-url"><?php esc_html_e( 'Release feed URL', 'githubsync' ); ?></label></th>
					<td>
						<input type="url" id="gs-update-url" name="update_url" class="regular-text" value="<?php echo esc_attr( $data['update_url'] ); ?>" placeholder="https://example.com/update.json">
						<p class="description"><?php esc_html_e( 'Leave the public release feed URL in place unless you host your own manifest.', 'githubsync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'githubsync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="githubsync[delete_data_on_uninstall]" value="1" <?php checked( (int) $gs_settings['delete_data_on_uninstall'], 1 ); ?>>
							<?php esc_html_e( 'Delete GitHubSync tables and settings when the plugin is uninstalled', 'githubsync' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Deployed files and backups are never deleted automatically.', 'githubsync' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'githubsync' ); ?></button></p>
		</form>
	</div>

	<div class="gs-card">
		<h2><?php \GitHubSync\Admin\AdminMenu::icon( 'refresh' ); ?> <?php esc_html_e( 'Plugin updates', 'githubsync' ); ?></h2>
		<p class="gs-muted"><?php echo esc_html( $data['update_status'] ); ?></p>
		<p class="gs-muted"><?php esc_html_e( 'New versions appear on Plugins and Dashboard → Updates, with release notes and a one-click update link. Every package is verified against a SHA-256 digest before installation.', 'githubsync' ); ?></p>
		<?php if ( $data['update_on'] ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Go to Dashboard → Updates', 'githubsync' ); ?></a></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form gs-inline">
			<?php wp_nonce_field( 'githubsync_check_update' ); ?>
			<input type="hidden" name="action" value="githubsync_check_update">
			<button type="submit" class="button"><?php \GitHubSync\Admin\AdminMenu::icon( 'refresh' ); ?> <?php esc_html_e( 'Check for updates now', 'githubsync' ); ?></button>
		</form>
	</div>
</div>
