<?php
/**
 * Tools template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title"><?php esc_html_e( 'Tools & Diagnostics', 'githubsync' ); ?></h1>

	<div class="gs-table-wrap">
	<table class="widefat striped gs-table gs-checks">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Check', 'githubsync' ); ?></th>
				<th><?php esc_html_e( 'Value', 'githubsync' ); ?></th>
				<th><?php esc_html_e( 'Status', 'githubsync' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'githubsync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['checks'] as $gs_check ) : ?>
				<tr>
					<td><?php echo esc_html( $gs_check['label'] ); ?></td>
					<td><code class="gs-path-soft gs-check-value"><?php echo esc_html( (string) $gs_check['value'] ); ?></code></td>
					<td>
						<span class="gs-badge gs-badge--<?php echo $gs_check['ok'] ? 'success' : 'error'; ?>">
							<?php echo $gs_check['ok'] ? esc_html__( 'OK', 'githubsync' ) : esc_html__( 'Attention', 'githubsync' ); ?>
						</span>
					</td>
					<td class="gs-muted"><?php echo esc_html( $gs_check['help'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<div class="gs-columns">
		<div class="gs-card gs-card--side">
			<h2><?php esc_html_e( 'Storage', 'githubsync' ); ?></h2>
			<ul class="gs-facts">
				<li><span><?php esc_html_e( 'Backups', 'githubsync' ); ?></span> <strong><?php echo esc_html( $data['backup_usage'] ); ?></strong></li>
				<li><span><?php esc_html_e( 'Log entries', 'githubsync' ); ?></span> <strong><?php echo esc_html( (string) $data['log_count'] ); ?></strong></li>
			</ul>
			<p class="gs-muted gs-break"><?php echo esc_html( $data['work_dir'] ); ?></p>
		</div>

		<div class="gs-card">
			<h2><?php esc_html_e( 'Maintenance', 'githubsync' ); ?></h2>

			<div class="gs-actionbar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
					<?php wp_nonce_field( 'githubsync_run_queue' ); ?>
					<input type="hidden" name="action" value="githubsync_run_queue">
					<button type="submit" class="button"><?php esc_html_e( 'Run queue now', 'githubsync' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
					<?php wp_nonce_field( 'githubsync_prune_backups' ); ?>
					<input type="hidden" name="action" value="githubsync_prune_backups">
					<button type="submit" class="button"><?php esc_html_e( 'Prune old backups', 'githubsync' ); ?></button>
				</form>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="gs-inline"
					data-gs-confirm="<?php esc_attr_e( 'Delete all deployment logs?', 'githubsync' ); ?>"
					data-gs-confirm-text="<?php esc_attr_e( 'Every log entry is removed permanently. Deployment configurations, backups and deployment history are not affected.', 'githubsync' ); ?>"
					data-gs-confirm-ok="<?php esc_attr_e( 'Clear logs', 'githubsync' ); ?>"
					data-gs-confirm-danger="1"
				>
					<?php wp_nonce_field( 'githubsync_clear_logs' ); ?>
					<input type="hidden" name="action" value="githubsync_clear_logs">
					<button type="submit" class="button gs-danger"><?php esc_html_e( 'Clear logs', 'githubsync' ); ?></button>
				</form>
			</div>

			<p class="gs-muted"><?php esc_html_e( 'If WP-Cron is disabled, add a system cron entry that requests wp-cron.php every five minutes.', 'githubsync' ); ?></p>
			<pre class="gs-code">*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron &gt;/dev/null 2&gt;&amp;1</pre>
		</div>
	</div>
</div>
