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
	$gs_steps   = isset( $data['steps'] ) ? (array) $data['steps'] : array();
	$gs_done    = 0;
	foreach ( $gs_steps as $gs_step ) {
		if ( ! empty( $gs_step['done'] ) ) {
			$gs_done++;
		}
	}
	$gs_total   = count( $gs_steps );
	$gs_complete = $gs_total > 0 && $gs_done === $gs_total;
	$gs_cron_ok = empty( $data['cron_disabled'] ) && ! empty( $data['cron_scheduled'] );
	?>

	<div class="gs-card gs-card--wide gs-card--<?php echo $gs_complete ? 'ok' : 'bad'; ?>">
		<h2><?php esc_html_e( 'Setup checklist', 'githubsync' ); ?></h2>

		<p class="gs-muted">
			<?php
			printf(
				/* translators: 1: completed steps, 2: total steps. */
				esc_html__( '%1$d of %2$d steps complete. Work through them in order; each step links to the page where it is done.', 'githubsync' ),
				(int) $gs_done,
				(int) $gs_total
			);
			?>
		</p>

		<ol class="gs-steps">
			<?php foreach ( $gs_steps as $gs_step ) : ?>
				<?php
				$gs_is_done = ! empty( $gs_step['done'] );
				$gs_page    = isset( $gs_step['action'][1] ) ? (string) $gs_step['action'][1] : '';
				$gs_label   = isset( $gs_step['action'][0] ) ? (string) $gs_step['action'][0] : '';
				?>
				<li class="gs-step<?php echo $gs_is_done ? ' gs-step--done' : ''; ?>">
					<p class="gs-step__head">
						<strong><?php echo esc_html( $gs_step['title'] ); ?></strong>
						<span class="gs-badge gs-badge--<?php echo $gs_is_done ? 'success' : 'warning'; ?>">
							<?php echo $gs_is_done ? esc_html__( 'Done', 'githubsync' ) : esc_html__( 'To do', 'githubsync' ); ?>
						</span>
					</p>
					<p><?php echo esc_html( $gs_step['body'] ); ?></p>
					<p class="gs-muted gs-step__state">
						<?php echo esc_html( $gs_is_done ? $gs_step['help'] : $gs_step['missing'] ); ?>
					</p>
					<?php if ( '' !== $gs_page && '' !== $gs_label ) : ?>
						<p>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $gs_page ) ); ?>">
								<?php echo esc_html( $gs_label ); ?>
							</a>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<p class="gs-muted">
			<?php
			if ( ! empty( $data['cron_disabled'] ) ) {
				esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Deployments are only run when a system cron calls wp-cron.php.', 'githubsync' );
			} elseif ( $gs_cron_ok ) {
				esc_html_e( 'WP-Cron is enabled and the deployment queue tick is scheduled.', 'githubsync' );
			} elseif ( $gs_total > 0 ) {
				esc_html_e( 'The deployment queue tick is not scheduled. It is registered when the plugin is activated; deactivate and reactivate GitHubSync to restore it.', 'githubsync' );
			}
			?>
		</p>
	</div>

	<div class="gs-grid">
		<div class="gs-card">
			<h2><?php esc_html_e( 'GitHub connection', 'githubsync' ); ?></h2>
			<p class="gs-big <?php echo $gs_connected ? 'gs-ok' : 'gs-bad'; ?>">
				<?php echo $gs_connected ? esc_html__( 'Connected', 'githubsync' ) : esc_html__( 'Not connected', 'githubsync' ); ?>
			</p>
			<p class="gs-muted"><?php echo esc_html( $data['connection'] ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-connection' ) ); ?>"><?php esc_html_e( 'Manage connection', 'githubsync' ); ?></a></p>
		</div>

		<div class="gs-card">
			<h2><?php esc_html_e( 'Deployments', 'githubsync' ); ?></h2>
			<p class="gs-big"><?php echo esc_html( (string) count( $data['deployments'] ) ); ?></p>
			<p class="gs-muted">
				<?php
				printf(
					/* translators: 1: active count, 2: auto count. */
					esc_html__( '%1$d active · %2$d with automatic deployment', 'githubsync' ),
					(int) $data['active_count'],
					(int) $data['auto_count']
				);
				?>
			</p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-new' ) ); ?>"><?php esc_html_e( 'Add deployment', 'githubsync' ); ?></a></p>
		</div>

		<div class="gs-card">
			<h2><?php esc_html_e( 'Queue', 'githubsync' ); ?></h2>
			<p class="gs-big"><?php echo esc_html( (string) $data['queue_count'] ); ?></p>
			<p class="gs-muted"><?php esc_html_e( 'Jobs waiting or retrying', 'githubsync' ); ?></p>
			<?php if ( $data['cron_disabled'] ) : ?>
				<p class="gs-bad"><?php esc_html_e( 'WP-Cron is disabled. Background jobs will not run without a system cron.', 'githubsync' ); ?></p>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-tools' ) ); ?>"><?php esc_html_e( 'Diagnostics', 'githubsync' ); ?></a></p>
		</div>

		<div class="gs-card">
			<h2><?php esc_html_e( 'Backup storage', 'githubsync' ); ?></h2>
			<p class="gs-big"><?php echo esc_html( $data['backup_usage'] ); ?></p>
			<p class="gs-muted"><?php esc_html_e( 'Retained rollback backups', 'githubsync' ); ?></p>
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
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_row['id'] ) ); ?>">
								<?php echo esc_html( $gs_row['name'] ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $gs_row['repository_full_name'] ); ?></td>
						<td><?php echo esc_html( $gs_row['branch'] ); ?></td>
						<td>
							<?php if ( ! empty( $gs_row['last_deployed_sha'] ) ) : ?>
								<code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_row['last_deployed_sha'] ) ); ?></code>
							<?php else : ?>
								<span class="gs-muted">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $gs_row['auto_deploy'] ? esc_html__( 'On', 'githubsync' ) : esc_html__( 'Off', 'githubsync' ); ?></td>
						<td><?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
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
						<td>#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
						<td><?php echo esc_html( $gs_job['trigger_type'] ); ?></td>
						<td><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
						<td><?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( $gs_job['started_at'] ? $gs_job['started_at'] : '—' ); ?></td>
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
