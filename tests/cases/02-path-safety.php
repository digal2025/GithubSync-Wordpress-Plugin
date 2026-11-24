<?php
/**
 * Spec §3.7.9-10, §9: path traversal / Zip Slip defences.
 *
 * @package GitHubSync
 */

use GitHubSync\Deployment\SafeArchiveExtractor;
use GitHubSync\Security\PathGuard;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Path safety (spec §3.7.9-10)' );

CaseRunner::case( 'normalize' );
Assert::same( 'a/b/c', PathGuard::normalize( 'a//b///c/' ), 'collapses separators, strips trailing slash' );
Assert::same( 'a/b', PathGuard::normalize( './a/b' ), 'strips leading ./' );
Assert::same( 'a/b', PathGuard::normalize( 'a\\b' ), 'converts backslashes' );
Assert::same( '', PathGuard::normalize( '' ), 'empty stays empty' );

CaseRunner::case( 'is_safe_relative accepts ordinary paths' );
Assert::true( PathGuard::is_safe_relative( 'style.css' ), 'simple file' );
Assert::true( PathGuard::is_safe_relative( 'inc/class-thing.php' ), 'nested file' );
Assert::true( PathGuard::is_safe_relative( 'a..b/c' ), 'dots inside names allowed' );
Assert::true( PathGuard::is_safe_relative( '.env' ), '.env is a legal relative name (filtered later)' );
Assert::same( 'x', PathGuard::is_safe_relative( './x' ) ? PathGuard::normalize( './x' ) : false, 'a leading ./ is normalised away, not treated as traversal' );

CaseRunner::case( 'is_safe_relative rejects traversal and absolutes' );
$bad = array(
	''                  => 'empty',
	"a\0b"              => 'null byte',
	'/etc/passwd'       => 'absolute POSIX',
	'../wp-config.php'  => 'parent traversal',
	'a/../../b'         => 'nested traversal',
	'a/./b'             => 'inner dot segment',
	'C:/Windows'        => 'drive letter',
	'c:foo'             => 'drive-relative',
	'\\\\server\\share' => 'UNC share',
	'file:///etc/passwd' => 'scheme prefix',
);

foreach ( $bad as $input => $label ) {
	Assert::false( PathGuard::is_safe_relative( $input ), 'rejects ' . $label . ' (' . str_replace( "\0", '\\0', $input ) . ')' );
}

CaseRunner::case( 'is_inside containment' );
$root = '/var/www/wp-content/plugins';
Assert::true( PathGuard::is_inside( $root . '/theme', $root ), 'child is inside' );
Assert::true( PathGuard::is_inside( $root, $root ), 'root is inside itself' );
Assert::false( PathGuard::is_inside( $root . '-evil/theme', $root ), 'prefix-similar sibling is outside' );
Assert::false( PathGuard::is_inside( '/var/www/wp-content/themes/x', $root ), 'other root outside' );
Assert::false( PathGuard::is_inside( '', $root ), 'empty candidate rejected' );

CaseRunner::case( 'join' );
Assert::same( '/var/root/a/b', PathGuard::join( '/var/root', 'a/b' ), 'safe relative joins' );
Assert::false( PathGuard::join( '/var/root', '../etc' ), 'traversal does not join' );
Assert::false( PathGuard::join( '/var/root', '/etc' ), 'absolute does not join' );

CaseRunner::case( 'symlink escape is detected by resolves_inside' );
$base    = Assert::mktmp( 'pathguard' );
$outside = Assert::mktmp( 'outside' );
mkdir( $base . '/real', 0700, true );
symlink( $outside, $base . '/link' );

Assert::true( PathGuard::resolves_inside( $base . '/real/file.txt', $base ), 'normal child resolves inside' );
Assert::false( PathGuard::resolves_inside( $base . '/link/pwned.txt', $base ), 'symlinked parent escapes' );
Assert::same( realpath( $base ), PathGuard::canonicalize( $base . '/real/..' ), 'canonicalize resolves .. against the real ancestor' );

CaseRunner::case( 'canonicalize of not-yet-created paths' );
$canon = PathGuard::canonicalize( $base . '/new/deep/file.txt' );
Assert::same( realpath( $base ) . '/new/deep/file.txt', $canon, 'appends missing segments to the real ancestor' );
Assert::rmtree( $base );
Assert::rmtree( $outside );

CaseRunner::case( 'Zip Slip is rejected at extraction' );
if ( ! class_exists( 'ZipArchive' ) ) {
	Assert::skip( 'ZipArchive extension missing' );
} else {
	$dir = Assert::mktmp( 'ziptrav' );
	$zip = new ZipArchive();
	$zip->open( $dir . '/evil.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'okay/file.txt', 'hello' );
	$zip->addFromString( '../../../escaped.php', '<?php echo 1;' );
	$zip->close();

	$target = $dir . '/out';
	mkdir( $target, 0700, true );
	Assert::throws(
		static function () use ( $dir, $target ) {
			( new SafeArchiveExtractor() )->extract( $dir . '/evil.zip', $target, array() );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'ARCHIVE_PATH_INVALID',
		'parent traversal entry throws ARCHIVE_PATH_INVALID'
	);
	Assert::rmtree( $dir );
}

CaseRunner::case( 'absolute-path and null-byte archive entries are rejected' );
if ( ! class_exists( 'ZipArchive' ) ) {
	Assert::skip( 'ZipArchive extension missing' );
} else {
	foreach ( array( '/abs/file.txt', 'a:stream.txt', '\\\\srv\\share\\x.txt' ) as $name ) {
		$dir = Assert::mktmp( 'zipbad' );
		$zip = new ZipArchive();
		$zip->open( $dir . '/bad.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( $name, 'x' );
		$zip->close();
		$target = $dir . '/out';
		mkdir( $target, 0700, true );
		Assert::throws(
			static function () use ( $dir, $target ) {
				( new SafeArchiveExtractor() )->extract( $dir . '/bad.zip', $target, array() );
			},
			'GitHubSync\Domain\Exceptions\GitHubSyncException',
			'ARCHIVE_PATH_INVALID',
			'rejects entry name ' . $name
		);
		Assert::rmtree( $dir );
	}

	// ZipArchive truncates entry names at NUL when writing, so a NUL-bearing
	// ZIP can only be built by hand. Guard against ZipArchive silently having
	// produced a harmless fixture instead of a real test.
	$dir   = Assert::mktmp( 'zipnul' );
	$zip   = new ZipArchive();
	$zip->open( $dir . '/probe.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( "nul\0byte.txt", 'x' );
	$zip->close();
	$probe = new ZipArchive();
	$probe->open( $dir . '/probe.zip' );
	$stored = $probe->getNameIndex( 0 );
	$probe->close();
	Assert::false( false !== strpos( $stored, "\0" ), 'ZipArchive cannot store NUL entry names, so this vector is untestable via ZipArchive' );
	Assert::rmtree( $dir );
}

CaseRunner::case( 'symlink entries are rejected' );
if ( ! class_exists( 'ZipArchive' ) ) {
	Assert::skip( 'ZipArchive extension missing' );
} else {
	$dir = Assert::mktmp( 'zipsym' );
	$zip = new ZipArchive();
	$zip->open( $dir . '/sym.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'link', '/etc/passwd' );
	// Mark entry 0 as a UNIX symlink (S_IFLNK | 0777).
	$zip->setExternalAttributesName( 'link', ZipArchive::OPSYS_UNIX, ( 0120000 | 0777 ) << 16 );
	$zip->close();

	$probe = new ZipArchive();
	$probe->open( $dir . '/sym.zip' );
	$probe->getExternalAttributesIndex( 0, $opsys, $attr );
	$probe->close();
	Assert::same( ZipArchive::OPSYS_UNIX, $opsys, 'fixture really is a UNIX-mode archive' );
	Assert::same( 0120000, ( $attr >> 16 ) & 0170000, 'fixture really carries the symlink mode' );

	$target = $dir . '/out';
	mkdir( $target, 0700, true );
	Assert::throws(
		static function () use ( $dir, $target ) {
			( new SafeArchiveExtractor() )->extract( $dir . '/sym.zip', $target, array() );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'ARCHIVE_PATH_INVALID',
		'symlink entry throws ARCHIVE_PATH_INVALID'
	);
	Assert::false( file_exists( $target . '/link' ), 'nothing was written' );
	Assert::rmtree( $dir );
}

CaseRunner::case( 'device / fifo / socket entries are rejected' );
if ( ! class_exists( 'ZipArchive' ) ) {
	Assert::skip( 'ZipArchive extension missing' );
} else {
	$modes = array( 0020000 => 'char device', 0060000 => 'block device', 0010000 => 'fifo', 0140000 => 'socket' );
	foreach ( $modes as $mode => $label ) {
		$dir = Assert::mktmp( 'zipspec' );
		$zip = new ZipArchive();
		$zip->open( $dir . '/spec.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'node', '' );
		$zip->setExternalAttributesName( 'node', ZipArchive::OPSYS_UNIX, ( $mode | 0644 ) << 16 );
		$zip->close();
		$target = $dir . '/out';
		mkdir( $target, 0700, true );
		Assert::throws(
			static function () use ( $dir, $target ) {
				( new SafeArchiveExtractor() )->extract( $dir . '/spec.zip', $target, array() );
			},
			'GitHubSync\Domain\Exceptions\GitHubSyncException',
			'ARCHIVE_PATH_INVALID',
			'rejects ' . $label
		);
		Assert::rmtree( $dir );
	}
}

CaseRunner::case( 'file count, per-file and total size limits' );
if ( ! class_exists( 'ZipArchive' ) ) {
	Assert::skip( 'ZipArchive extension missing' );
} else {
	$dir = Assert::mktmp( 'ziplim' );

	// Too many files.
	$zip = new ZipArchive();
	$zip->open( $dir . '/many.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	for ( $i = 0; $i < 6; $i++ ) {
		$zip->addFromString( 'f' . $i . '.txt', 'x' );
	}
	$zip->close();
	$target = $dir . '/out-many';
	mkdir( $target, 0700, true );
	Assert::throws(
		static function () use ( $dir, $target ) {
			( new SafeArchiveExtractor() )->extract( $dir . '/many.zip', $target, array( 'max_files' => 3 ) );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'LIMIT_EXCEEDED',
		'max_files enforced'
	);

	// Single oversized file.
	$zip = new ZipArchive();
	$zip->open( $dir . '/big.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'big.txt', str_repeat( 'A', 4096 ) );
	$zip->close();
	$target = $dir . '/out-big';
	mkdir( $target, 0700, true );
	Assert::throws(
		static function () use ( $dir, $target ) {
			( new SafeArchiveExtractor() )->extract( $dir . '/big.zip', $target, array( 'max_file_bytes' => 1024 ) );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'LIMIT_EXCEEDED',
		'max_file_bytes enforced'
	);

	// Total size across small files.
	$zip = new ZipArchive();
	$zip->open( $dir . '/total.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	for ( $i = 0; $i < 4; $i++ ) {
		$zip->addFromString( 'p' . $i . '.txt', str_repeat( 'B', 500 ) );
	}
	$zip->close();
	$target = $dir . '/out-total';
	mkdir( $target, 0700, true );
	Assert::throws(
		static function () use ( $dir, $target ) {
			( new SafeArchiveExtractor() )->extract( $dir . '/total.zip', $target, array( 'max_total_bytes' => 1200 ) );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'LIMIT_EXCEEDED',
		'max_total_bytes enforced'
	);

	// Corrupt archive.
	file_put_contents( $dir . '/junk.zip', 'this is not a zip file' );
	$target = $dir . '/out-junk';
	mkdir( $target, 0700, true );
	Assert::throws(
		static function () use ( $dir, $target ) {
			( new SafeArchiveExtractor() )->extract( $dir . '/junk.zip', $target, array() );
		},
		'GitHubSync\Domain\Exceptions\GitHubSyncException',
		'ARCHIVE_INVALID',
		'non-ZIP payload throws ARCHIVE_INVALID'
	);

	Assert::rmtree( $dir );
}

CaseRunner::case( 'GitHub zipball wrapper prefix is stripped' );
if ( ! class_exists( 'ZipArchive' ) ) {
	Assert::skip( 'ZipArchive extension missing' );
} else {
	$dir = Assert::mktmp( 'zipwrap' );
	$sha = str_repeat( 'a1b2c3d4', 5 );
	$zip = new ZipArchive();
	$zip->open( $dir . '/gh.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'owner-repo-' . $sha . '/', '' );
	$zip->addFromString( 'owner-repo-' . $sha . '/style.css', '/* theme */' );
	$zip->addFromString( 'owner-repo-' . $sha . '/inc/a.php', '<?php' );
	$zip->close();

	$target = $dir . '/out';
	mkdir( $target, 0700, true );
	$result = Assert::no_throw(
		static function () use ( $dir, $target ) {
			return ( new SafeArchiveExtractor() )->extract( $dir . '/gh.zip', $target, array() );
		},
		'extraction succeeds'
	);
	Assert::same( 'owner-repo-' . $sha . '/', $result['prefix'], 'wrapper detected' );
	Assert::same( 2, $result['files'], 'two files reported' );
	Assert::true( is_file( $target . '/style.css' ), 'files land at the root, not under the wrapper' );
	Assert::true( is_file( $target . '/inc/a.php' ), 'nested paths preserved' );
	Assert::rmtree( $dir );
}
