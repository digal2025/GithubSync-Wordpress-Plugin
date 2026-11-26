<?php
/**
 * The deployment screen offers a one-click rollback from GitHub history.
 *
 * Rendering the template with sample data is the cheapest honest check that
 * the new section, its nonce and its current-commit marker are all wired.
 *
 * @package GitHubSync
 */

use GitHubSync\Deployment\DeploymentManager;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'GitHub history rollback' );

/**
 * Render the deployment detail template and return its HTML.
 *
 * @param array $data Template data.
 * @return string
 */
$render = static function ( array $data ) {
	ob_start();
	include GITHUBSYNC_DIR . 'templates/deployment-detail.php';

	return (string) ob_get_clean();
};

$sha = str_repeat( 'a', 40 );
$old = str_repeat( 'b', 40 );

$data = array(
	'row'           => array(
		'id'                   => 7,
		'name'                 => 'Demo deployment',
		'status'               => 'active',
		'repository_full_name' => 'octocat/hello-world',
		'branch'               => 'main',
		'source_path'          => '',
		'destination_path'     => WP_CONTENT_DIR . '/plugins/hello-world',
		'deployment_type'      => 'plugin',
		'destination_slug'     => 'hello-world',
		'auto_deploy'          => 1,
		'manual_deploy'        => 1,
		'last_deployed_sha'    => $sha,
		'webhook_token'        => 'token',
	),
	'webhook_url'   => 'https://example.test/wp-json/githubsync/v1/push/token',
	'secret'        => 'secret',
	'jobs'          => array(),
	'rollbacks'     => array(),
	'commits'       => array(
		array(
			'sha'     => $sha,
			'message' => 'Current commit',
			'author'  => 'Octocat',
			'date'    => '2026-01-02T10:00:00Z',
			'url'     => 'https://github.com/octocat/hello-world/commit/' . $sha,
		),
		array(
			'sha'     => $old,
			'message' => 'Older commit',
			'author'  => 'Octocat',
			'date'    => '2026-01-01T10:00:00Z',
			'url'     => 'https://github.com/octocat/hello-world/commit/' . $old,
		),
	),
	'commits_error' => '',
	'logs'          => array(),
);

CaseRunner::case( 'the deployment screen lists GitHub history' );
$html = $render( $data );

Assert::true( false !== strpos( $html, 'GitHub history' ), 'the history section is present' );
Assert::true( false !== strpos( $html, 'Older commit' ), 'the older commit is shown' );
Assert::true( false !== strpos( $html, 'githubsync_rollback_commit' ), 'the rollback action is wired' );
Assert::true( false !== strpos( $html, 'name="commit_sha"' ), 'the commit SHA is submitted' );

CaseRunner::case( 'the deployed commit is marked and not offered as a rollback' );
Assert::same( 1, substr_count( $html, 'Current version' ), 'only the current commit is marked' );
Assert::same( 1, substr_count( $html, 'value="' . $old . '"' ), 'only the older commit gets a button' );

CaseRunner::case( 'a failed history lookup stays usable' );
$broken         = $data;
$broken['commits']       = array();
$broken['commits_error'] = 'GitHub is unavailable.';
$broken_html    = $render( $broken );

Assert::true( false !== strpos( $broken_html, 'GitHub is unavailable.' ), 'the error is explained' );
Assert::false( false !== strpos( $broken_html, 'name="commit_sha"' ), 'no rollback buttons are offered without commits' );

CaseRunner::case( 'an invalid SHA is refused before any job is queued' );
$result = ( new DeploymentManager() )->rollback_to_commit( 7, 'not-a-sha' );

Assert::true( is_wp_error( $result ), 'a malformed SHA returns an error' );
