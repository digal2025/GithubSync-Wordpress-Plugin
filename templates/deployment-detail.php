<?php
/**
 * Deployment detail template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;

$gs_row  = $data['row'];
$gs_paused = 'paused' === $gs_row['status'];
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title">
		<?php echo esc_html( $gs_row['name'] ); ?>
		<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</h1>

	<p class="gs-muted">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployments' ) ); ?>">&larr; <?php esc_html_e( 'All deployments', 'githubsync' ); ?></a>
	</p>

	<div class="gs-grid">
		<div class="gs-card">
			<h2><?php esc_html_e( 'Source', 'githubsync' ); ?></h2>
			<p><code><?php echo esc_html( $gs_row['repository_full_name'] ); ?></code></p>
			<p class="gs-muted">
				<?php esc_html_e( 'Branch:', 'githubsync' ); ?> <code><?php echo esc_html( $gs_row['branch'] ); ?></code><br>
				<?php esc_html_e( 'Source path:', 'githubsync' ); ?> <code><?php echo esc_html( '' === $gs_row['source_path'] ? '/' : $gs_row['source_path'] ); ?></code>
			</p>
			<p class="gs-muted">
				<?php esc_html_e( 'Last deployed commit:', 'githubsync' ); ?>
				<code><?php echo esc_html( $gs_row['last_deployed_sha'] ? \GitHubSync\Support\Helpers::short_sha( $gs_row['last_deployed_sha'] ) : '—' ); ?></code>
			</p>
		</div>

		<div class="gs-card">
			<h2><?php esc_html_e( 'Destination', 'githubsync' ); ?></h2>
			<p><code class="gs-path"><?php echo esc_html( $gs_row['destination_path'] ); ?></code></p>
			<p class="gs-muted">
				<?php esc_html_e( 'Type:', 'githubsync' ); ?> <?php echo esc_html( $gs_row['deployment_type'] ); ?><br>
				<?php esc_html_e( 'Auto deploy:', 'githubsync' ); ?> <?php echo (int) $gs_row['auto_deploy'] ? esc_html__( 'On', 'githubsync' ) : esc_html__( 'Off', 'githubsync' ); ?><br>
				<?php esc_html_e( 'Manual deploy:', 'githubsync' ); ?> <?php echo (int) $gs_row['manual_deploy'] ? esc_html__( 'On', 'githubsync' ) : esc_html__( 'Off', 'githubsync' ); ?>
			</p>
		</div>

		<div class="gs-card gs-card--wide">
			<h2><?php esc_html_e( 'Webhook', 'githubsync' ); ?></h2>
			<p class="gs-muted"><?php esc_html_e( 'Add this as a repository webhook in GitHub. Content type: application/json. Event: push only.', 'githubsync' ); ?></p>
			<p><label for="gs-payload"><?php esc_html_e( 'Payload URL', 'githubsync' ); ?></label><br>
				<input type="text" id="gs-payload" readonly class="large-text code" value="<?php echo esc_attr( $data['webhook_url'] ); ?>">
			</p>
			<p><label for="gs-secret"><?php esc_html_e( 'Secret', 'githubsync' ); ?></label><br>
				<input type="text" id="gs-secret" readonly class="large-text code" value="<?php echo esc_attr( $data['secret'] ); ?>">
			</p>
			<p class="gs-warn"><?php esc_html_e( 'Treat this secret like a password. It is stored encrypted and is never written to logs.', 'githubsync' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Generate a new secret? You must update it in GitHub immediately.', 'githubsync' ) ); ?>');">
				<?php wp_nonce_field( 'githubsync_regenerate_secret' ); ?>
				<input type="hidden" name="action" value="githubsync_regenerate_secret">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Regenerate secret', 'githubsync' ); ?></button>
			</form>
		</div>
	</div>

	<h2 class="gs-section-title"><?php esc_html_e( 'Actions', 'githubsync' ); ?></h2>

	<div class="gs-actionbar">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
			<?php wp_nonce_field( 'githubsync_deploy_now' ); ?>
			<input type="hidden" name="action" value="githubsync_deploy_now">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Deploy now', 'githubsync' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
			<?php wp_nonce_field( 'githubsync_toggle_pause' ); ?>
			<input type="hidden" name="action" value="githubsync_toggle_pause">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
			<button type="submit" class="button"><?php echo $gs_paused ? esc_html__( 'Resume auto-deploy', 'githubsync' ) : esc_html__( 'Pause auto-deploy', 'githubsync' ); ?></button>
		</form>

		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-edit&id=' . (int) $gs_row['id'] ) ); ?>"><?php esc_html_e( 'Edit configuration', 'githubsync' ); ?></a>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this configuration? Deployed files, backups and history are kept.', 'githubsync' ) ); ?>');">
			<?php wp_nonce_field( 'githubsync_delete_deployment' ); ?>
			<input type="hidden" name="action" value="githubsync_delete_deployment">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
			<input type="hidden" name="confirm" value="1">
			<button type="submit" class="button gs-danger"><?php esc_html_e( 'Delete configuration', 'githubsync' ); ?></button>
		</form>
	</div>

	<h2 class="gs-section-title"><?php esc_html_e( 'Recent deployments', 'githubsync' ); ?></h2>

	<?php if ( empty( $data['jobs'] ) ) : ?>
		<p class="gs-muted"><?php esc_html_e( 'Nothing has run yet.', 'githubsync' ); ?></p>
	<?php else : ?>
		<table class="widefat striped gs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Commit', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Files', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Started', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'githubsync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['jobs'] as $gs_job ) : ?>
					<tr>
						<td>#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
						<td><?php echo esc_html( $gs_job['trigger_type'] ); ?></td>
						<td><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
						<td>
							<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( ! empty( $gs_job['error_code'] ) ) : ?>
								<div class="gs-muted"><code><?php echo esc_html( $gs_job['error_code'] ); ?></code> <?php echo esc_html( (string) $gs_job['error_message'] ); ?></div>
							<?php endif; ?>
						</td>
						<td class="gs-muted">
							+<?php echo esc_html( (string) (int) $gs_job['files_added'] ); ?>
							~<?php echo esc_html( (string) (int) $gs_job['files_updated'] ); ?>
							−<?php echo esc_html( (string) (int) $gs_job['files_removed'] ); ?>
						</td>
						<td><?php echo esc_html( $gs_job['started_at'] ? $gs_job['started_at'] : '—' ); ?></td>
						<td>
							<?php if ( in_array( $gs_job['status'], array( 'failed', 'cancelled' ), true ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
									<?php wp_nonce_field( 'githubsync_retry_job' ); ?>
									<input type="hidden" name="action" value="githubsync_retry_job">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
									<input type="hidden" name="job_id" value="<?php echo esc_attr( (string) $gs_job['id'] ); ?>">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Retry', 'githubsync' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $data['rollbacks'] ) ) : ?>
		<h2 class="gs-section-title"><?php esc_html_e( 'Rollback points', 'githubsync' ); ?></h2>
		<table class="widefat striped gs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Commit', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Completed', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Restore', 'githubsync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['rollbacks'] as $gs_job ) : ?>
					<tr>
						<td>#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
						<td><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
						<td><?php echo esc_html( (string) $gs_job['finished_at'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Restore this deployment over the current files?', 'githubsync' ) ); ?>');">
								<?php wp_nonce_field( 'githubsync_rollback' ); ?>
								<input type="hidden" name="action" value="githubsync_rollback">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
								<input type="hidden" name="job_id" value="<?php echo esc_attr( (string) $gs_job['id'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Roll back to this', 'githubsync' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 class="gs-section-title"><?php esc_html_e( 'Log', 'githubsync' ); ?></h2>
	<?php if ( empty( $data['logs'] ) ) : ?>
		<p class="gs-muted"><?php esc_html_e( 'No log entries.', 'githubsync' ); ?></p>
	<?php else : ?>
		<table class="widefat striped gs-table gs-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Level', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Event', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Message', 'githubsync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['logs'] as $gs_log ) : ?>
					<tr>
						<td class="gs-muted"><?php echo esc_html( $gs_log['created_at'] ); ?></td>
						<td><?php echo esc_html( $gs_log['level'] ); ?></td>
						<td><code><?php echo esc_html( $gs_log['event_code'] ); ?></code></td>
						<td><?php echo esc_html( $gs_log['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
