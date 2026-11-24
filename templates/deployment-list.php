<?php
/**
 * Deployment list template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title">
		<?php esc_html_e( 'Deployments', 'githubsync' ); ?>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-new' ) ); ?>"><?php esc_html_e( 'Add Deployment', 'githubsync' ); ?></a>
	</h1>

	<?php if ( empty( $data['deployments'] ) ) : ?>
		<div class="gs-empty">
			<p><?php esc_html_e( 'No deployment configurations yet.', 'githubsync' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-new' ) ); ?>"><?php esc_html_e( 'Create your first deployment', 'githubsync' ); ?></a></p>
		</div>
	<?php else : ?>
		<table class="widefat striped gs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Repository / branch', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Destination', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Last deployment', 'githubsync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'githubsync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['deployments'] as $gs_row ) : ?>
					<?php $gs_last = isset( $gs_row['last_job'] ) ? $gs_row['last_job'] : null; ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_row['id'] ) ); ?>"><?php echo esc_html( $gs_row['name'] ); ?></a></strong>
							<div class="gs-muted">
								<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo (int) $gs_row['auto_deploy'] ? esc_html__( 'Auto-deploy on', 'githubsync' ) : esc_html__( 'Auto-deploy off', 'githubsync' ); ?>
							</div>
						</td>
						<td>
							<code><?php echo esc_html( $gs_row['repository_full_name'] ); ?></code><br>
							<span class="gs-muted"><?php echo esc_html( $gs_row['branch'] ); ?></span>
						</td>
						<td>
							<code class="gs-path"><?php echo esc_html( $gs_row['destination_path'] ); ?></code>
							<div class="gs-muted"><?php echo esc_html( isset( $data['types'][ $gs_row['deployment_type'] ] ) ? $data['types'][ $gs_row['deployment_type'] ] : $gs_row['deployment_type'] ); ?></div>
						</td>
						<td>
							<?php if ( $gs_last ) : ?>
								<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_last['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div class="gs-muted">
									<code><?php echo esc_html( \GitHubSync\Support\Helpers::short_sha( $gs_last['commit_sha'] ) ); ?></code>
									· <?php echo esc_html( $gs_last['started_at'] ? $gs_last['started_at'] : $gs_last['created_at'] ); ?>
								</div>
							<?php else : ?>
								<span class="gs-muted"><?php esc_html_e( 'Never', 'githubsync' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="gs-actions">
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-edit&id=' . (int) $gs_row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'githubsync' ); ?></a>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
								<?php wp_nonce_field( 'githubsync_deploy_now' ); ?>
								<input type="hidden" name="action" value="githubsync_deploy_now">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Deploy now', 'githubsync' ); ?></button>
							</form>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this configuration? Deployed files, backups and history are kept.', 'githubsync' ) ); ?>');">
								<?php wp_nonce_field( 'githubsync_delete_deployment' ); ?>
								<input type="hidden" name="action" value="githubsync_delete_deployment">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
								<input type="hidden" name="confirm" value="1">
								<button type="submit" class="button button-small gs-danger"><?php esc_html_e( 'Delete', 'githubsync' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
