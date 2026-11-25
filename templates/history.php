<?php
/**
 * History template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title"><?php esc_html_e( 'Deployment History', 'githubsync' ); ?></h1>

	<?php if ( empty( $data['jobs'] ) ) : ?>
		<?php
		\GitHubSync\Admin\AdminMenu::empty_state(
			__( 'No deployment jobs recorded', 'githubsync' ),
			__( 'Every deployment attempt, whether triggered by a push or run manually, is listed here once it starts.', 'githubsync' ),
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=githubsync-deployments' ) ) . '">' . esc_html__( 'Go to deployments', 'githubsync' ) . '</a>'
		);
		?>
	<?php else : ?>
		<div class="gs-table-wrap">
		<table class="widefat striped gs-table">
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
				<?php foreach ( $data['jobs'] as $gs_job ) : ?>
					<?php
					$gs_start    = \GitHubSync\Support\Helpers::to_timestamp( $gs_job['started_at'] );
					$gs_end      = \GitHubSync\Support\Helpers::to_timestamp( $gs_job['finished_at'] );
					$gs_duration = ( $gs_start && $gs_end && $gs_end >= $gs_start ) ? ( $gs_end - $gs_start ) . 's' : '—';
					$gs_name     = isset( $data['names'][ (int) $gs_job['deployment_id'] ] ) ? $data['names'][ (int) $gs_job['deployment_id'] ] : '#' . (int) $gs_job['deployment_id'];
					?>
					<tr>
						<td>#<?php echo esc_html( (string) $gs_job['id'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_job['deployment_id'] ) ); ?>">
								<?php echo esc_html( $gs_name ); ?>
							</a>
						</td>
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
						<td class="gs-muted"><?php echo esc_html( $gs_job['created_at'] ); ?></td>
						<td><?php echo esc_html( $gs_duration ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</div>
