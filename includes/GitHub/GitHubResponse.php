<?php
/**
 * GitHub HTTP response value object.
 *
 * @package GitHubSync
 */

namespace GitHubSync\GitHub;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises a WordPress HTTP API response.
 */
final class GitHubResponse {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status = 0;

	/**
	 * Response headers (lower-cased keys).
	 *
	 * @var array
	 */
	public $headers = array();

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * Decoded JSON body.
	 *
	 * @var mixed
	 */
	public $data = null;

	/**
	 * Decode failure flag.
	 *
	 * @var bool
	 */
	public $decode_failed = false;

	/**
	 * Build from a wp_remote_* response.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 * @return self
	 */
	public static function from_wp( $response ) {
		$self = new self();

		if ( is_wp_error( $response ) ) {
			$self->status = 0;
			$self->body   = $response->get_error_message();
			return $self;
		}

		$self->status = (int) wp_remote_retrieve_response_code( $response );
		$self->body   = (string) wp_remote_retrieve_body( $response );
		$self->headers = array_change_key_case( self::headers_to_array( wp_remote_retrieve_headers( $response ) ), CASE_LOWER );

		if ( '' !== $self->body ) {
			$decoded = json_decode( $self->body, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				$self->decode_failed = true;
			} else {
				$self->data = $decoded;
			}
		}

		return $self;
	}

	/**
	 * Normalise a WP HTTP header container to a plain array.
	 *
	 * `wp_remote_retrieve_headers()` returns an array in older WordPress
	 * releases and a `WpOrg\Requests\Utility\CaseInsensitiveDictionary` from
	 * 6.2 onwards. Casting the dictionary to an array yields only its private
	 * storage key, so every header would read as empty.
	 *
	 * @param array|object $headers Header container.
	 * @return array
	 */
	private static function headers_to_array( $headers ) {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all = $headers->getAll();

			return is_array( $all ) ? $all : array();
		}

		if ( $headers instanceof \Traversable ) {
			return iterator_to_array( $headers );
		}

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Whether the response is a 2xx success.
	 *
	 * @return bool
	 */
	public function is_ok() {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether the response is a redirect.
	 *
	 * @return bool
	 */
	public function is_redirect() {
		return in_array( $this->status, array( 301, 302, 303, 307, 308 ), true );
	}

	/**
	 * A header value.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public function header( $name ) {
		$name = strtolower( $name );
		return isset( $this->headers[ $name ] ) ? (string) $this->headers[ $name ] : '';
	}

	/**
	 * GitHub error message from the body, if present.
	 *
	 * @return string
	 */
	public function error_message() {
		if ( is_array( $this->data ) && isset( $this->data['message'] ) ) {
			return (string) $this->data['message'];
		}
		return '';
	}

	/**
	 * Whether the response indicates a rate limit.
	 *
	 * @return bool
	 */
	public function is_rate_limited() {
		if ( 429 === $this->status ) {
			return true;
		}

		if ( 403 === $this->status ) {
			$remaining = $this->header( 'x-ratelimit-remaining' );
			return '0' === $remaining || '' !== $this->header( 'retry-after' );
		}

		return false;
	}

	/**
	 * Seconds to wait before retrying, if GitHub told us.
	 *
	 * @return int
	 */
	public function retry_after() {
		$retry = (int) $this->header( 'retry-after' );
		if ( $retry > 0 ) {
			return $retry;
		}

		$reset = (int) $this->header( 'x-ratelimit-reset' );
		if ( $reset > 0 ) {
			return max( 1, $reset - time() );
		}

		return 60;
	}
}
