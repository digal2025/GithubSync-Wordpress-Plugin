<?php
/**
 * Dashboard template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;

$gs_connected = ! empty( $data['connected'] );
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title"><?php esc_html_e( 'GitHubSync', 'githubsync' ); ?></h1>
	<p class="gs-subtitle"><?php esc_html_e( 'Deploy selected files from GitHub to this WordPress installation on push.', 'githubsync' ); ?></p>

	<?php
	$gs_steps      = isset( $data['steps'] ) ? (array) $data['steps'] : array();
	$gs_total      = count( $gs_steps );
	$gs_done       = 0;
	$gs_current    = 0;
	$gs_found_open = false;

	foreach ( $gs_steps as $gs_i => $gs_step ) {
		if ( ! empty( $gs_step['done'] ) ) {
			$gs_done++;
		} elseif ( ! $gs_found_open ) {
			// Open on the first unfinished step: that is what to do next.
			$gs_current    = $gs_i;
			$gs_found_open = true;
		}
	}

	$gs_complete = $gs_total > 0 && $gs_done === $gs_total;
	$gs_cron_ok  = empty( $data['cron_disabled'] ) && ! empty( $data['cron_scheduled'] );
	$gs_closed   = ! empty( $data['dismissed'] );
	?>

	<div class="gs-checkbar">
		<?php if ( $gs_closed || 0 === $gs_total ) : ?>
			<?php
			// Nothing to open: the checklist is closed or there is no step data.
			if ( $gs_closed ) :
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-checkbar__form">
					<?php wp_nonce_field( 'githubsync_restore_checklist' ); ?>
					<input type="hidden" name="action" value="githubsync_restore_checklist">
					<button type="submit" class="button button-small"><?php esc_html_e( 'Show the setup checklist again', 'githubsync' ); ?></button>
				</form>
			<?php endif; ?>
		<?php else : ?>
			<button
				type="button"
				class="button"
				data-gs-dialog-open="gs-setup-checklist"
				aria-haspopup="dialog"
				aria-controls="gs-setup-checklist"
			>
				<?php esc_html_e( 'Show setup checklist', 'githubsync' ); ?>
				<span class="gs-badge gs-badge--<?php echo $gs_complete ? 'success' : 'warning'; ?>">
					<?php
					printf(
						/* translators: 1: completed steps, 2: total steps. */
						esc_html__( '%1$d of %2$d', 'githubsync' ),
						(int) $gs_done,
						(int) $gs_total
					);
					?>
				</span>
			</button>
		<?php endif; ?>
	</div>

	<?php if ( ! $gs_closed && $gs_total > 0 ) : ?>
		<?php $gs_percent = (int) round( 100 * $gs_done / $gs_total ); ?>

		<dialog
			id="gs-setup-checklist"
			class="gs-dialog<?php echo $gs_complete ? ' gs-dialog--complete' : ''; ?>"
			aria-labelledby="gs-setup-checklist-title"
			data-gs-carousel
			data-gs-total="<?php echo esc_attr( (string) $gs_total ); ?>"
			data-gs-current="<?php echo esc_attr( (string) $gs_current ); ?>"
		>
			<div class="gs-dialog__inner">
				<div class="gs-dialog__head">
					<div class="gs-dialog__title">
						<h2 id="gs-setup-checklist-title"><?php esc_html_e( 'Setup checklist', 'githubsync' ); ?></h2>
						<p class="gs-muted">
							<?php
							printf(
								/* translators: 1: completed steps, 2: total steps. */
								esc_html__( '%1$d of %2$d steps complete. Use the arrows to move through them in order.', 'githubsync' ),
								(int) $gs_done,
								(int) $gs_total
							);
							?>
						</p>
						<div class="gs-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $gs_total ); ?>" aria-valuenow="<?php echo esc_attr( (string) $gs_done ); ?>" aria-label="<?php esc_attr_e( 'Setup progress', 'githubsync' ); ?>">
							<span class="gs-progress__bar" style="width: <?php echo esc_attr( (string) $gs_percent ); ?>%"></span>
						</div>
					</div>

					<button type="button" class="gs-dialog__x" data-gs-dialog-close aria-label="<?php esc_attr_e( 'Close setup checklist', 'githubsync' ); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>

				<div class="gs-carousel" role="group" aria-roledescription="<?php esc_attr_e( 'carousel', 'githubsync' ); ?>" aria-label="<?php esc_attr_e( 'Setup steps', 'githubsync' ); ?>">
					<div class="gs-carousel__viewport">
						<?php foreach ( $gs_steps as $gs_i => $gs_step ) : ?>
							<?php
							$gs_is_done = ! empty( $gs_step['done'] );
							$gs_page    = isset( $gs_step['action'][1] ) ? (string) $gs_step['action'][1] : '';
							$gs_label   = isset( $gs_step['action'][0] ) ? (string) $gs_step['action'][0] : '';
							?>
							<section
								class="gs-slide<?php echo $gs_is_done ? ' gs-slide--done' : ''; ?>"
								data-gs-slide
								data-gs-index="<?php echo esc_attr( (string) $gs_i ); ?>"
								role="group"
								aria-roledescription="<?php esc_attr_e( 'step', 'githubsync' ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: 1: step number, 2: total steps. */ __( 'Step %1$d of %2$d', 'githubsync' ), $gs_i + 1, $gs_total ) ); ?>"
								<?php echo $gs_i === $gs_current ? '' : 'hidden'; ?>
							>
								<p class="gs-slide__kicker">
									<?php
									printf(
										/* translators: 1: step number, 2: total steps. */
										esc_html__( 'Step %1$d of %2$d', 'githubsync' ),
										(int) ( $gs_i + 1 ),
										(int) $gs_total
									);
									?>
								</p>
								<h3 class="gs-slide__title">
									<?php echo esc_html( $gs_step['title'] ); ?>
									<span class="gs-badge gs-badge--<?php echo $gs_is_done ? 'success' : 'warning'; ?>">
										<?php echo $gs_is_done ? esc_html__( 'Done', 'githubsync' ) : esc_html__( 'To do', 'githubsync' ); ?>
									</span>
								</h3>
								<p><?php echo esc_html( $gs_step['body'] ); ?></p>
								<p class="gs-muted gs-slide__state">
									<?php echo esc_html( $gs_is_done ? $gs_step['help'] : $gs_step['missing'] ); ?>
								</p>
								<?php if ( '' !== $gs_page && '' !== $gs_label ) : ?>
									<p>
										<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $gs_page ) ); ?>">
											<?php echo esc_html( $gs_label ); ?>
										</a>
									</p>
								<?php endif; ?>
							</section>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="gs-carousel__status">
					<div class="gs-dots" role="tablist" aria-label="<?php esc_attr_e( 'Jump to step', 'githubsync' ); ?>">
						<?php foreach ( $gs_steps as $gs_i => $gs_step ) : ?>
							<button
								type="button"
								class="gs-dot<?php echo ! empty( $gs_step['done'] ) ? ' gs-dot--done' : ''; ?>"
								data-gs-goto="<?php echo esc_attr( (string) $gs_i ); ?>"
								role="tab"
								aria-selected="<?php echo $gs_i === $gs_current ? 'true' : 'false'; ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: 1: step number, 2: step title. */ __( 'Step %1$d: %2$s', 'githubsync' ), $gs_i + 1, $gs_step['title'] ) ); ?>"
							></button>
						<?php endforeach; ?>
					</div>
					<span class="gs-muted gs-carousel__counter" data-gs-counter aria-live="polite"></span>
				</div>

				<div class="gs-carousel__nav">
					<button type="button" class="button gs-carousel__nav-prev" data-gs-prev aria-label="<?php esc_attr_e( 'Previous step', 'githubsync' ); ?>">
						<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Previous', 'githubsync' ); ?>
					</button>
					<button type="button" class="button gs-carousel__nav-next" data-gs-next aria-label="<?php esc_attr_e( 'Next step', 'githubsync' ); ?>">
						<?php esc_html_e( 'Next', 'githubsync' ); ?> <span aria-hidden="true">&rarr;</span>
					</button>
				</div>

				<p class="gs-muted gs-dialog__cron">
					<?php
					if ( ! empty( $data['cron_disabled'] ) ) {
						esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Deployments are only run when a system cron calls wp-cron.php.', 'githubsync' );
					} elseif ( $gs_cron_ok ) {
						esc_html_e( 'WP-Cron is enabled and the deployment queue tick is scheduled.', 'githubsync' );
					} else {
						esc_html_e( 'The deployment queue tick is not scheduled. It is registered when the plugin is activated; deactivate and reactivate GitHubSync to restore it.', 'githubsync' );
					}
					?>
				</p>

				<div class="gs-dialog__foot">
					<?php if ( $gs_complete ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-dialog__dismiss">
							<?php wp_nonce_field( 'githubsync_dismiss_checklist' ); ?>
							<input type="hidden" name="action" value="githubsync_dismiss_checklist">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Close checklist', 'githubsync' ); ?></button>
						</form>
					<?php else : ?>
						<p class="gs-muted gs-dialog__hint">
							<?php esc_html_e( 'Finish every step to close this checklist.', 'githubsync' ); ?>
						</p>
						<button type="button" class="button" data-gs-dialog-close><?php esc_html_e( 'Hide for now', 'githubsync' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</dialog>
	<?php endif; ?>

	<div class="gs-grid">
		<div class="gs-card gs-card--stat">
			<h2 class="gs-stat__label"><?php esc_html_e( 'GitHub connection', 'githubsync' ); ?></h2>
			<p class="gs-stat__value <?php echo $gs_connected ? 'gs-ok' : 'gs-bad'; ?>">
				<?php echo $gs_connected ? esc_html__( 'Connected', 'githubsync' ) : esc_html__( 'Not connected', 'githubsync' ); ?>
			</p>
			<p class="gs-stat__meta"><?php echo esc_html( $data['connection'] ); ?></p>
			<p class="gs-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-connection' ) ); ?>"><?php esc_html_e( 'Manage connection', 'githubsync' ); ?></a></p>
		</div>

		<div class="gs-card gs-card--stat">
			<h2 class="gs-stat__label"><?php esc_html_e( 'Deployments', 'githubsync' ); ?></h2>
			<p class="gs-stat__value"><?php echo esc_html( (string) count( $data['deployments'] ) ); ?></p>
			<p class="gs-stat__meta">
				<?php
				printf(
					/* translators: 1: active count, 2: auto count. */
					esc_html__( '%1$d active · %2$d with automatic deployment', 'githubsync' ),
					(int) $data['active_count'],
					(int) $data['auto_count']
				);
				?>
			</p>
			<p class="gs-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-new' ) ); ?>"><?php esc_html_e( 'Add deployment', 'githubsync' ); ?></a></p>
		</div>

		<div class="gs-card gs-card--stat">
			<h2 class="gs-stat__label"><?php esc_html_e( 'Queue', 'githubsync' ); ?></h2>
			<p class="gs-stat__value"><?php echo esc_html( (string) $data['queue_count'] ); ?></p>
			<p class="gs-stat__meta"><?php esc_html_e( 'Jobs waiting or retrying', 'githubsync' ); ?></p>
			<?php if ( $data['cron_disabled'] ) : ?>
				<p class="gs-bad"><?php esc_html_e( 'WP-Cron is disabled. Background jobs will not run without a system cron.', 'githubsync' ); ?></p>
			<?php endif; ?>
			<p class="gs-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-tools' ) ); ?>"><?php esc_html_e( 'Diagnostics', 'githubsync' ); ?></a></p>
		</div>

		<div class="gs-card gs-card--stat">
			<h2 class="gs-stat__label"><?php esc_html_e( 'Backup storage', 'githubsync' ); ?></h2>
			<p class="gs-stat__value"><?php echo esc_html( $data['backup_usage'] ); ?></p>
			<p class="gs-stat__meta"><?php esc_html_e( 'Retained rollback backups', 'githubsync' ); ?></p>
		</div>
	</div>

	<?php if ( ! $data['encryption_ok'] ) : ?>
		<div class="notice notice-error inline"><p>
			<?php esc_html_e( 'Secure credential encryption is not configured. GitHubSync will not store credentials until a site key exists.', 'githubsync' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-settings' ) ); ?>"><?php esc_html_e( 'Configure encryption', 'githubsync' ); ?></a>
		</p></div>
	<?php endif; ?>

	<h2 class="gs-section-title"><?php esc_html_e( 'Configured deployments', 'githubsync' ); ?></h2>

	<?php if ( empty( $data['deployments'] ) ) : ?>
		<p class="gs-muted"><?php esc_html_e( 'No deployments yet.', 'githubsync' ); ?></p>
	<?php else : ?>
		<table class="widefat striped gs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Repository', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Branch', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Deployed commit', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Auto', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'githubsync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['deployments'] as $gs_row ) : ?>
					<tr>
						<td data-gs-label="<?php esc_attr_e( 'Name', 'githubsync' ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_row['id'] ) ); ?>">
								<?php echo esc_html( $gs_row['name'] ); ?>
							</a>
						</td>
						<td data-gs-label="<?php esc_attr_e( 'Repository', 'githubsync' ); ?>"><?php echo esc_html( $gs_row['repository_full_name'] ); ?></td>
						<td data-gs-label="<?php esc_attr_e( 'Branch', 'githubsync' ); ?>"><?php echo esc_html( $gs_row['branch'] ); ?></td>
						<td data-gs-label="<?php esc_attr_e( 'Deployed commit', 'githubsync' ); ?>">
							<?php if ( ! empty( $gs_row['last_deployed_sha'] ) ) : ?>
								<code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_row['last_deployed_sha'] ) ); ?></code>
							<?php else : ?>
								<span class="gs-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-gs-label="<?php esc_attr_e( 'Auto', 'githubsync' ); ?>"><?php echo (int) $gs_row['auto_deploy'] ? esc_html__( 'On', 'githubsync' ) : esc_html__( 'Off', 'githubsync' ); ?></td>
						<td data-gs-label="<?php esc_attr_e( 'Status', 'githubsync' ); ?>"><?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 class="gs-section-title"><?php esc_html_e( 'Recent activity', 'githubsync' ); ?></h2>

	<?php if ( empty( $data['recent'] ) ) : ?>
		<p class="gs-muted"><?php esc_html_e( 'No deployments have run yet.', 'githubsync' ); ?></p>
	<?php else : ?>
		<table class="widefat striped gs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Job', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Commit', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Started', 'githubsync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['recent'] as $gs_job ) : ?>
					<tr>
						<td data-gs-label="<?php esc_attr_e( 'Job', 'githubsync' ); ?>">#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
						<td data-gs-label="<?php esc_attr_e( 'Trigger', 'githubsync' ); ?>"><?php echo esc_html( $gs_job['trigger_type'] ); ?></td>
						<td data-gs-label="<?php esc_attr_e( 'Commit', 'githubsync' ); ?>"><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
						<td data-gs-label="<?php esc_attr_e( 'Status', 'githubsync' ); ?>"><?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td data-gs-label="<?php esc_attr_e( 'Started', 'githubsync' ); ?>"><?php echo esc_html( $gs_job['started_at'] ? $gs_job['started_at'] : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $data['failures'] ) ) : ?>
		<h2 class="gs-section-title"><?php esc_html_e( 'Needs attention', 'githubsync' ); ?></h2>
		<ul class="gs-list">
			<?php foreach ( $data['failures'] as $gs_job ) : ?>
				<li>
					<strong>#<?php echo esc_html( (string) $gs_job['id'] ); ?></strong>
					<code><?php echo esc_html( (string) $gs_job['error_code'] ); ?></code>
					<?php echo esc_html( (string) $gs_job['error_message'] ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
