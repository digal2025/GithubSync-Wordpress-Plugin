<?php
/**
 * Minimal assertion helper.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Tests;

/**
 * Collects assertions, reports pass/fail/skip.
 */
final class CaseRunner {

	/** @var int */
	public static $passed = 0;

	/** @var int */
	public static $failed = 0;

	/** @var int */
	public static $skipped = 0;

	/** @var string[] */
	public static $failures = array();

	/** @var string[] */
	public static $skips = array();

	/** @var string */
	private static $current = '';

	/** @var string */
	private static $group = '';

	/** @var string[] */
	public static $groups = array();

	/**
	 * Start a group.
	 *
	 * @param string $name Group name.
	 * @return void
	 */
	public static function group( $name ) {
		self::$group     = $name;
		self::$groups[]  = $name;
		self::$current   = '';
		echo "\n== {$name} ==\n";
	}

	/**
	 * Start a case.
	 *
	 * @param string $name Case name.
	 * @return void
	 */
	public static function case( $name ) {
		self::$current = $name;
	}

	/**
	 * Record a pass.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function pass( $message ) {
		++self::$passed;
		echo "  ok    " . self::label() . $message . "\n";
	}

	/**
	 * Record a failure.
	 *
	 * @param string $message Message.
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual Actual value.
	 * @return void
	 */
	public static function fail( $message, $expected = null, $actual = null ) {
		++self::$failed;
		$detail   = self::render( $expected, $actual );
		$line     = self::label() . $message . $detail;
		self::$failures[] = '[' . self::$group . '] ' . $line;
		echo "  FAIL  " . $line . "\n";
	}

	/**
	 * Record a skip.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function skip( $message ) {
		++self::$skipped;
		self::$skips[] = '[' . self::$group . '] ' . self::label() . $message;
		echo "  SKIP  " . self::label() . $message . "\n";
	}

	/**
	 * Whether the current test failed (reset by each case).
	 *
	 * @var bool
	 */
	public static $case_failed = false;

	/**
	 * Begin a new case marker.
	 *
	 * @param string $name Case.
	 * @return void
	 */
	public static function begin( $name ) {
		self::$current     = $name;
		self::$case_failed = false;
	}

	/**
	 * Prefix for output lines.
	 *
	 * @return string
	 */
	private static function label() {
		return '' === self::$current ? '' : self::$current . ': ';
	}

	/**
	 * Human-readable diff.
	 *
	 * @param mixed $expected Expected.
	 * @param mixed $actual   Actual.
	 * @return string
	 */
	private static function render( $expected, $actual ) {
		if ( true === $expected ) {
			return ' (expected true, got ' . self::short( $actual ) . ')';
		}
		return ' (expected ' . self::short( $expected ) . ', got ' . self::short( $actual ) . ')';
	}

	/**
	 * Compact value description.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function short( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_string( $value ) ) {
			$value = strlen( $value ) > 160 ? substr( $value, 0, 157 ) . '...' : $value;
			return '"' . addcslashes( $value, "\0..\37" ) . '"';
		}
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value );
			return strlen( $json ) > 160 ? substr( $json, 0, 157 ) . '...' : $json;
		}
		return (string) $value;
	}

	/**
	 * Print the summary.
	 *
	 * @return int Exit code.
	 */
	public static function summary() {
		$total = self::$passed + self::$failed;
		echo "\n--------------------------------------------\n";
		echo sprintf( "Assertions: %d  Passed: %d  Failed: %d  Skipped: %d\n", $total, self::$passed, self::$failed, self::$skipped );

		if ( self::$failures ) {
			echo "\nFailures:\n";
			foreach ( self::$failures as $failure ) {
				echo '  - ' . $failure . "\n";
			}
		}

		if ( self::$skips ) {
			echo "\nSkipped (environment-dependent):\n";
			foreach ( self::$skips as $skip ) {
				echo '  - ' . $skip . "\n";
			}
		}

		echo self::$failed ? "\nRESULT: FAIL\n" : "\nRESULT: PASS\n";
		return self::$failed ? 1 : 0;
	}
}

/**
 * Assertion helpers.
 */
final class Assert {

	/**
	 * Strict equality.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function same( $expected, $actual, $message = 'values should be identical' ) {
		if ( $expected === $actual ) {
			CaseRunner::pass( $message );
		} else {
			CaseRunner::fail( $message, $expected, $actual );
		}
	}

	/**
	 * Truthiness.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public static function true( $actual, $message = 'condition should be true' ) {
		if ( true === $actual ) {
			CaseRunner::pass( $message );
		} else {
			CaseRunner::fail( $message, true, $actual );
		}
	}

	/**
	 * Falsiness.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public static function false( $actual, $message = 'condition should be false' ) {
		if ( false === $actual || null === $actual || 0 === $actual || '' === $actual ) {
			CaseRunner::pass( $message );
		} else {
			CaseRunner::fail( $message, false, $actual );
		}
	}

	/**
	 * Thrown exception of a type and (optionally) error code.
	 *
	 * @param callable $fn        Callable expected to throw.
	 * @param string   $class     Exception class.
	 * @param string   $code      Optional error code substring.
	 * @param string   $message   Message.
	 * @return void
	 */
	public static function throws( $fn, $class, $code = null, $message = 'should throw' ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			if ( ! ( $e instanceof $class ) ) {
				CaseRunner::fail( $message . ' (wrong exception type)', $class, get_class( $e ) );
				return;
			}
			$actual = method_exists( $e, 'get_error_code' ) ? $e->get_error_code() : (string) $e->getCode();
			if ( null !== $code && $actual !== $code ) {
				CaseRunner::fail( $message . ' (wrong code)', $code, get_class( $e ) . ' [' . $actual . ']: ' . $e->getMessage() );
				return;
			}
			CaseRunner::pass( $message . ' [' . get_class( $e ) . ']' );
			return;
		}
		CaseRunner::fail( $message . ' (nothing thrown)', $class, 'no exception' );
	}

	/**
	 * Does not throw.
	 *
	 * @param callable $fn      Callable.
	 * @param string   $message Message.
	 * @return mixed Return value.
	 */
	public static function no_throw( $fn, $message = 'should not throw' ) {
		try {
			$result = $fn();
			CaseRunner::pass( $message );
			return $result;
		} catch ( \Throwable $e ) {
			CaseRunner::fail( $message . ' threw ' . get_class( $e ) . ': ' . $e->getMessage(), 'no exception', get_class( $e ) );
			return null;
		}
	}

	/**
	 * Skip.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function skip( $message ) {
		CaseRunner::skip( $message );
	}

	/**
	 * Recursive directory create.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function mktmp( $dir ) {
		$path = GITHUBSYNC_TEST_TMP . '/' . $dir;
		if ( is_dir( $path ) ) {
			self::rmtree( $path );
		}
		mkdir( $path, 0700, true );
		return $path;
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public static function rmtree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			self::rmtree( $path . '/' . $entry );
		}
		@rmdir( $path );
	}
}
