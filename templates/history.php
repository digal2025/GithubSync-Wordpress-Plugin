<?php
/**
 * History template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;

$gs_jobs     = isset( $data['jobs'] ) ? (array) $data['jobs'] : array();
$gs_ok_count = 0;
$gs_failures = 0;
$gs_active   = 0;

foreach ( $gs_jobs as $gs_job_row ) {
	if ( 'succeeded' === $gs_job_row['status'] ) {
		$gs_ok_count++;
	} elseif ( in_array( $gs_job_row['status'], array( 'failed', 'cancelled' ), true ) ) {
		$gs_failures++;
	} else {
		$gs_active++;
	}
}
?>
<div class="wrap gs-wrap">
	<?php
	\GitHubSync\Admin\AdminMenu::page_head(
		__( 'Deployment History', 'githubsync' ),
		__( 'Every deployment attempt, whether triggered by a push or run by hand.', 'githubsync' ),
		'',
		'history'
	);
	?>

	<?php if ( ! empty( $data['history_count'] ) ) : ?>
		<div class="gs-card">
			<div class="gs-actionbar">
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="gs-inline"
					data-gs-confirm="<?php esc_attr_e( 'Clear deployment history?', 'githubsync' ); ?>"
					data-gs-confirm-text="<?php esc_attr_e( 'All finished, failed and cancelled jobs will be removed from this site, along with their log entries. Active jobs, deployed files and backups are kept.', 'githubsync' ); ?>"
					data-gs-confirm-ok="<?php esc_attr_e( 'Clear history', 'githubsync' ); ?>"
					data-gs-confirm-danger="1"
				>
					<?php wp_nonce_field( 'githubsync_clear_history' ); ?>
					<input type="hidden" name="action" value="githubsync_clear_history">
					<button type="submit" class="button gs-danger"><?php \GitHubSync\Admin\AdminMenu::icon( 'history' ); ?> <?php esc_html_e( 'Clear history', 'githubsync' ); ?></button>
				</form>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( empty( $gs_jobs ) ) : ?>
		<?php
		\GitHubSync\Admin\AdminMenu::empty_state(
			__( 'No deployment jobs recorded', 'githubsync' ),
			__( 'Every deployment attempt, whether triggered by a push or run manually, is listed here once it starts.', 'githubsync' ),
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=githubsync-deployments' ) ) . '">' . esc_html__( 'Go to deployments', 'githubsync' ) . '</a>',
			'history'
		);
		?>
	<?php else : ?>
		<div class="gs-mini-stats">
			<span class="gs-mini-stat"><strong><?php echo esc_html( (string) count( $gs_jobs ) ); ?></strong> <?php esc_html_e( 'jobs', 'githubsync' ); ?></span>
			<span class="gs-mini-stat"><strong class="gs-ok"><?php echo esc_html( (string) $gs_ok_count ); ?></strong> <?php esc_html_e( 'succeeded', 'githubsync' ); ?></span>
			<span class="gs-mini-stat"><strong class="<?php echo $gs_failures > 0 ? 'gs-bad' : 'gs-muted'; ?>"><?php echo esc_html( (string) $gs_failures ); ?></strong> <?php esc_html_e( 'failed', 'githubsync' ); ?></span>
			<span class="gs-mini-stat"><strong><?php echo esc_html( (string) $gs_active ); ?></strong> <?php esc_html_e( 'in progress', 'githubsync' ); ?></span>
		</div>

		<div class="gs-table-wrap">
			<table class="gs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Deployment', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Trigger', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Commit', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Status', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Changes', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Queued', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'githubsync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $gs_jobs as $gs_job ) : ?>
						<?php
						$gs_start    = \GitHubSync\Support\Helpers::to_timestamp( $gs_job['started_at'] );
						$gs_end      = \GitHubSync\Support\Helpers::to_timestamp( $gs_job['finished_at'] );
						$gs_duration = ( $gs_start && $gs_end && $gs_end >= $gs_start ) ? ( $gs_end - $gs_start ) . 's' : '—';
						$gs_name     = isset( $data['names'][ (int) $gs_job['deployment_id'] ] ) ? $data['names'][ (int) $gs_job['deployment_id'] ] : '#' . (int) $gs_job['deployment_id'];
						?>
						<tr>
							<td data-gs-label="<?php esc_attr_e( 'Job', 'githubsync' ); ?>">#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Deployment', 'githubsync' ); ?>">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_job['deployment_id'] ) ); ?>">
									<?php echo esc_html( $gs_name ); ?>
								</a>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Trigger', 'githubsync' ); ?>"><?php echo esc_html( $gs_job['trigger_type'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Commit', 'githubsync' ); ?>"><code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_job['commit_sha'] ) ); ?></code></td>
							<td data-gs-label="<?php esc_attr_e( 'Status', 'githubsync' ); ?>">
								<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( ! empty( $gs_job['error_code'] ) ) : ?>
									<div class="gs-muted"><code><?php echo esc_html( $gs_job['error_code'] ); ?></code> <?php echo esc_html( (string) $gs_job['error_message'] ); ?></div>
								<?php endif; ?>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Changes', 'githubsync' ); ?>" class="gs-muted">
								+<?php echo esc_html( (string) (int) $gs_job['files_added'] ); ?>
								~<?php echo esc_html( (string) (int) $gs_job['files_updated'] ); ?>
								−<?php echo esc_html( (string) (int) $gs_job['files_removed'] ); ?>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Queued', 'githubsync' ); ?>" class="gs-muted"><?php echo esc_html( $gs_job['created_at'] ); ?></td>
							<td data-gs-label="<?php esc_attr_e( 'Duration', 'githubsync' ); ?>"><?php echo esc_html( $gs_duration ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
