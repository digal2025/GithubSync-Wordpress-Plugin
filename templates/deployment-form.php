<?php
/**
 * Deployment form template.
 *
 * @package GitHubSync
 * @var array $data
 */

defined( 'ABSPATH' ) || exit;

$gs_row    = $data['row'];
$gs_is_new = empty( $gs_row );

$gs_name   = $gs_is_new ? '' : $gs_row['name'];
$gs_repo   = $gs_is_new ? $data['repo_param'] : $gs_row['repository_full_name'];
$gs_branch = $gs_is_new ? '' : $gs_row['branch'];
$gs_source = $gs_is_new ? '' : $gs_row['source_path'];
$gs_type   = $gs_is_new ? 'plugin' : $gs_row['deployment_type'];
$gs_slug   = $gs_is_new ? '' : $gs_row['destination_slug'];
$gs_excl   = $gs_is_new ? '' : $gs_row['exclude_patterns'];
$gs_auto   = $gs_is_new ? 1 : (int) $gs_row['auto_deploy'];
$gs_manual = $gs_is_new ? 1 : (int) $gs_row['manual_deploy'];
$gs_adopt  = $gs_is_new ? 0 : (int) $gs_row['adopt_existing'];
?>
<div class="wrap gs-wrap">
	<h1 class="gs-title"><?php echo $gs_is_new ? esc_html__( 'Add Deployment', 'githubsync' ) : esc_html__( 'Edit Deployment', 'githubsync' ); ?></h1>

	<?php if ( ! $gs_is_new ) : ?>
		<p class="gs-muted">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployment&id=' . (int) $gs_row['id'] ) ); ?>">&larr; <?php esc_html_e( 'Back to deployment', 'githubsync' ); ?></a>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form">
		<?php wp_nonce_field( 'githubsync_save_deployment' ); ?>
		<input type="hidden" name="action" value="githubsync_save_deployment">
		<input type="hidden" name="id" value="<?php echo esc_attr( (string) $data['id'] ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gs-name"><?php esc_html_e( 'Display name', 'githubsync' ); ?></label></th>
				<td>
					<input type="text" id="gs-name" name="name" class="regular-text" required value="<?php echo esc_attr( $gs_name ); ?>">
					<p class="description"><?php esc_html_e( 'Shown in the dashboard and history.', 'githubsync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="gs-repo"><?php esc_html_e( 'Repository', 'githubsync' ); ?></label></th>
				<td>
					<input type="text" id="gs-repo" name="repository" class="regular-text" required placeholder="owner/repository" value="<?php echo esc_attr( $gs_repo ); ?>">
					<p class="description"><?php esc_html_e( 'The repository is verified against the GitHub API when you save.', 'githubsync' ); ?></p>

					<?php if ( '' !== $data['branch_error'] ) : ?>
						<p class="gs-bad"><?php echo esc_html( $data['branch_error'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $data['branches'] ) ) : ?>
						<datalist id="gs-branches">
							<?php foreach ( $data['branches'] as $gs_b ) : ?>
								<option value="<?php echo esc_attr( $gs_b['name'] ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description">
							<?php
							printf(
								/* translators: %d: branch count. */
								esc_html__( '%d branches loaded. Start typing to pick one.', 'githubsync' ),
								count( $data['branches'] )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="gs-branch"><?php esc_html_e( 'Branch', 'githubsync' ); ?></label></th>
				<td>
					<input type="text" id="gs-branch" name="branch" class="regular-text" required list="gs-branches" value="<?php echo esc_attr( $gs_branch ); ?>">
					<p class="description"><?php esc_html_e( 'Pushes to exactly this branch trigger deployment. Never assume main.', 'githubsync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="gs-source"><?php esc_html_e( 'Source path', 'githubsync' ); ?></label></th>
				<td>
					<input type="text" id="gs-source" name="source_path" class="regular-text" placeholder="wordpress/my-theme" value="<?php echo esc_attr( $gs_source ); ?>">
					<p class="description"><?php esc_html_e( 'Optional subdirectory inside the repository. Leave empty for the repository root.', 'githubsync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="gs-type"><?php esc_html_e( 'Deployment type', 'githubsync' ); ?></label></th>
				<td>
					<select id="gs-type" name="deployment_type">
						<?php foreach ( $data['types'] as $gs_key => $gs_label ) : ?>
							<option value="<?php echo esc_attr( $gs_key ); ?>" <?php selected( $gs_type, $gs_key ); ?>><?php echo esc_html( $gs_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="gs-slug"><?php esc_html_e( 'Destination folder', 'githubsync' ); ?></label></th>
				<td>
					<input type="text" id="gs-slug" name="destination_slug" class="regular-text" required placeholder="my-theme" value="<?php echo esc_attr( $gs_slug ); ?>">
					<p class="description">
						<?php
						printf(
							/* translators: %s: example path. */
							esc_html__( 'A folder name inside the approved root, for example %s. Traversal, absolute paths and the GitHubSync plugin folder are rejected.', 'githubsync' ),
							'<code>' . esc_html( 'wp-content/plugins/my-plugin' ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="gs-excl"><?php esc_html_e( 'Exclude patterns', 'githubsync' ); ?></label></th>
				<td>
					<textarea id="gs-excl" name="exclude_patterns" rows="6" class="large-text code" placeholder="docs/**&#10;*.md"><?php echo esc_textarea( $gs_excl ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One glob per line. * matches within a folder, ** matches across folders. Protected files (.git, .env, keys) are always excluded and cannot be overridden.', 'githubsync' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Behaviour', 'githubsync' ); ?></th>
				<td>
					<label><input type="checkbox" name="auto_deploy" value="1" <?php checked( $gs_auto, 1 ); ?>> <?php esc_html_e( 'Deploy automatically on push to this branch', 'githubsync' ); ?></label><br>
					<label><input type="checkbox" name="manual_deploy" value="1" <?php checked( $gs_manual, 1 ); ?>> <?php esc_html_e( 'Allow manual deployment from the dashboard', 'githubsync' ); ?></label><br>
					<label><input type="checkbox" name="adopt_existing" value="1" <?php checked( $gs_adopt, 1 ); ?>> <?php esc_html_e( 'Adopt an existing destination directory (required if it already contains files)', 'githubsync' ); ?></label>
					<p class="description gs-warn"><?php esc_html_e( 'Deployment replaces files in the destination. Files excluded by policy are preserved. There is no database migration.', 'githubsync' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo $gs_is_new ? esc_html__( 'Save and continue', 'githubsync' ) : esc_html__( 'Save changes', 'githubsync' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployments' ) ); ?>"><?php esc_html_e( 'Cancel', 'githubsync' ); ?></a>
		</p>
	</form>
</div>
