<?php
/**
 * Add Deployment prefills the fields GitHub can actually answer.
 *
 * GitHub knows the repository name and the default branch. It cannot know
 * whether the code is a plugin, a theme or a static site, which subfolder
 * holds it, or what should be excluded. These cases pin that split: the
 * suggestions come from metadata, every suggestion says why, a typed value
 * is never overwritten, and a failed lookup prefills nothing.
 *
 * Network is redirected with `pre_http_request`; no request leaves the
 * machine.
 *
 * @package GitHubSync
 */

use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\GitHub\GitHubAuth;
use GitHubSync\GitHub\RepositoryPrefill;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Deployment prefill' );

/**
 * Render the form template and return its HTML.
 *
 * @param array $data Template data.
 * @return string
 */
$render = static function ( array $data ) {
	$file = GITHUBSYNC_DIR . 'templates/deployment-form.php';
	ob_start();
	include $file;

	return (string) ob_get_clean();
};

/**
 * Match the text of a named form field.
 *
 * @param string $html HTML.
 * @param string $name Field name.
 * @return string|null
 */
$field_value = static function ( $html, $name ) {
	if ( preg_match( '#<input[^>]*name="' . preg_quote( $name, '#' ) . '"[^>]*>#', $html, $m ) ) {
		if ( preg_match( '#value="([^"]*)"#', $m[0], $v ) ) {
			return html_entity_decode( $v[1], ENT_QUOTES );
		}
	}

	return null;
};

/**
 * The attribute text of a named input.
 *
 * @param string $html HTML.
 * @param string $name Field name.
 * @return string
 */
$field_tag = static function ( $html, $name ) {
	if ( preg_match( '#<input[^>]*name="' . preg_quote( $name, '#' ) . '"[^>]*>#', $html, $m ) ) {
		return $m[0];
	}

	return '';
};

$meta = array(
	'id'             => 424242,
	'full_name'      => 'scoophound/prefill-fixture',
	'private'        => true,
	'visibility'     => 'private',
	'default_branch' => 'trunk',
);

$branches = array(
	array( 'name' => 'trunk', 'sha' => str_repeat( 'a', 40 ) ),
	array( 'name' => 'feature/x', 'sha' => str_repeat( 'b', 40 ) ),
);

$base = array(
	'id'           => 0,
	'row'          => array(),
	'types'        => DestinationPolicy::types(),
	'branches'     => $branches,
	'branch_error' => '',
	'repo_param'   => 'scoophound/prefill-fixture',
	'repo_meta'    => $meta,
	'repo_error'   => '',
);

// ------------------------------------------------------------- suggestions ---

CaseRunner::case( 'GitHub metadata fills the branch, source path and folder' );
$suggest = RepositoryPrefill::suggest( $meta, $branches, array(), 0 );

Assert::same( 'trunk', $suggest['fields']['branch']['value'], 'the default branch is suggested' );
Assert::same( 'prefill-fixture', $suggest['fields']['destination_slug']['value'], 'the folder name is the repository name, lower-cased' );
Assert::false( $suggest['branch_is_default'], 'an empty branch is not yet the default one' );
Assert::false( $suggest['slug_deployed'], 'a fresh slug is not reported as already deployed' );

$trunked = RepositoryPrefill::suggest( $meta, $branches, array( 'branch' => 'trunk' ), 0 );

Assert::true( $trunked['branch_is_default'], 'the chosen default branch is recognised as the default one' );
Assert::same( '', $trunked['fields']['source_path']['value'], 'the source path is suggested as empty (repository root)' );

CaseRunner::case( 'every suggestion explains where it came from' );
foreach ( array( 'branch', 'destination_slug' ) as $key ) {
	Assert::true( isset( $suggest['fields'][ $key ]['reason'] ) && '' !== $suggest['fields'][ $key ]['reason'], $key . ' carries a reason' );
}
Assert::true( isset( $trunked['fields']['source_path']['reason'] ) && '' !== $trunked['fields']['source_path']['reason'], 'source_path carries a reason' );
Assert::true( false !== strpos( $suggest['fields']['branch']['reason'], 'trunk' ), 'the branch reason names the branch' );
Assert::true( false !== strpos( $suggest['fields']['destination_slug']['reason'], 'repository name' ), 'the folder reason names its source' );
Assert::false( isset( $suggest['fields']['deployment_type'] ), 'the deployment type is never suggested' );
Assert::false( isset( $suggest['fields']['exclude_patterns'] ), 'exclude patterns are never suggested' );

CaseRunner::case( 'the deployment type is never guessed from GitHub alone' );
foreach ( array( 'deployment_type', 'deployment_type_note', 'exclude_patterns', 'display_name' ) as $key ) {
	Assert::false( isset( $suggest['fields'][ $key ] ), $key . ' is left to the user' );
}

CaseRunner::case( 'a typed value is never overwritten' );
$typed = RepositoryPrefill::suggest(
	$meta,
	$branches,
	array(
		'branch'           => 'develop',
		'source_path'      => 'wordpress/my-plugin',
		'deployment_type'  => 'plugin',
		'destination_slug' => 'chosen-name',
	),
	0
);

Assert::same( array(), $typed['fields'], 'nothing is suggested once every field has a value' );
Assert::false( $typed['branch_is_default'], 'a non-default branch is not reported as the default' );

CaseRunner::case( 'the source path is only prefilled on the default branch' );
$other = RepositoryPrefill::suggest( $meta, $branches, array( 'branch' => 'feature/x' ), 0 );

Assert::false( isset( $other['fields']['source_path'] ), 'no source path guess off the default branch' );

CaseRunner::case( 'an unknown branch is not suggested from a stale branch list' );
$stale = RepositoryPrefill::suggest(
	$meta,
	array( array( 'name' => 'feature/x', 'sha' => str_repeat( 'c', 40 ) ) ),
	array(),
	0
);

Assert::false( isset( $stale['fields']['branch'] ), 'a default branch missing from the list is not filled in' );

CaseRunner::case( 'the repository name is cleaned into a valid folder name' );
$dotted = RepositoryPrefill::suggest(
	array(
		'id'             => 1,
		'full_name'      => 'org/site.Com',
		'private'        => false,
		'visibility'     => 'public',
		'default_branch' => 'main',
	),
	array(),
	array(),
	0
);

Assert::same( 'site.com', $dotted['fields']['destination_slug']['value'], 'case is lowered and dots kept' );

CaseRunner::case( 'a typed folder is kept and the shared-folder check reports a boolean' );
$taken = RepositoryPrefill::suggest(
	$meta,
	$branches,
	array( 'destination_slug' => 'taken-folder' ),
	0
);

Assert::false( isset( $taken['fields']['destination_slug'] ), 'a typed folder is not replaced' );
Assert::true( is_bool( $taken['slug_deployed'] ), 'the shared-folder check always reports a boolean' );

CaseRunner::case( 'a folder name taken by another deployment is reported' );
/**
 * A saved deployment and what a new form filled from it would look like.
 *
 * @return array|\WP_Error
 */
$saved_row = static function () {
	$repo = new \GitHubSync\Database\DeploymentRepository();
	$id   = $repo->create(
		array(
			'name'                 => 'Prefill collision fixture',
			'repository_full_name' => 'scoophound/prefill-collision',
			'branch'               => 'main',
			'source_path'          => '',
			'deployment_type'      => DestinationPolicy::TYPE_PLUGIN,
			'destination_slug'     => 'prefill-collision',
			'exclude_patterns'     => '',
			'auto_deploy'          => 0,
			'manual_deploy'        => 0,
			'adopt_existing'       => 0,
		)
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	return $repo->find( (int) $id );
};

$collision = $saved_row();

if ( is_wp_error( $collision ) ) {
	Assert::skip( 'the sandbox could not save a deployment: ' . $collision->get_error_message() );
} else {
	$saved_meta = array(
		'id'             => 1,
		'full_name'      => 'scoophound/prefill-collision',
		'private'        => true,
		'visibility'     => 'private',
		'default_branch' => 'main',
	);
	$clash      = RepositoryPrefill::suggest(
		$saved_meta,
		array(),
		array(
			'branch'           => 'main',
			'destination_slug' => 'prefill-collision',
		),
		0
	);
	$own        = RepositoryPrefill::suggest(
		$saved_meta,
		array(),
		array(
			'branch'           => 'main',
			'destination_slug' => 'prefill-collision',
		),
		(int) $collision['id']
	);

	Assert::true( $clash['slug_deployed'], 'a folder used by another deployment is flagged' );
	Assert::false( $own['slug_deployed'], 'a deployment does not collide with itself' );

	$repo = new \GitHubSync\Database\DeploymentRepository();
	$repo->delete( (int) $collision['id'] );
	Assert::false( $repo->find( (int) $collision['id'] ), 'the fixture deployment was removed' );
}

CaseRunner::case( 'the plugin never offers its own folder as a destination' );
$on_disk = RepositoryPrefill::slug_exists_on_disk( DestinationPolicy::TYPE_PLUGIN, 'githubsync' );

Assert::true( is_dir( GITHUBSYNC_DIR ), 'the plugin folder exists in this sandbox' );
Assert::false( $on_disk, 'the plugin refuses to treat its own folder as a deployment target' );

$self_meta = array(
	'id'             => 5,
	'full_name'      => 'someone/GitHubSync',
	'private'        => false,
	'visibility'     => 'public',
	'default_branch' => 'main',
);
$self_suggest = RepositoryPrefill::suggest( $self_meta, array(), array(), 0 );

Assert::false( isset( $self_suggest['fields']['destination_slug'] ), 'a repository called GitHubSync gets no folder guess' );

CaseRunner::case( 'no metadata, no suggestions' );
$none = RepositoryPrefill::suggest( null, array(), array(), 0 );

Assert::same( array(), $none['fields'], 'a failed lookup produces no fields' );
Assert::false( $none['branch_is_default'], 'no default branch is known' );

// ---------------------------------------------------------------- rendering ---

CaseRunner::case( 'the form shows the prefilled values and their reasons' );
$html = $render( $base );

Assert::same( 'trunk', $field_value( $html, 'branch' ), 'the branch input carries the default branch' );
Assert::same( '', $field_value( $html, 'source_path' ), 'the source path stays empty' );
Assert::same( 'prefill-fixture', $field_value( $html, 'destination_slug' ), 'the folder input carries the repository name' );
Assert::true( false !== strpos( $html, 'Prefilled from GitHub' ), 'a notice says the values came from GitHub' );
Assert::true( false !== strpos( $html, 'scoophound/prefill-fixture' ), 'the notice names the repository' );
Assert::true( false !== strpos( $html, 'private' ), 'the notice reports the visibility' );
Assert::true( false !== strpos( $html, 'default branch on GitHub (trunk)' ), 'the branch hint names its source' );
Assert::true( false !== strpos( $html, 'repository name' ), 'the folder hint names its source' );
Assert::same( 1, substr_count( $html, '<option value="plugin"' ), 'the type list still has one plugin option' );

CaseRunner::case( 'a failed lookup says so and fills nothing' );
$broken             = $base;
$broken['repo_meta'] = null;
$broken['repo_error'] = 'GitHub is not connected.';
$broken['branches']  = array();
$broken_html         = $render( $broken );

Assert::same( '', $field_value( $broken_html, 'branch' ), 'the branch input stays empty' );
Assert::same( '', $field_value( $broken_html, 'destination_slug' ), 'the folder input stays empty' );
Assert::true( false !== strpos( $broken_html, 'nothing was prefilled' ), 'the notice explains the failure' );
Assert::true( false !== strpos( $broken_html, 'GitHub is not connected.' ), 'the notice carries the GitHub error' );
Assert::false( false !== strpos( $broken_html, 'Prefilled from GitHub' ), 'the success notice is absent' );

CaseRunner::case( 'an edit screen is never prefilled over a saved row' );
$edit        = $base;
$edit['id']  = 9;
$edit['row'] = array(
	'id'                   => 9,
	'name'                 => 'Saved',
	'repository_full_name' => 'scoophound/My_Plugin',
	'branch'               => 'release',
	'source_path'          => 'pkg',
	'deployment_type'      => 'theme',
	'destination_slug'     => 'saved-theme',
	'exclude_patterns'     => '',
	'auto_deploy'          => 1,
	'manual_deploy'        => 1,
	'adopt_existing'       => 0,
);

$edit_html = $render( $edit );

Assert::same( 'release', $field_value( $edit_html, 'branch' ), 'the saved branch is kept' );
Assert::same( 'pkg', $field_value( $edit_html, 'source_path' ), 'the saved source path is kept' );
Assert::same( 'saved-theme', $field_value( $edit_html, 'destination_slug' ), 'the saved folder is kept' );
Assert::true( false !== strpos( $edit_html, 'name="deployment_type"' ), 'the edit screen still renders the type field' );
Assert::false( false !== strpos( $edit_html, 'Prefilled from GitHub' ), 'the edit screen shows no prefill notice' );

// -------------------------------------------------------------- live lookup ---

CaseRunner::case( 'metadata(true) reads the repository from GitHub and caches it' );
$auth = new GitHubAuth();
$auth->disconnect();
$connected = $auth->connect_pat( 'ghp_' . str_repeat( 'A', 36 ), array( 'login' => 'scoophound' ) );

if ( is_wp_error( $connected ) ) {
	Assert::skip( 'the test site cannot store an encrypted token: ' . $connected->get_error_message() );
} else {
	$seen   = array();
	$filter = static function ( $preempt, $args, $url ) use ( &$seen ) {
		$seen[] = $url;

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'id'             => 777,
					'full_name'      => 'scoophound/Live-Repo',
					'private'        => false,
					'visibility'     => 'public',
					'default_branch' => 'main',
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	};

	delete_transient( RepositoryPrefill::cache_key( 'scoophound/Live-Repo' ) );

	add_filter( 'pre_http_request', $filter, 10, 3 );
	$live = RepositoryPrefill::metadata( new GitHubApiClient( $auth ), 'scoophound/Live-Repo' );
	$again = RepositoryPrefill::metadata( new GitHubApiClient( $auth ), 'scoophound/Live-Repo' );
	remove_filter( 'pre_http_request', $filter, 10 );

	Assert::true( is_array( $live ), 'metadata() returns repository data' );
	Assert::same( 'main', $live['default_branch'], 'the default branch is read from GitHub' );
	Assert::same( 'main', $again['default_branch'], 'the second call returns the same data' );
	Assert::same( 1, count( $seen ), 'the second call is served from cache' );
	Assert::true( false !== strpos( $seen[0], '/repos/scoophound/Live-Repo' ), 'the request went to the repository endpoint' );

	$auth->disconnect();
	delete_transient( RepositoryPrefill::cache_key( 'scoophound/Live-Repo' ) );
}
