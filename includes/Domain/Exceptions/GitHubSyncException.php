<?php
/**
 * Domain exception carrying a stable error code.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Domain\Exceptions;

use GitHubSync\Support\ErrorCodes;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A failure that maps to a stable error code and a safe message.
 */
class GitHubSyncException extends RuntimeException {

	/**
	 * Stable error code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Additional non-secret diagnostic context.
	 *
	 * @var array
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Stable code from ErrorCodes.
	 * @param string $detail     Optional internal detail (never secrets).
	 * @param array  $context    Optional context array (redacted before logging).
	 */
	public function __construct( $error_code, $detail = '', array $context = array() ) {
		$this->error_code = $error_code;
		$this->context    = $context;

		$message = ErrorCodes::message( $error_code );
		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		parent::__construct( $message );
	}

	/**
	 * Stable error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Diagnostic context.
	 *
	 * @return array
	 */
	public function get_context() {
		return $this->context;
	}
}
