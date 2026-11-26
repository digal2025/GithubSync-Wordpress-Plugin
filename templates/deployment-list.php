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
	<div class="gs-page-head">
		<div class="gs-page-head__text">
			<span class="gs-page-head__icon" aria-hidden="true"><?php \GitHubSync\Admin\AdminMenu::icon( 'rocket' ); ?></span>
			<div>
				<h1 class="gs-title"><?php esc_html_e( 'Deployments', 'githubsync' ); ?></h1>
				<p class="gs-subtitle"><?php esc_html_e( 'Each deployment links one repository branch to one folder on this site.', 'githubsync' ); ?></p>
			</div>
		</div>
		<div class="gs-page-head__actions">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-new' ) ); ?>">
				<?php \GitHubSync\Admin\AdminMenu::icon( 'plus' ); ?>
				<?php esc_html_e( 'Add Deployment', 'githubsync' ); ?>
			</a>
		</div>
	</div>

	<?php if ( empty( $data['deployments'] ) ) : ?>
		<?php
		\GitHubSync\Admin\AdminMenu::empty_state(
			__( 'No deployments yet', 'githubsync' ),
			__( 'A deployment links a GitHub repository branch to a folder on this site. Create one to start syncing files.', 'githubsync' ),
			'<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=githubsync-deployment-new' ) ) . '">' . esc_html__( 'Create your first deployment', 'githubsync' ) . '</a>',
			'rocket'
		);
		?>
	<?php else : ?>
		<div class="gs-table-wrap">
			<table class="gs-table gs-deployments">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Repository / branch', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Destination', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Size', 'githubsync' ); ?></th>
						<th><?php esc_html_e( 'Last deployment', 'githubsync' ); ?></th>
						<th class="gs-actions"><?php esc_html_e( 'Actions', 'githubsync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['deployments'] as $gs_row ) : ?>
						<?php $gs_last = isset( $gs_row['last_job'] ) ? $gs_row['last_job'] : null; ?>
						<tr>
							<td data-gs-label="<?php esc_attr_e( 'Name', 'githubsync' ); ?>">
								<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_row['id'] ) ); ?>"><?php echo esc_html( $gs_row['name'] ); ?></a></strong>
								<div class="gs-muted gs-deployments__meta">
									<?php echo \GitHubSync\Admin\AdminMenu::badge( $gs_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php echo (int) $gs_row['auto_deploy'] ? esc_html__( 'Auto-deploy on', 'githubsync' ) : esc_html__( 'Auto-deploy off', 'githubsync' ); ?></span>
								</div>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Repository / branch', 'githubsync' ); ?>">
								<code class="gs-path"><?php echo esc_html( $gs_row['repository_full_name'] ); ?></code>
								<div class="gs-muted"><?php echo esc_html( $gs_row['branch'] ); ?></div>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Destination', 'githubsync' ); ?>">
								<code class="gs-path-soft"><?php echo esc_html( \GitHubSync\Support\Helpers::relative_path( $gs_row['destination_path'] ) ); ?></code>
								<div class="gs-muted"><?php echo esc_html( isset( $data['types'][ $gs_row['deployment_type'] ] ) ? $data['types'][ $gs_row['deployment_type'] ] : $gs_row['deployment_type'] ); ?></div>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Size', 'githubsync' ); ?>">
								<?php if ( ! empty( $gs_row['has_files'] ) ) : ?>
									<strong><?php echo esc_html( \GitHubSync\Support\Helpers::human_bytes( (int) $gs_row['size'] ) ); ?></strong>
									<div class="gs-muted">
										<?php
										printf(
											/* translators: %s: number of files. */
											esc_html( _n( '%s file', '%s files', (int) $gs_row['file_count'], 'githubsync' ) ),
											esc_html( (string) (int) $gs_row['file_count'] )
										);
										?>
									</div>
								<?php else : ?>
									<span class="gs-muted"><?php esc_html_e( 'Not deployed yet', 'githubsync' ); ?></span>
								<?php endif; ?>
								<?php if ( (int) $gs_row['backup_bytes'] > 0 ) : ?>
									<div class="gs-muted">
										<?php
										printf(
											/* translators: %s: backup disk usage. */
											esc_html__( 'Backups: %s', 'githubsync' ),
											esc_html( \GitHubSync\Support\Helpers::human_bytes( (int) $gs_row['backup_bytes'] ) )
										);
										?>
									</div>
								<?php endif; ?>
							</td>
							<td data-gs-label="<?php esc_attr_e( 'Last deployment', 'githubsync' ); ?>">
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
							<td class="gs-actions" data-gs-label="<?php esc_attr_e( 'Actions', 'githubsync' ); ?>">
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment-edit&id=' . (int) $gs_row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'githubsync' ); ?></a>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-inline">
									<?php wp_nonce_field( 'githubsync_deploy_now' ); ?>
									<input type="hidden" name="action" value="githubsync_deploy_now">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $gs_row['id'] ); ?>">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Deploy now', 'githubsync' ); ?></button>
								</form>

								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									class="gs-inline"
									data-gs-confirm="<?php echo esc_attr( sprintf( /* translators: %s: deployment name. */ __( 'Delete the “%s” configuration?', 'githubsync' ), $gs_row['name'] ) ); ?>"
									data-gs-confirm-text="<?php esc_attr_e( 'Only the configuration is removed. Files already deployed, their backups and the deployment history are kept.', 'githubsync' ); ?>"
									data-gs-confirm-ok="<?php esc_attr_e( 'Delete configuration', 'githubsync' ); ?>"
									data-gs-confirm-danger="1"
								>
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
		</div>
	<?php endif; ?>
</div>
