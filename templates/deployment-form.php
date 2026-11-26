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

$gs_repo_error = isset( $data['repo_error'] ) ? (string) $data['repo_error'] : '';

$gs_meta    = isset( $data['repo_meta'] ) && is_array( $data['repo_meta'] ) ? $data['repo_meta'] : null;
$gs_suggest = array(
	'fields'        => array(),
	'slug_deployed' => false,
);

if ( '' !== $gs_repo && ( null !== $gs_meta || ! empty( $data['branches'] ) ) ) {
	$gs_suggest = \GitHubSync\GitHub\RepositoryPrefill::suggest(
		$gs_meta,
		isset( $data['branches'] ) ? $data['branches'] : array(),
		array(
			'branch'           => $gs_branch,
			'source_path'      => $gs_source,
			'deployment_type'  => $gs_type,
			'destination_slug' => $gs_slug,
		),
		$gs_is_new ? 0 : (int) $gs_row['id']
	);
}

if ( $gs_is_new && isset( $gs_suggest['fields']['branch'] ) ) {
	$gs_branch = $gs_suggest['fields']['branch']['value'];
}
if ( $gs_is_new && isset( $gs_suggest['fields']['source_path'] ) ) {
	$gs_source = $gs_suggest['fields']['source_path']['value'];
}
if ( $gs_is_new && isset( $gs_suggest['fields']['destination_slug'] ) ) {
	$gs_slug = $gs_suggest['fields']['destination_slug']['value'];
}

/**
 * Reason a prefilled field carries the value it does.
 *
 * @param array  $suggest Result of RepositoryPrefill::suggest().
 * @param string $field   Field key.
 * @return string
 */
$gs_reason = static function ( array $suggest, $field ) {
	return isset( $suggest['fields'][ $field ]['reason'] ) ? (string) $suggest['fields'][ $field ]['reason'] : '';
};
?>
<div class="wrap gs-wrap">
	<?php if ( ! $gs_is_new ) : ?>
		<?php \GitHubSync\Admin\AdminMenu::back_link( 'githubsync-deployment', __( 'Back to deployment', 'githubsync' ), array( 'id' => (int) $gs_row['id'] ) ); ?>
	<?php endif; ?>

	<div class="gs-page-head">
		<div class="gs-page-head__text">
			<span class="gs-page-head__icon" aria-hidden="true"><?php \GitHubSync\Admin\AdminMenu::icon( $gs_is_new ? 'plus' : 'settings' ); ?></span>
			<div>
				<h1 class="gs-title"><?php echo $gs_is_new ? esc_html__( 'Add Deployment', 'githubsync' ) : esc_html__( 'Edit Deployment', 'githubsync' ); ?></h1>
				<p class="gs-subtitle">
					<?php
					echo $gs_is_new
						? esc_html__( 'Link a repository branch to a folder on this site. GitHubSync checks every value before the first deploy.', 'githubsync' )
						: esc_html__( 'Change where this deployment reads from and writes to. Saving does not deploy anything by itself.', 'githubsync' );
					?>
				</p>
			</div>
		</div>
	</div>

	<div class="gs-layout">
		<div class="gs-layout__main">
			<?php if ( $gs_is_new && '' !== $gs_repo ) : ?>
				<?php if ( '' !== $gs_repo_error ) : ?>
					<?php
					\GitHubSync\Admin\AdminMenu::notice(
						'warning',
						sprintf(
							/* translators: 1: repository full name, 2: GitHub error message. */
							esc_html__( 'Could not read %1$s from GitHub, so nothing was prefilled: %2$s', 'githubsync' ),
							'<code>' . esc_html( $gs_repo ) . '</code>',
							esc_html( $gs_repo_error )
						)
					);
					?>
				<?php elseif ( null !== $gs_meta ) : ?>
					<?php
					\GitHubSync\Admin\AdminMenu::notice(
						'info',
						sprintf(
							/* translators: 1: repository full name, 2: visibility (private or public). */
							esc_html__( 'Prefilled from GitHub: %1$s (%2$s). Check every value before saving; you can overwrite any of them.', 'githubsync' ),
							'<code>' . esc_html( (string) $gs_meta['full_name'] ) . '</code>',
							esc_html( ! empty( $gs_meta['private'] ) ? esc_html__( 'private', 'githubsync' ) : esc_html__( 'public', 'githubsync' ) )
						)
					);
					?>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-form">
				<?php wp_nonce_field( 'githubsync_save_deployment' ); ?>
				<input type="hidden" name="action" value="githubsync_save_deployment">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $data['id'] ); ?>">

				<section class="gs-panel">
					<div class="gs-panel__head">
						<span class="gs-panel__step">1</span>
						<div>
							<h2 class="gs-panel__title"><?php esc_html_e( 'Source', 'githubsync' ); ?></h2>
							<p class="gs-panel__hint"><?php esc_html_e( 'Where the code lives on GitHub.', 'githubsync' ); ?></p>
						</div>
					</div>
					<div class="gs-panel__body">
						<div class="gs-field">
							<label for="gs-name"><?php esc_html_e( 'Display name', 'githubsync' ); ?></label>
							<input type="text" id="gs-name" name="name" class="regular-text" required placeholder="My plugin" value="<?php echo esc_attr( $gs_name ); ?>">
							<p class="description"><?php esc_html_e( 'Any label you recognise: shown in the dashboard, history and deployment list. Use the project name, for example "My plugin".', 'githubsync' ); ?></p>
						</div>

						<div class="gs-field">
							<label for="gs-repo"><?php esc_html_e( 'Repository', 'githubsync' ); ?></label>
							<input type="text" id="gs-repo" name="repository" class="regular-text" required placeholder="owner/repository" value="<?php echo esc_attr( $gs_repo ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: example repository. */
									esc_html__( 'Two parts separated by a slash and nothing else: the account or organization, then the repository name. Example: %s. The repository is checked against the GitHub API when you save, so it must exist and the connected account must be able to read it.', 'githubsync' ),
									'<code>' . esc_html( 'digal2025/GithubSync-Wordpress-Plugin' ) . '</code>'
								);
								?>
							</p>

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
						</div>

						<div class="gs-field">
							<label for="gs-branch"><?php esc_html_e( 'Branch', 'githubsync' ); ?></label>
							<input type="text" id="gs-branch" name="branch" class="regular-text" required list="gs-branches" value="<?php echo esc_attr( $gs_branch ); ?>">
							<p class="description"><?php esc_html_e( 'The exact branch name, case-sensitive, without "origin/" or "refs/heads/". Usually main or master — open the repository on GitHub and read it from the branch switcher rather than guessing. Only pushes to this branch trigger a deployment.', 'githubsync' ); ?></p>
							<?php if ( '' !== $gs_reason( $gs_suggest, 'branch' ) ) : ?>
								<p class="description gs-source-hint"><?php echo esc_html( $gs_reason( $gs_suggest, 'branch' ) ); ?></p>
							<?php endif; ?>
						</div>

						<div class="gs-field">
							<label for="gs-source"><?php esc_html_e( 'Source path', 'githubsync' ); ?></label>
							<input type="text" id="gs-source" name="source_path" class="regular-text" placeholder="wordpress/my-theme" value="<?php echo esc_attr( $gs_source ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Leave empty when the plugin or theme files sit at the top of the repository. If the repository holds several projects, enter the subfolder that contains the code, relative to the repository root — for example wordpress/my-theme — with no leading or trailing slash.', 'githubsync' ); ?></p>
							<?php if ( '' !== $gs_reason( $gs_suggest, 'source_path' ) ) : ?>
								<p class="description gs-source-hint"><?php echo esc_html( $gs_reason( $gs_suggest, 'source_path' ) ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<section class="gs-panel">
					<div class="gs-panel__head">
						<span class="gs-panel__step">2</span>
						<div>
							<h2 class="gs-panel__title"><?php esc_html_e( 'Destination', 'githubsync' ); ?></h2>
							<p class="gs-panel__hint"><?php esc_html_e( 'Where the files land on this site.', 'githubsync' ); ?></p>
						</div>
					</div>
					<div class="gs-panel__body">
						<div class="gs-field">
							<label for="gs-type"><?php esc_html_e( 'Deployment type', 'githubsync' ); ?></label>
							<select id="gs-type" name="deployment_type">
								<?php foreach ( $data['types'] as $gs_key => $gs_label ) : ?>
									<option value="<?php echo esc_attr( $gs_key ); ?>" <?php selected( $gs_type, $gs_key ); ?>><?php echo esc_html( $gs_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Chooses which approved root the files are written into:', 'githubsync' ); ?>
								<strong><?php esc_html_e( 'WordPress plugin', 'githubsync' ); ?></strong> &rarr; <code>wp-content/plugins</code>,
								<strong><?php esc_html_e( 'WordPress theme', 'githubsync' ); ?></strong> &rarr; <code>wp-content/themes</code>,
								<strong><?php esc_html_e( 'Static site', 'githubsync' ); ?></strong> &rarr; <code>wp-content/uploads/githubsync-sites</code>.
								<?php esc_html_e( 'GitHubSync never writes outside these roots.', 'githubsync' ); ?>
							</p>
						</div>

						<div class="gs-field">
							<label for="gs-slug"><?php esc_html_e( 'Destination folder', 'githubsync' ); ?></label>
							<input type="text" id="gs-slug" name="destination_slug" class="regular-text" required placeholder="my-plugin" value="<?php echo esc_attr( $gs_slug ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: example path. */
									esc_html__( 'Just the folder name, not a path: letters, numbers, dots, dashes and underscores only, for example %s. Do not include wp-content or slashes — the deployment type above already chooses that root. Traversal, absolute paths and the GitHubSync plugin folder are rejected.', 'githubsync' ),
									'<code>' . esc_html( 'my-plugin' ) . '</code>'
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Usually the same name as the folder in your repository. If that folder already exists on this site, tick "Adopt an existing destination directory" below.', 'githubsync' ); ?>
							</p>
							<?php if ( '' !== $gs_reason( $gs_suggest, 'destination_slug' ) ) : ?>
								<p class="description gs-source-hint">
									<?php echo esc_html( $gs_reason( $gs_suggest, 'destination_slug' ) ); ?>
									<?php if ( ! empty( $gs_suggest['slug_deployed'] ) ) : ?>
										<?php
										printf(
											/* translators: %s: destination folder name. */
											esc_html__( ' Another deployment on this site already writes to %s. Two deployments sharing one folder overwrite each other.', 'githubsync' ),
											'<code>' . esc_html( $gs_slug ) . '</code>'
										);
										?>
									<?php endif; ?>
								</p>
							<?php endif; ?>
						</div>

						<div class="gs-field">
							<label for="gs-excl"><?php esc_html_e( 'Exclude patterns', 'githubsync' ); ?></label>
							<textarea id="gs-excl" name="exclude_patterns" rows="4" class="large-text code gs-code-input" placeholder="docs/**&#10;*.md"><?php echo esc_textarea( $gs_excl ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Optional. One glob per line, for files in the repository that should not be copied to this site, for example docs/** or *.md. * matches within a folder, ** matches across folders. Leave empty to deploy everything. Protected files (.git, .github, .env, wp-config.php, key and certificate files) are always excluded and cannot be re-included.', 'githubsync' ); ?>
							</p>
						</div>
					</div>
				</section>

				<section class="gs-panel">
					<div class="gs-panel__head">
						<span class="gs-panel__step">3</span>
						<div>
							<h2 class="gs-panel__title"><?php esc_html_e( 'Behaviour', 'githubsync' ); ?></h2>
							<p class="gs-panel__hint"><?php esc_html_e( 'When this deployment is allowed to run.', 'githubsync' ); ?></p>
						</div>
					</div>
					<div class="gs-panel__body">
						<div class="gs-check-group">
							<label><input type="checkbox" name="auto_deploy" value="1" <?php checked( $gs_auto, 1 ); ?>> <?php esc_html_e( 'Deploy automatically on push to this branch', 'githubsync' ); ?></label>
							<p class="description gs-hint"><?php esc_html_e( 'A webhook delivery for this repository and branch starts a deployment without anyone logged in. Leave unticked to deploy only when you press the button.', 'githubsync' ); ?></p>
						</div>
						<div class="gs-check-group">
							<label><input type="checkbox" name="manual_deploy" value="1" <?php checked( $gs_manual, 1 ); ?>> <?php esc_html_e( 'Allow manual deployment from the dashboard', 'githubsync' ); ?></label>
							<p class="description gs-hint"><?php esc_html_e( 'Shows a Deploy button for this deployment, which deploys the configured branch on demand.', 'githubsync' ); ?></p>
						</div>
						<div class="gs-check-group">
							<label><input type="checkbox" name="adopt_existing" value="1" <?php checked( $gs_adopt, 1 ); ?>> <?php esc_html_e( 'Adopt an existing destination directory (required if it already contains files)', 'githubsync' ); ?></label>
							<p class="description gs-hint"><?php esc_html_e( 'Tick this when the destination folder already exists on this site, for example an existing plugin you now want managed by GitHub. Untick it for a fresh, empty destination. A folder that already holds files is refused unless this is ticked.', 'githubsync' ); ?></p>
						</div>
						<p class="description gs-warn"><?php esc_html_e( 'Deployment replaces files in the destination. Files excluded by policy are preserved. There is no database migration.', 'githubsync' ); ?></p>
					</div>
				</section>

				<div class="gs-form-foot">
					<button type="submit" class="button button-primary"><?php echo $gs_is_new ? esc_html__( 'Save and continue', 'githubsync' ) : esc_html__( 'Save changes', 'githubsync' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=githubsync-deployments' ) ); ?>"><?php esc_html_e( 'Cancel', 'githubsync' ); ?></a>
				</div>
			</form>
		</div>

		<aside class="gs-layout__side">
			<?php if ( $gs_is_new ) : ?>
				<div class="gs-card gs-help">
					<h2 class="gs-help__title"><?php \GitHubSync\Admin\AdminMenu::icon( 'check' ); ?> <?php esc_html_e( 'Filling this in', 'githubsync' ); ?></h2>
					<ul class="gs-help__list">
						<li><strong><?php esc_html_e( 'Repository', 'githubsync' ); ?></strong> <?php esc_html_e( '— owner/name, exactly as in the GitHub URL.', 'githubsync' ); ?></li>
						<li><strong><?php esc_html_e( 'Branch', 'githubsync' ); ?></strong> <?php esc_html_e( '— the branch you push to. Check it on GitHub rather than assuming main.', 'githubsync' ); ?></li>
						<li><strong><?php esc_html_e( 'Source path', 'githubsync' ); ?></strong> <?php esc_html_e( '— empty when the code is at the repository root, otherwise the subfolder that holds it.', 'githubsync' ); ?></li>
						<li><strong><?php esc_html_e( 'Destination', 'githubsync' ); ?></strong> <?php esc_html_e( '— the type and folder name decide where files land. Tick "Adopt an existing destination" to reuse a folder that already has files.', 'githubsync' ); ?></li>
					</ul>
					<p class="gs-help__note">
						<?php esc_html_e( 'Open the form with ?gs_repo=owner/name or from Browse repositories and GitHub fills in the branch, source path and folder for you.', 'githubsync' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="gs-panel">
				<div class="gs-panel__head">
					<div>
						<h2 class="gs-panel__title"><?php esc_html_e( 'Before you save', 'githubsync' ); ?></h2>
					</div>
				</div>
				<div class="gs-panel__body">
					<ul class="gs-help__list">
						<li><?php esc_html_e( 'Protected files are never deployed: .git, .github, .env, wp-config.php and key or certificate files.', 'githubsync' ); ?></li>
						<li><?php esc_html_e( 'Deploying replaces the destination contents; anything excluded by policy is preserved.', 'githubsync' ); ?></li>
						<li><?php esc_html_e( 'Adopt an existing folder only when you intend GitHubSync to manage it from now on.', 'githubsync' ); ?></li>
					</ul>
				</div>
			</div>
		</aside>
	</div>
</div>
