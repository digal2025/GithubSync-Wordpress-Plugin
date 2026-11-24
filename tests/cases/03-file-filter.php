<?php
/**
 * Spec §3.6: protected server-owned files and exclude patterns.
 *
 * @package GitHubSync
 */

use GitHubSync\Security\FileFilter;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Protected files and exclusions (spec §3.6)' );

$filter = new FileFilter();

CaseRunner::case( 'server-owned files are protected' );
$protected = array(
	'wp-config.php'                 => 'WordPress config',
	'.env'                          => 'dotenv',
	'.env.local'                    => 'dotenv variant',
	'includes/.env'                 => 'nested dotenv',
	'id_rsa'                        => 'private key',
	'deploy/id_ed25519'             => 'nested private key',
	'private.key'                   => 'key file',
	'cert.pem'                      => 'certificate',
	'githubsync-secret.key'         => 'encryption key',
	'.DS_Store'                     => 'macOS noise',
);

foreach ( $protected as $path => $label ) {
	Assert::true( $filter->is_protected( $path ), 'protected: ' . $label . ' (' . $path . ')' );
}

CaseRunner::case( 'protected list is deliberately narrow' );
// Documented behaviour: files the spec does not list as server-owned are
// deployed. Listed below so a future widening is a conscious change.
$not_protected = array( 'backup.sql', 'node_modules/pkg/index.js', 'vendor/autoload.php', 'wp-settings.php', 'data.json', '.htaccess' );
foreach ( $not_protected as $path ) {
	Assert::false( $filter->is_protected( $path ), 'not in protected list: ' . $path );
}

CaseRunner::case( 'ordinary theme/plugin files are not protected' );
$allowed = array( 'style.css', 'functions.php', 'index.php', 'inc/class-widget.php', 'assets/js/app.js', 'readme.txt', 'languages/en_US.mo', 'screenshot.png' );
foreach ( $allowed as $path ) {
	Assert::false( $filter->is_protected( $path ), 'allowed: ' . $path );
}

CaseRunner::case( 'glob semantics' );
Assert::true( FileFilter::matches( '*.log', 'debug.log' ), '*.log matches basename' );
Assert::true( FileFilter::matches( '*.log', 'logs/debug.log' ), 'slash-less pattern matches basename at depth' );
Assert::false( FileFilter::matches( '*.log', 'debug.txt' ), 'extension must match' );
Assert::false( FileFilter::matches( '*.log', 'a/b/c.log.d' ), 'anchored at the end' );
Assert::true( FileFilter::matches( 'docs/*.md', 'docs/readme.md' ), 'single star stays within a segment' );
Assert::false( FileFilter::matches( 'docs/*.md', 'docs/sub/readme.md' ), 'single star does not cross slashes' );
Assert::true( FileFilter::matches( 'docs/**/*.md', 'docs/sub/deep/readme.md' ), '** crosses directories' );
Assert::true( FileFilter::matches( 'docs/**/*.md', 'docs/readme.md' ), '**/ also matches zero directories' );
Assert::true( FileFilter::matches( 'tests/**', 'tests/unit/a.php' ), 'trailing /** matches everything below' );
Assert::true( FileFilter::matches( 'cache/', 'cache/a/b.txt' ), 'trailing slash is directory-only' );
Assert::false( FileFilter::matches( 'cache/', 'cache.txt' ), 'directory-only pattern does not match a file of the same name' );
Assert::true( FileFilter::matches( 'file?.txt', 'file1.txt' ), '? matches exactly one character' );
Assert::false( FileFilter::matches( 'file?.txt', 'file10.txt' ), '? does not match two characters' );
Assert::false( FileFilter::matches( '', 'anything' ), 'empty pattern never matches' );

CaseRunner::case( 'administrator excludes' );
$custom = new FileFilter( array( 'tests/**', '*.scss', 'dev-notes.txt' ) );
Assert::true( $custom->is_excluded( 'tests/bootstrap.php' ), 'custom dir exclude applies' );
Assert::true( $custom->is_excluded( 'assets/scss/main.scss' ), 'custom glob exclude applies' );
Assert::true( $custom->is_excluded( 'dev-notes.txt' ), 'custom basename exclude applies' );
Assert::false( $custom->is_excluded( 'assets/css/main.css' ), 'unrelated file still deployed' );
Assert::true( $custom->is_excluded( 'wp-config.php' ), 'protected files remain excluded even with custom rules' );
Assert::false( ( new FileFilter() )->is_excluded( 'tests/bootstrap.php' ), 'defaults do not exclude tests/' );

CaseRunner::case( 'exclude list parsing' );
$parsed = FileFilter::from_text( "  tests/**  \r\n\r\n*.scss\n# comment\n  " );
Assert::true( $parsed->is_excluded( 'tests/x.php' ), 'from_text handles CRLF and blank lines' );
Assert::true( $parsed->is_excluded( 'a.scss' ), 'from_text handles globs' );
$empty = FileFilter::from_text( '' );
Assert::false( $empty->is_excluded( 'tests/x.php' ), 'empty list excludes nothing extra' );
