<?php
/**
 * Spec §3.7 / acceptance "Safety and recovery": backup, verification,
 * promotion and rollback against a real filesystem.
 *
 * @package GitHubSync
 */

use GitHubSync\Deployment\BackupManager;
use GitHubSync\Deployment\PromotionManager;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Backup, promotion and rollback (spec §3.7)' );

$fs        = new FilesystemAdapter();
$backups   = new BackupManager( $fs );
$promotion = new PromotionManager( $fs );
$root      = Assert::mktmp( 'promo' );

// BackupManager writes into the real uploads directory, so each run needs its
// own deployment ids: reusing a fixed id makes a second run verify against the
// previous run's manifest and fail. Ids are removed again at the end.
$run_base    = 400000 + ( getmypid() % 5000 ) * 4;
$dep_main    = $run_base;
$dep_absent  = $run_base + 1;
$dep_prune   = $run_base + 2;
$parent    = $root . '/dest';

wp_mkdir_p( $parent . '/live' );
wp_mkdir_p( $root . '/staging' );
file_put_contents( $parent . '/live/old.txt', 'v1' );
file_put_contents( $parent . '/live/keep.txt', 'unchanged' );
file_put_contents( $root . '/staging/new.txt', 'v2' );

CaseRunner::case( 'backup is created and verified against the live tree' );
$backup = $backups->create( $parent . '/live', $dep_main, 7, str_repeat( 'b', 40 ) );
Assert::true( is_array( $backup ), 'create returns a manifest array' );
Assert::true( is_dir( $backup['path'] ), 'backup directory exists' );
Assert::true( file_exists( $backup['path'] . '/.githubsync-backup.json' ), 'manifest written after verification' );
Assert::same( 2, $backup['files'], 'both live files were copied' );
Assert::true( $backups->exists( $dep_main, 7, str_repeat( 'b', 40 ) ), 'exists() sees the backup' );

CaseRunner::case( 'verification rejects a corrupted backup' );
file_put_contents( $backup['path'] . '/old.txt', 'tampered' );
Assert::true( is_wp_error( $backups->verify( $parent . '/live', $backup['path'] ) ), 'hash mismatch is an error' );

CaseRunner::case( 'promotion swaps staging into place and removes the old tree' );
$result = $promotion->promote( $root . '/staging', $parent . '/live' );
Assert::true( true === $result, 'promote() succeeds' );
Assert::true( file_exists( $parent . '/live/new.txt' ), 'new file is live' );
Assert::false( file_exists( $parent . '/live/old.txt' ), 'old file is gone' );
Assert::same( 'v2', file_get_contents( $parent . '/live/new.txt' ), 'live content is the staged content' );
$leftovers = glob( $parent . '/.githubsync-old-*' );
Assert::same( array(), $leftovers ? $leftovers : array(), 'no old-tree leftovers remain' );

CaseRunner::case( 'promotion refuses a missing staging directory' );
$bad = $promotion->promote( $parent . '/nope', $parent . '/live' );
Assert::true( is_wp_error( $bad ), 'missing staging returns a WP_Error' );
Assert::same( 'PROMOTION_FAILED', $bad->get_error_code(), 'error code is PROMOTION_FAILED' );
Assert::true( file_exists( $parent . '/live/new.txt' ), 'live tree is untouched by the failed promotion' );

CaseRunner::case( 'rollback restores the verified backup' );
$restored = $promotion->restore_from_backup( $backup['path'], $parent . '/live' );
Assert::true( true === $restored, 'restore_from_backup() succeeds' );
Assert::true( file_exists( $parent . '/live/old.txt' ), 'old file is back' );
Assert::false( file_exists( $parent . '/live/new.txt' ), 'promoted file is gone after rollback' );

CaseRunner::case( 'rollback refuses a missing backup' );
$bad = $promotion->restore_from_backup( $parent . '/no-backup', $parent . '/live' );
Assert::true( is_wp_error( $bad ), 'missing backup returns a WP_Error' );
Assert::same( 'ROLLBACK_FAILED', $bad->get_error_code(), 'error code is ROLLBACK_FAILED' );
Assert::true( file_exists( $parent . '/live/old.txt' ), 'live tree is untouched by the failed rollback' );

CaseRunner::case( 'backup of a missing live tree is a no-op' );
$empty = $backups->create( $parent . '/absent', $dep_absent, 8, str_repeat( 'c', 40 ) );
Assert::true( is_array( $empty ) && 0 === $empty['files'], 'no files backed up for an absent tree' );

CaseRunner::case( 'prune keeps the newest N backups' );
foreach ( array( 11, 12, 13, 14 ) as $job ) {
	$backups->create( $parent . '/live', $dep_prune, $job, str_repeat( 'd', 40 ) );
}
$deleted = $backups->prune( $dep_prune, 2 );
Assert::same( 2, $deleted, 'prune removed all but the two newest' );

Assert::rmtree( $root );

// Leave the uploads backup tree exactly as we found it.
foreach ( array( $dep_main, $dep_absent, $dep_prune ) as $dep_cleanup ) {
	$fs->remove_tree( $backups->root() . '/' . $dep_cleanup, $backups->root() );
}
