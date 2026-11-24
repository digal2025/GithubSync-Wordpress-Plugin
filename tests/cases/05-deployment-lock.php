<?php
/**
 * Spec §3.7.1: per-configuration deployment lock.
 *
 * @package GitHubSync
 */

use GitHubSync\Deployment\DeploymentLock;
use GitHubSync\Support\Helpers;
use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;

CaseRunner::group( 'Deployment lock (spec §3.7.1)' );

$lock        = new DeploymentLock();
$deployment  = 990001;

CaseRunner::case( 'acquire, contend, release' );
$token = $lock->acquire( $deployment );
Assert::true( is_string( $token ) && strlen( $token ) >= 16, 'first acquire returns a token' );
Assert::true( $lock->is_locked( $deployment ), 'lock reports held' );
Assert::false( $lock->acquire( $deployment ), 'second acquire is refused while held' );

$lock->release( $deployment, $token );
Assert::false( $lock->is_locked( $deployment ), 'release clears the lock' );
$again = $lock->acquire( $deployment );
Assert::true( is_string( $again ), 'lock can be re-acquired after release' );

CaseRunner::case( 'only the holder can release' );
$other_id = $deployment + 1;
$other    = $lock->acquire( $other_id ); // Different deployment ID, must succeed.
Assert::true( is_string( $other ), 'a different configuration locks independently' );
$lock->release( $deployment, 'not-the-token' );
Assert::true( $lock->is_locked( $deployment ), 'a foreign token cannot release the lock' );

CaseRunner::case( 'heartbeat extends only for the holder' );
Assert::true( $lock->heartbeat( $deployment, $again, 600 ), 'holder heartbeat succeeds' );
Assert::false( $lock->heartbeat( $deployment, 'wrong-token', 600 ), 'foreign heartbeat fails' );

CaseRunner::case( 'expired locks can be stolen' );
$lock->release( $deployment, $again );
$short = $lock->acquire( $deployment, -1 ); // Already expired.
Assert::true( is_string( $short ), 'lock with a negative TTL is acquired' );
Assert::false( $lock->is_locked( $deployment ), 'expired lock reads as not held' );
$stolen = $lock->acquire( $deployment );
Assert::true( is_string( $stolen ), 'expired lock is stolen by the next runner' );
Assert::false( $stolen === $short, 'new holder gets a new token' );
Assert::false( $lock->heartbeat( $deployment, $short, 600 ), 'previous holder can no longer extend the stolen lock' );

CaseRunner::case( 'corrupt lock rows are recovered' );
$lock->release( $deployment, $stolen );
update_option( 'githubsync_lock_' . $deployment, 'garbage-not-json', '', 'no' );
$recovered = $lock->acquire( $deployment );
Assert::true( is_string( $recovered ), 'a corrupt lock row is replaced' );
$lock->release( $deployment, $recovered );

CaseRunner::case( 'cleanup' );
$lock->release( $other_id, $other );
delete_option( 'githubsync_lock_' . $other_id );
$lock->release( $deployment, $recovered );
Assert::false( $lock->is_locked( $deployment ), 'test locks cleaned up' );
delete_option( 'githubsync_lock_' . $deployment );
