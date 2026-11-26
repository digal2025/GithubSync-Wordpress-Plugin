<?php
/**
 * History clearing and per-deployment size reporting.
 *
 * @package GitHubSync
 */

use GitHubSync\Database\JobRepository;
use GitHubSync\Database\LogRepository;
use GitHubSync\Database\Schema;
use GitHubSync\Deployment\BackupManager;
use GitHubSync\Filesystem\FilesystemAdapter;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'History clearing and deployment size' );

global $wpdb;

$jobs    = new JobRepository();
$logs    = new LogRepository();
$fs      = new FilesystemAdapter();
$backups = new BackupManager( $fs );

$dep_a = 700000 + ( getmypid() % 5000 ) * 4;
$dep_b = $dep_a + 1;
$dep_c = $dep_a + 2;

$job_ids = array();
$log_ids = array();

CaseRunner::case( 'clear_history removes terminal jobs and their logs' );

$done   = $jobs->create( array( 'deployment_id' => $dep_a, 'status' => 'succeeded', 'commit_sha' => str_repeat( 'a', 40 ) ) );
$failed = $jobs->create( array( 'deployment_id' => $dep_a, 'status' => 'failed', 'commit_sha' => str_repeat( 'b', 40 ) ) );
$active = $jobs->create( array( 'deployment_id' => $dep_a, 'status' => 'queued', 'commit_sha' => str_repeat( 'c', 40 ) ) );
$other  = $jobs->create( array( 'deployment_id' => $dep_b, 'status' => 'cancelled', 'commit_sha' => str_repeat( 'd', 40 ) ) );

foreach ( array( $done, $failed, $active, $other ) as $job_id ) {
	$job_ids[] = $job_id;
}

$done_log     = $logs->insert( array( 'deployment_id' => $dep_a, 'job_id' => $done, 'event_code' => 'DONE', 'message' => 'done' ) );
$active_log   = $logs->insert( array( 'deployment_id' => $dep_a, 'job_id' => $active, 'event_code' => 'ACTIVE', 'message' => 'active' ) );
$jobless_log  = $logs->insert( array( 'deployment_id' => $dep_a, 'job_id' => null, 'event_code' => 'INFO', 'message' => 'deployment note' ) );
$log_ids      = array( $done_log, $active_log, $jobless_log );

$before = $jobs->count();
$deleted = $jobs->clear_history();

Assert::same( 3, $deleted, 'all terminal jobs were removed' );
Assert::true( null === $jobs->find( $done ), 'succeeded job is gone' );
Assert::true( null === $jobs->find( $failed ), 'failed job is gone' );
Assert::true( null === $jobs->find( $other ), 'cancelled job in another deployment is gone' );
Assert::true( null !== $jobs->find( $active ), 'queued job is kept' );
Assert::same( $before - 3, $jobs->count(), 'job count reflects cleared history' );

Assert::same(
	0,
	(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::logs() . ' WHERE id = %d', $done_log ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	'log entry for a removed job is gone'
);
Assert::same(
	1,
	(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::logs() . ' WHERE id = %d', $active_log ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	'log entry for an active job is kept'
);
Assert::same(
	1,
	(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::logs() . ' WHERE id = %d', $jobless_log ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	'deployment-level log entry is kept'
);

CaseRunner::case( 'clear_history can target one deployment' );

$dep_a_job = $jobs->create( array( 'deployment_id' => $dep_a, 'status' => 'succeeded', 'commit_sha' => str_repeat( 'e', 40 ) ) );
$dep_b_job = $jobs->create( array( 'deployment_id' => $dep_b, 'status' => 'failed', 'commit_sha' => str_repeat( 'f', 40 ) ) );
$job_ids[] = $dep_a_job;
$job_ids[] = $dep_b_job;

Assert::same( 1, $jobs->clear_history( $dep_a ), 'only the selected deployment is removed' );
Assert::true( null === $jobs->find( $dep_a_job ), 'selected deployment job is gone' );
Assert::true( null !== $jobs->find( $dep_b_job ), 'other deployment job is kept' );

CaseRunner::case( 'deployment and backup sizes are reported' );

$size_dir = Assert::mktmp( 'deployment-size' );
file_put_contents( $size_dir . '/fixture.txt', str_repeat( 'x', 1024 ) );
$stats = $fs->stats( $size_dir );

Assert::same( 1, $stats['files'], 'one deployed file counted' );
Assert::same( 1024, $stats['bytes'], 'deployed bytes counted' );

$backup_dir = $backups->root() . '/' . $dep_c;
wp_mkdir_p( $backup_dir );
file_put_contents( $backup_dir . '/sample.txt', str_repeat( 'y', 2048 ) );

Assert::same( 2048, $backups->usage_for( $dep_c ), 'backup bytes counted for one deployment' );
Assert::same( 0, $backups->usage_for( $dep_c + 1 ), 'missing backup directory reports zero' );

Assert::rmtree( $backup_dir );

foreach ( $job_ids as $job_id ) {
	$wpdb->delete( Schema::jobs(), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
}

foreach ( $log_ids as $log_id ) {
	$wpdb->delete( Schema::logs(), array( 'id' => $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
}
