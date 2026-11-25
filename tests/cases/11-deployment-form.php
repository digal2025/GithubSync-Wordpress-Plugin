<?php
/**
 * The Add Deployment screen renders the guidance for every field.
 *
 * The form is plain PHP that echoes a data array, so rendering it with output
 * buffering is the cheapest honest check: if a translator string, a variable or
 * a helper goes missing, the screen breaks before the user ever sees it.
 *
 * @package GitHubSync
 */

use GitHubSync\Domain\DestinationPolicy;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Deployment form guidance' );

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

$data = array(
	'id'           => 0,
	'row'          => array(),
	'types'        => DestinationPolicy::types(),
	'branches'     => array(),
	'branch_error' => '',
	'repo_param'   => '',
);

CaseRunner::case( 'the new deployment form explains how to fill it in' );
$html = $render( $data );

Assert::true( false !== strpos( $html, 'Filling this in' ), 'the form opens with the how-to block' );
Assert::same( 1, substr_count( $html, 'gs-card' ), 'the how-to block is a single card' );

CaseRunner::case( 'every text field carries a concrete hint' );
$hints = array(
	'owner/repository'   => 'the repository placeholder shows the required shape',
	'digal2025/'         => 'the repository hint names a real example',
	'case-sensitive'     => 'the branch hint warns about casing',
	'no leading or'      => 'the source path hint explains the format',
	'Just the folder name' => 'the destination hint says name, not path',
);

foreach ( $hints as $needle => $label ) {
	Assert::true( false !== stripos( $html, $needle ), $label );
}

CaseRunner::case( 'the destination hint no longer suggests typing a path' );
Assert::false( false !== strpos( $html, 'wp-content/plugins/my-plugin' ), 'the old full-path example is gone' );

CaseRunner::case( 'the deployment type lists all three approved roots' );
foreach ( array( 'wp-content/plugins', 'wp-content/themes', 'githubsync-sites' ) as $root ) {
	Assert::true( false !== strpos( $html, $root ), 'the type hint names ' . $root );
}

CaseRunner::case( 'each behaviour checkbox is explained' );
Assert::same( 3, substr_count( $html, 'gs-hint' ), 'all three checkboxes have a hint' );
Assert::true( false !== strpos( $html, 'Adopt' ) || false !== strpos( $html, 'adopt' ), 'adoption is explained' );

CaseRunner::case( 'the existing deployment edit screen hides the how-to' );
$edit = $data;
$edit['id']  = 7;
$edit['row'] = array(
	'id'                    => 7,
	'name'                  => 'Existing',
	'repository_full_name'  => 'octocat/hello-world',
	'branch'                => 'main',
	'source_path'           => '',
	'deployment_type'       => 'plugin',
	'destination_slug'      => 'hello-world',
	'exclude_patterns'      => '',
	'auto_deploy'           => 1,
	'manual_deploy'         => 0,
	'adopt_existing'        => 1,
);

$edit_html = $render( $edit );

Assert::false( false !== strpos( $edit_html, 'Filling this in' ), 'editing an existing deployment skips the how-to' );
Assert::true( false !== strpos( $edit_html, 'octocat/hello-world' ), 'the saved repository is filled in' );
Assert::true( false !== strpos( $edit_html, 'Edit Deployment' ), 'the heading switches to edit' );
