<?php
/**
 * Structured, redacted logging.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Support;

use GitHubSync\Database\LogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Writes log rows with secrets stripped before they reach the database.
 */
final class Logger {

	/**
	 * Repository.
	 *
	 * @var LogRepository
	 */
	private $logs;

	/**
	 * Constructor.
	 *
	 * @param LogRepository|null $logs Repository.
	 */
	public function __construct( ?LogRepository $logs = null ) {
		$this->logs = $logs ? $logs : new LogRepository();
	}

	/**
	 * Write a log entry.
	 *
	 * @param string   $level         info|warning|error|debug.
	 * @param string   $event_code    Stable event/error code.
	 * @param string   $message       Human message (no secrets).
	 * @param array    $context       Non-secret context.
	 * @param int|null $deployment_id Deployment ID.
	 * @param int|null $job_id        Job ID.
	 * @return void
	 */
	public function log( $level, $event_code, $message, array $context = array(), $deployment_id = null, $job_id = null ) {
		$context = Helpers::redact( $context );

		$json = '';
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			$json    = is_string( $encoded ) ? substr( $encoded, 0, 20000 ) : '';
		}

		$this->logs->insert(
			array(
				'deployment_id' => null === $deployment_id ? null : (int) $deployment_id,
				'job_id'        => null === $job_id ? null : (int) $job_id,
				'level'         => in_array( $level, array( 'info', 'warning', 'error', 'debug' ), true ) ? $level : 'info',
				'event_code'    => substr( (string) $event_code, 0, 100 ),
				'message'       => substr( wp_strip_all_tags( (string) $message ), 0, 5000 ),
				'context_json'  => $json,
				'created_at'    => Helpers::now(),
			)
		);
	}

	/**
	 * Convenience error logger for an exception.
	 *
	 * @param \Throwable $error         Error.
	 * @param int        $deployment_id Deployment ID.
	 * @param int        $job_id        Job ID.
	 * @return void
	 */
	public function error_from_exception( $error, $deployment_id = null, $job_id = null ) {
		$code = method_exists( $error, 'get_error_code' ) ? $error->get_error_code() : ErrorCodes::INTERNAL_ERROR;

		$this->log(
			'error',
			$code,
			$error->getMessage(),
			array( 'exception' => get_class( $error ) ),
			$deployment_id,
			$job_id
		);
	}
}
