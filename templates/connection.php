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
	</div>

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
