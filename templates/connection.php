<?php
/**
 * Connection template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title"><?php esc_html_e( 'GitHub Connection', 'githubsync' ); ?></h1>

	<div class="gs-card">
		<h2><?php esc_html_e( 'Status', 'githubsync' ); ?></h2>
		<p class="gs-big <?php echo $data['connected'] ? 'gs-ok' : 'gs-bad'; ?>">
			<?php echo $data['connected'] ? esc_html__( 'Connected', 'githubsync' ) : esc_html__( 'Not connected', 'githubsync' ); ?>
		</p>
		<p class="gs-muted"><?php echo esc_html( $data['summary'] ); ?></p>

		<?php if ( ! $data['encryption_ok'] ) : ?>
			<p class="gs-bad"><?php esc_html_e( 'Secure encryption is not configured, so credentials cannot be stored. Configure it on the Settings page first.', 'githubsync' ); ?></p>
		<?php endif; ?>

		<?php if ( $data['connected'] ) : ?>
			<div class="gs-actionbar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
					<?php wp_nonce_field( 'githubsync_test_connection' ); ?>
					<input type="hidden" name="action" value="githubsync_test_connection">
					<button type="submit" class="button"><?php esc_html_e( 'Test connection', 'githubsync' ); ?></button>
				</form>

				<a class="button" href="<?php echo esc_url( $data['browse_url'] ); ?>"><?php esc_html_e( 'Browse repositories', 'githubsync' ); ?></a>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Remove stored GitHub credentials?', 'githubsync' ) ); ?>');">
					<?php wp_nonce_field( 'githubsync_disconnect' ); ?>
					<input type="hidden" name="action" value="githubsync_disconnect">
					<button type="submit" class="button gs-danger"><?php esc_html_e( 'Disconnect', 'githubsync' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $data['error'] ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $data['error'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( $data['browse'] ) : ?>
		<h2 class="gs-section-title"><?php esc_html_e( 'Repositories', 'githubsync' ); ?></h2>

		<?php if ( empty( $data['repos'] ) ) : ?>
			<p class="gs-muted"><?php esc_html_e( 'No repositories were returned for this installation.', 'githubsync' ); ?></p>
		<?php else : ?>
			<table class="widefat striped gs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Repository', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Visibility', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Default branch', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Use', 'githubsync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['repos'] as $gs_repo ) : ?>
						<tr>
							<td><code><?php echo esc_html( $gs_repo['full_name'] ); ?></code></td>
							<td><?php echo esc_html( $gs_repo['visibility'] ); ?></td>
							<td><?php echo esc_html( $gs_repo['default_branch'] ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-new&gs_repo=' . rawurlencode( $gs_repo['full_name'] ) ) ); ?>">
									<?php esc_html_e( 'New deployment', 'githubsync' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="gs-pager">
				<?php if ( $data['paged'] > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'githubsync-connection', 'browse' => '1', 'paged' => $data['paged'] - 1, '_wpnonce' => wp_create_nonce( 'githubsync_browse' ) ), admin_url( 'admin.php' ) ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'githubsync' ); ?></a>
				<?php endif; ?>
				<?php if ( $data['has_next'] ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'githubsync-connection', 'browse' => '1', 'paged' => $data['paged'] + 1, '_wpnonce' => wp_create_nonce( 'githubsync_browse' ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'githubsync' ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<h2 class="gs-section-title"><?php esc_html_e( 'Connect a GitHub App (recommended)', 'githubsync' ); ?></h2>
	<div class="gs-card">
		<?php if ( ! empty( $data['manifest']['callback'] ) ) : ?>
			<?php $gs_cb = $data['manifest']['callback']; ?>
			<p class="<?php echo 'error' === $gs_cb['type'] ? 'gs-bad' : ( 'warning' === $gs_cb['type'] ? 'gs-warn' : 'gs-ok' ); ?>">
				<?php echo esc_html( $gs_cb['message'] ); ?>
			</p>
			<?php if ( '' !== $gs_cb['install_url'] ) : ?>
				<p class="gs-actionbar">
					<a class="button button-primary" href="<?php echo esc_url( $gs_cb['install_url'] ); ?>"><?php esc_html_e( 'Install the App on GitHub', 'githubsync' ); ?></a>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $data['manifest']['supported'] ) : ?>
			<?php if ( ! empty( $data['manifest']['form'] ) ) : ?>
				<p class="gs-muted"><?php esc_html_e( 'Sending the App manifest to GitHub…', 'githubsync' ); ?></p>
				<form id="gs-manifest" method="post" action="<?php echo esc_url( $data['manifest']['form']['action'] ); ?>">
					<input type="hidden" name="manifest" value="<?php echo esc_attr( $data['manifest']['form']['manifest'] ); ?>">
					<input type="hidden" name="state" value="<?php echo esc_attr( $data['manifest']['form']['state'] ); ?>">
					<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Continue to GitHub', 'githubsync' ); ?></button></p>
				</form>
				<script>document.getElementById('gs-manifest').submit();</script>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %s: GitHub App name that will be created. */
						esc_html__( 'Create "%s" on GitHub in one step. You approve it on github.com, and this site then stores the App ID, private key and webhook secret for you. The App requests only Metadata (read) and Contents (read).', 'githubsync' ),
						esc_html( $data['manifest']['app_name'] )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
					<?php wp_nonce_field( 'githubsync_begin_app_manifest' ); ?>
					<input type="hidden" name="action" value="githubsync_begin_app_manifest">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create GitHub App', 'githubsync' ); ?></button>
				</form>
			<?php endif; ?>
		<?php else : ?>
			<p class="gs-bad">
				<?php esc_html_e( 'One-click App creation needs this site to be served over HTTPS (or http://localhost), because GitHub only redirects back to those. Use the manual fields below instead.', 'githubsync' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $data['manifest']['has_app'] ) : ?>
			<hr>
			<p class="gs-muted">
				<?php esc_html_e( 'App created. Install it on the account and repositories you want to deploy, then pick the installation here.', 'githubsync' ); ?>
			</p>

			<p class="gs-actionbar">
				<?php if ( '' !== $data['manifest']['install_url'] ) : ?>
					<a class="button" href="<?php echo esc_url( $data['manifest']['install_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Install App on GitHub', 'githubsync' ); ?></a>
				<?php endif; ?>

				<?php if ( '' !== $data['manifest']['app_meta']['html_url'] ) : ?>
					<a class="button" href="<?php echo esc_url( $data['manifest']['app_meta']['html_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open App settings on GitHub', 'githubsync' ); ?></a>
				<?php endif; ?>

				<?php if ( $data['manifest']['key_ready'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
						<?php wp_nonce_field( 'githubsync_download_app_key' ); ?>
						<input type="hidden" name="action" value="githubsync_download_app_key">
						<button type="submit" class="button"><?php esc_html_e( 'Download private key', 'githubsync' ); ?></button>
					</form>
				<?php endif; ?>
			</p>
			<?php if ( $data['manifest']['key_ready'] ) : ?>
				<p class="description"><?php esc_html_e( 'Keep a copy of the private key somewhere safe: GitHub only shows it once, and the download is re-encrypted from this site.', 'githubsync' ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $data['manifest']['install_error'] ) : ?>
				<p class="gs-bad"><?php echo esc_html( $data['manifest']['install_error'] ); ?></p>
			<?php elseif ( empty( $data['manifest']['installations'] ) ) : ?>
				<p class="gs-muted"><?php esc_html_e( 'No installations found yet. Install the App on GitHub, then reload this page.', 'githubsync' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form">
					<?php wp_nonce_field( 'githubsync_install_app' ); ?>
					<input type="hidden" name="action" value="githubsync_install_app">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="gs-installation-pick"><?php esc_html_e( 'Installation', 'githubsync' ); ?></label></th>
							<td>
								<select id="gs-installation-pick" name="installation_id" class="regular-text">
									<?php foreach ( $data['manifest']['installations'] as $gs_install ) : ?>
										<option value="<?php echo esc_attr( $gs_install['installation_id'] ); ?>"<?php selected( $data['manifest']['current'], $gs_install['installation_id'] ); ?>>
											<?php
											printf(
												/* translators: 1: GitHub account login, 2: installation ID. */
												esc_html__( '%1$s (installation %2$s)', 'githubsync' ),
												esc_html( '' !== $gs_install['account'] ? $gs_install['account'] : __( 'unknown account', 'githubsync' ) ),
												esc_html( $gs_install['installation_id'] )
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
					<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Use this installation', 'githubsync' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<details class="gs-card"<?php echo $data['manifest']['supported'] ? '' : ' open'; ?>>
		<summary><strong><?php esc_html_e( 'Connect an existing GitHub App manually', 'githubsync' ); ?></strong></summary>
		<p class="gs-muted">
			<?php esc_html_e( 'Create a GitHub App, install it on the repositories you want to deploy, and paste its App ID, installation ID and private key below. Request only Metadata (read) and Contents (read).', 'githubsync' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form">
			<?php wp_nonce_field( 'githubsync_save_connection_app' ); ?>
			<input type="hidden" name="action" value="githubsync_save_connection_app">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gs-app-id"><?php esc_html_e( 'App ID', 'githubsync' ); ?></label></th>
					<td><input type="text" id="gs-app-id" name="app_id" class="regular-text" value="<?php echo esc_attr( $data['connection']['mode'] === 'app' ? $data['connection']['app_id'] : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-installation"><?php esc_html_e( 'Installation ID', 'githubsync' ); ?></label></th>
					<td>
						<input type="text" id="gs-installation" name="installation_id" class="regular-text" value="<?php echo esc_attr( $data['connection']['mode'] === 'app' ? $data['connection']['installation_id'] : '' ); ?>">
						<p class="description"><?php esc_html_e( 'Found in the URL of the installation settings page.', 'githubsync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gs-key"><?php esc_html_e( 'Private key (PEM)', 'githubsync' ); ?></label></th>
					<td>
						<textarea id="gs-key" name="private_key" rows="8" class="large-text code" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea>
						<p class="description"><?php esc_html_e( 'Paste the contents of the .pem file. It is encrypted before storage and never displayed again.', 'githubsync' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save GitHub App connection', 'githubsync' ); ?></button></p>
		</form>
	</details>

	<h2 class="gs-section-title"><?php esc_html_e( 'Or use a personal access token', 'githubsync' ); ?></h2>
	<div class="gs-card">
		<p class="gs-muted">
			<?php esc_html_e( 'A fine-grained token with Contents (read) and Metadata (read) works, but a GitHub App is preferred for long-term use because it is scoped to an installation and can be revoked centrally.', 'githubsync' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form">
			<?php wp_nonce_field( 'githubsync_save_connection_pat' ); ?>
			<input type="hidden" name="action" value="githubsync_save_connection_pat">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gs-pat"><?php esc_html_e( 'Token', 'githubsync' ); ?></label></th>
					<td>
						<input type="password" id="gs-pat" name="token" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Stored encrypted. Never shown in HTML, logs or REST responses after saving.', 'githubsync' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit"><button type="submit" class="button"><?php esc_html_e( 'Save token', 'githubsync' ); ?></button></p>
		</form>
	</div>
</div>
