<?php
/**
 * Deployment detail template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;

$gs_row    = $data['row'];
$gs_paused = 'paused' === $gs_row['status'];
?>
<div class="wrap gs-wrap">
	<?php \GitHubSync\Admin\AdminMenu::back_link( 'githubsync-deployments', __( 'All deployments', 'githubsync' ) ); ?>

	<div class="gs-page-head">
		<div class="gs-page-head__text">
			<span class="gs-page-head__icon" aria-hidden="true"><?php \GitHubSync\Admin\AdminMenu::icon( 'rocket' ); ?></span>
			<div>
				<h1 class="gs-title">
					<?php echo esc_html( $gs_row['name'] ); ?>
					<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</h1>
				<p class="gs-subtitle"><code><?php echo esc_html( $gs_row['repository_full_name'] ); ?></code> · <?php echo esc_html( $gs_row['branch'] ); ?></p>
			</div>
		</div>
		<div class="gs-page-head__actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
				<?php wp_nonce_field( 'githubsync_deploy_now' ); ?>
				<input type="hidden" name="action" value="githubsync_deploy_now">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
				<button type="submit" class="button button-primary"><?php \GitHubSync\Admin\AdminMenu::icon( 'zap' ); ?> <?php esc_html_e( 'Deploy now', 'githubsync' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-edit&id=' . (int) $gs_row['id'] ) ); ?>"><?php esc_html_e( 'Edit configuration', 'githubsync' ); ?></a>
		</div>
	</div>

	<div class="gs-grid">
		<div class="gs-card">
			<h2><?php \GitHubSync\Admin\AdminMenu::icon( 'branch' ); ?> <?php esc_html_e( 'Source', 'githubsync' ); ?></h2>
			<dl class="gs-defs">
				<div>
					<dt><?php esc_html_e( 'Repository', 'githubsync' ); ?></dt>
					<dd><code><?php echo esc_html( $gs_row['repository_full_name'] ); ?></code></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Branch', 'githubsync' ); ?></dt>
					<dd><code><?php echo esc_html( $gs_row['branch'] ); ?></code></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Source path', 'githubsync' ); ?></dt>
					<dd><code><?php echo esc_html( '' === $gs_row['source_path'] ? '/' : $gs_row['source_path'] ); ?></code></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Last deployed commit', 'githubsync' ); ?></dt>
					<dd><code><?php echo esc_html( $gs_row['last_deployed_sha'] ? \GitHubSync\Support\Helpers::short_sha( $gs_row['last_deployed_sha'] ) : '—' ); ?></code></dd>
				</div>
			</dl>
		</div>

		<div class="gs-card">
			<h2><?php \GitHubSync\Admin\AdminMenu::icon( 'folder' ); ?> <?php esc_html_e( 'Destination', 'githubsync' ); ?></h2>
			<dl class="gs-defs">
				<div>
					<dt><?php esc_html_e( 'Path', 'githubsync' ); ?></dt>
					<dd><code class="gs-path"><?php echo esc_html( \GitHubSync\Support\Helpers::relative_path( $gs_row['destination_path'] ) ); ?></code></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Type', 'githubsync' ); ?></dt>
					<dd><?php echo esc_html( $gs_row['deployment_type'] ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Auto deploy', 'githubsync' ); ?></dt>
					<dd><?php echo (int) $gs_row['auto_deploy'] ? esc_html__( 'On', 'githubsync' ) : esc_html__( 'Off', 'githubsync' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Manual deploy', 'githubsync' ); ?></dt>
					<dd><?php echo (int) $gs_row['manual_deploy'] ? esc_html__( 'On', 'githubsync' ) : esc_html__( 'Off', 'githubsync' ); ?></dd>
				</div>
			</dl>
		</div>
	</div>

	<div class="gs-card">
		<h2><?php \GitHubSync\Admin\AdminMenu::icon( 'link' ); ?> <?php esc_html_e( 'Webhook', 'githubsync' ); ?></h2>
		<p class="gs-muted"><?php esc_html_e( 'Add this as a repository webhook in GitHub. Content type: application/json. Event: push only.', 'githubsync' ); ?></p>
		<div class="gs-copy-field">
			<label for="gs-payload"><?php esc_html_e( 'Payload URL', 'githubsync' ); ?></label>
			<div class="gs-copy-field__row">
				<input type="text" id="gs-payload" readonly class="large-text code" value="<?php echo esc_attr( $data['webhook_url'] ); ?>">
				<button type="button" class="button gs-copy" data-gs-copy="#gs-payload" data-gs-copy-idle="<?php esc_attr_e( 'Copy', 'githubsync' ); ?>" data-gs-copy-done="<?php esc_attr_e( 'Copied', 'githubsync' ); ?>"><?php \GitHubSync\Admin\AdminMenu::icon( 'copy' ); ?> <?php esc_html_e( 'Copy', 'githubsync' ); ?></button>
			</div>
		</div>
		<div class="gs-copy-field">
			<label for="gs-secret"><?php esc_html_e( 'Secret', 'githubsync' ); ?></label>
			<div class="gs-copy-field__row">
				<input type="text" id="gs-secret" readonly class="large-text code" value="<?php echo esc_attr( $data['secret'] ); ?>">
				<button type="button" class="button gs-copy" data-gs-copy="#gs-secret" data-gs-copy-idle="<?php esc_attr_e( 'Copy', 'githubsync' ); ?>" data-gs-copy-done="<?php esc_attr_e( 'Copied', 'githubsync' ); ?>"><?php \GitHubSync\Admin\AdminMenu::icon( 'copy' ); ?> <?php esc_html_e( 'Copy', 'githubsync' ); ?></button>
			</div>
		</div>
		<p class="gs-warn"><?php esc_html_e( 'Treat this secret like a password. It is stored encrypted and is never written to logs.', 'githubsync' ); ?></p>

		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="gs-form"
			data-gs-confirm="<?php esc_attr_e( 'Generate a new secret?', 'githubsync' ); ?>"
			data-gs-confirm-text="<?php esc_attr_e( 'The old secret stops working immediately. You must paste the new one into the GitHub webhook settings before the next push.', 'githubsync' ); ?>"
			data-gs-confirm-ok="<?php esc_attr_e( 'Generate new secret', 'githubsync' ); ?>"
		>
			<?php wp_nonce_field( 'githubsync_regenerate_secret' ); ?>
			<input type="hidden" name="action" value="githubsync_regenerate_secret">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
			<p class="gs-field-actions">
				<button type="submit" class="button"><?php \GitHubSync\Admin\AdminMenu::icon( 'refresh' ); ?> <?php esc_html_e( 'Regenerate secret', 'githubsync' ); ?></button>
			</p>
		</form>
	</div>

	<div class="gs-section-head">
		<h2 class="gs-section-title"><?php esc_html_e( 'Actions', 'githubsync' ); ?></h2>
	</div>

	<div class="gs-card">
		<div class="gs-actionbar">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
				<?php wp_nonce_field( 'githubsync_toggle_pause' ); ?>
				<input type="hidden" name="action" value="githubsync_toggle_pause">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
				<button type="submit" class="button"><?php echo $gs_paused ? esc_html__( 'Resume auto-deploy', 'githubsync' ) : esc_html__( 'Pause auto-deploy', 'githubsync' ); ?></button>
			</form>

			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-edit&id=' . (int) $gs_row['id'] ) ); ?>"><?php esc_html_e( 'Edit configuration', 'githubsync' ); ?></a>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				class="gs-inline"
				data-gs-confirm="<?php esc_attr_e( 'Delete this configuration?', 'githubsync' ); ?>"
				data-gs-confirm-text="<?php esc_attr_e( 'Only the configuration is removed. Files already deployed, their backups and the deployment history are kept.', 'githubsync' ); ?>"
				data-gs-confirm-ok="<?php esc_attr_e( 'Delete configuration', 'githubsync' ); ?>"
				data-gs-confirm-danger="1"
			>
				<?php wp_nonce_field( 'githubsync_delete_deployment' ); ?>
				<input type="hidden" name="action" value="githubsync_delete_deployment">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
				<input type="hidden" name="confirm" value="1">
				<button type="submit" class="button gs-danger"><?php esc_html_e( 'Delete configuration', 'githubsync' ); ?></button>
			</form>
		</div>
	</div>

	<div class="gs-section-head">
		<h2 class="gs-section-title"><?php esc_html_e( 'Recent deployments', 'githubsync' ); ?></h2>
	</div>

	<?php if ( empty( $data['jobs'] ) ) : ?>
		<?php
		\GitHubSync\Admin\AdminMenu::empty_state(
			__( 'Nothing has run yet', 'githubsync' ),
			__( 'Deploy manually with the “Deploy now” button, or push to the configured branch once auto-deploy is on.', 'githubsync' ),
			'',
			'activity'
		);
		?>
	<?php else : ?>
		<div class="gs-table-wrap">
			<table class="gs-table">
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
							<td data-gs-label="<?php esc_attr_e( 'Job', 'githubsync' ); ?>">#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Trigger', 'githubsync' ); ?>"><?php echo esc_html( $gs_job['trigger_type'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Commit', 'githubsync' ); ?>"><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
							<td data-gs-label="<?php esc_attr_e( 'Status', 'githubsync' ); ?>">
								<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( ! empty( $gs_job['error_code'] ) ) : ?>
									<div class="gs-muted"><code><?php echo esc_html( $gs_job['error_code'] ); ?></code> <?php echo esc_html( (string) $gs_job['error_message'] ); ?></div>
								<?php endif; ?>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Files', 'githubsync' ); ?>" class="gs-muted">
								+<?php echo esc_html( (string) (int) $gs_job['files_added'] ); ?>
								~<?php echo esc_html( (string) (int) $gs_job['files_updated'] ); ?>
								−<?php echo esc_html( (string) (int) $gs_job['files_removed'] ); ?>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Started', 'githubsync' ); ?>"><?php echo esc_html( $gs_job['started_at'] ? $gs_job['started_at'] : '—' ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Actions', 'githubsync' ); ?>">
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
		</div>
	<?php endif; ?>

	<div class="gs-section-head">
		<h2 class="gs-section-title"><?php esc_html_e( 'GitHub history', 'githubsync' ); ?></h2>
		<span class="gs-muted"><?php esc_html_e( 'Recent commits on', 'githubsync' ); ?> <code><?php echo esc_html( $gs_row['branch'] ); ?></code></span>
	</div>

	<?php if ( '' !== $data['commits_error'] ) : ?>
		<?php \GitHubSync\Admin\AdminMenu::notice( 'warning', esc_html( $data['commits_error'] ) ); ?>
	<?php elseif ( empty( $data['commits'] ) ) : ?>
		<?php
		\GitHubSync\Admin\AdminMenu::empty_state(
			__( 'No commits found', 'githubsync' ),
			__( 'GitHub did not return any commits for this branch. The connection may need to be checked.', 'githubsync' ),
			'',
			'history'
		);
		?>
	<?php else : ?>
		<div class="gs-table-wrap">
			<table class="gs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Commit', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Message', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Author', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Date', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Action', 'githubsync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['commits'] as $gs_commit ) : ?>
						<?php
						$gs_current = strtolower( (string) $gs_row['last_deployed_sha'] ) === (string) $gs_commit['sha'];
						$gs_stamp   = \GitHubSync\Support\Helpers::to_timestamp( $gs_commit['date'] );
						$gs_date    = $gs_stamp ? date_i18n( 'M j, Y H:i', $gs_stamp ) : '—';
						?>
						<tr>
							<td data-gs-label="<?php esc_attr_e( 'Commit', 'githubsync' ); ?>"><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_commit['sha'] ) ); ?></code></td>
							<td data-gs-label="<?php esc_attr_e( 'Message', 'githubsync' ); ?>">
								<?php if ( '' !== $gs_commit['url'] ) : ?>
									<a href="<?php echo esc_url( $gs_commit['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $gs_commit['message'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $gs_commit['message'] ); ?>
								<?php endif; ?>
								<?php if ( $gs_current ) : ?>
									<span class="gs-badge gs-badge--muted"><?php esc_html_e( 'Deployed', 'githubsync' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Author', 'githubsync' ); ?>" class="gs-muted"><?php echo esc_html( $gs_commit['author'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Date', 'githubsync' ); ?>" class="gs-muted"><?php echo esc_html( $gs_date ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Action', 'githubsync' ); ?>">
								<?php if ( $gs_current ) : ?>
									<span class="gs-muted"><?php esc_html_e( 'Current version', 'githubsync' ); ?></span>
								<?php else : ?>
									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										class="gs-inline"
										data-gs-confirm="<?php esc_attr_e( 'Roll back to this commit?', 'githubsync' ); ?>"
										data-gs-confirm-text="<?php esc_attr_e( 'The files from this GitHub commit will be deployed to this site. A verified backup of the current files is taken first.', 'githubsync' ); ?>"
										data-gs-confirm-ok="<?php esc_attr_e( 'Roll back', 'githubsync' ); ?>"
									>
										<?php wp_nonce_field( 'githubsync_rollback_commit' ); ?>
										<input type="hidden" name="action" value="githubsync_rollback_commit">
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
										<input type="hidden" name="commit_sha" value="<?php echo esc_attr( $gs_commit['sha'] ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Roll back', 'githubsync' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $data['rollbacks'] ) ) : ?>
		<div class="gs-section-head">
			<h2 class="gs-section-title"><?php esc_html_e( 'Backup restore points', 'githubsync' ); ?></h2>
		</div>
		<div class="gs-table-wrap">
			<table class="gs-table">
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
							<td data-gs-label="<?php esc_attr_e( 'Job', 'githubsync' ); ?>">#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Commit', 'githubsync' ); ?>"><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
							<td data-gs-label="<?php esc_attr_e( 'Completed', 'githubsync' ); ?>"><?php echo esc_html( (string) $gs_job['finished_at'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Restore', 'githubsync' ); ?>">
								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									class="gs-inline"
									data-gs-confirm="<?php esc_attr_e( 'Roll back to this deployment?', 'githubsync' ); ?>"
									data-gs-confirm-text="<?php esc_attr_e( 'The files currently deployed will be replaced by this earlier version. A backup of the current files is taken first, so the rollback can itself be undone.', 'githubsync' ); ?>"
									data-gs-confirm-ok="<?php esc_attr_e( 'Roll back', 'githubsync' ); ?>"
									data-gs-confirm-danger="1"
								>
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
		</div>
	<?php endif; ?>

	<div class="gs-section-head">
		<h2 class="gs-section-title"><?php esc_html_e( 'Log', 'githubsync' ); ?></h2>
	</div>
	<?php if ( empty( $data['logs'] ) ) : ?>
		<?php \GitHubSync\Admin\AdminMenu::empty_state( __( 'No log entries', 'githubsync' ), __( 'Log entries appear here after the first deployment.', 'githubsync' ), '', 'list' ); ?>
	<?php else : ?>
		<div class="gs-table-wrap">
			<table class="gs-table gs-log">
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
							<td data-gs-label="<?php esc_attr_e( 'Time', 'githubsync' ); ?>" class="gs-muted"><?php echo esc_html( $gs_log['created_at'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Level', 'githubsync' ); ?>"><?php echo esc_html( $gs_log['level'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Event', 'githubsync' ); ?>"><code><?php echo esc_html( $gs_log['event_code'] ); ?></code></td>
							<td data-gs-label="<?php esc_attr_e( 'Message', 'githubsync' ); ?>"><?php echo esc_html( $gs_log['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
