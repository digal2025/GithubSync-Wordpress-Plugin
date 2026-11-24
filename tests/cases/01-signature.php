<?php
/**
 * Spec §3.3 / §9: webhook HMAC SHA-256 signature verification.
 *
 * @package GitHubSync
 */

use GitHubSync\Tests\Assert;
use GitHubSync\Tests\CaseRunner;
use GitHubSync\Webhooks\SignatureVerifier;

CaseRunner::group( 'Webhook signature (spec §3.3)' );

$secret  = 'c539bb81caf3ec5fb0baac6e4ad55edbee3713b4';
$payload = '{"ref":"refs/heads/main","after":"abc"}';

CaseRunner::case( 'valid signature accepted' );
$sig = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
Assert::true( SignatureVerifier::verify( $payload, $sig, $secret ), 'correct HMAC verifies' );
Assert::same( $sig, SignatureVerifier::sign( $payload, $secret ), 'sign() matches reference HMAC' );

CaseRunner::case( 'uppercase hex accepted' );
Assert::true( SignatureVerifier::verify( $payload, 'sha256=' . strtoupper( substr( $sig, 7 ) ), $secret ), 'hex case is not significant' );

CaseRunner::case( 'wrong secret rejected' );
Assert::false( SignatureVerifier::verify( $payload, $sig, str_repeat( 'a', 64 ) ), 'different secret fails' );

CaseRunner::case( 'tampered body rejected' );
Assert::false( SignatureVerifier::verify( $payload . ' ', $sig, $secret ), 'one extra byte fails' );

CaseRunner::case( 'missing or empty header rejected' );
Assert::false( SignatureVerifier::verify( $payload, '', $secret ), 'empty signature fails' );
Assert::false( SignatureVerifier::verify( $payload, null, $secret ), 'null signature fails' );

CaseRunner::case( 'missing secret rejected' );
Assert::false( SignatureVerifier::verify( $payload, $sig, '' ), 'empty secret fails (fail closed)' );

CaseRunner::case( 'malformed headers rejected' );
Assert::false( SignatureVerifier::verify( $payload, substr( $sig, 7 ), $secret ), 'missing sha256= prefix fails' );
Assert::false( SignatureVerifier::verify( $payload, 'sha1=' . substr( $sig, 7 ), $secret ), 'sha1 prefix fails' );
Assert::false( SignatureVerifier::verify( $payload, 'sha256=' . substr( $sig, 7, 63 ), $secret ), 'truncated digest fails' );
Assert::false( SignatureVerifier::verify( $payload, $sig . 'ff', $secret ), 'over-long digest fails' );
Assert::false( SignatureVerifier::verify( $payload, 'sha256=' . str_repeat( 'z', 64 ), $secret ), 'non-hex digest fails' );

CaseRunner::case( 'raw body is signed, not re-encoded JSON' );
$raw = "{\n  \"a\": 1\n}";
Assert::true( SignatureVerifier::verify( $raw, SignatureVerifier::sign( $raw, $secret ), $secret ), 'pretty-printed JSON verifies byte-for-byte' );
Assert::false( SignatureVerifier::verify( wp_json_encode( json_decode( $raw, true ) ), SignatureVerifier::sign( $raw, $secret ), $secret ), 're-encoded JSON does not verify' );

CaseRunner::case( 'secret generator' );
$generated = SignatureVerifier::generate_secret();
Assert::same( 64, strlen( $generated ), 'generated secret is 64 hex chars' );
Assert::true( 1 === preg_match( '/^[a-f0-9]{64}$/', $generated ), 'generated secret is hex' );
Assert::true( $generated !== SignatureVerifier::generate_secret(), 'secrets are unique' );
