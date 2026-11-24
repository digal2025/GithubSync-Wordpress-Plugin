<?php
/**
 * Central GitHub REST API client.
 *
 * @package GitHubSync
 */

namespace GitHubSync\GitHub;

use GitHubSync\Support\ErrorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Every GitHub HTTP call goes through this class.
 */
final class GitHubApiClient {

	private const API_ROOT = 'https://api.github.com';

	/**
	 * Auth provider.
	 *
	 * @var GitHubAuth
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param GitHubAuth|null $auth Auth provider.
	 */
	public function __construct( ?GitHubAuth $auth = null ) {
		$this->auth = $auth ? $auth : new GitHubAuth();
	}

	/**
	 * Perform a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path beginning with "/" or absolute GitHub URL.
	 * @param array  $query  Query args.
	 * @param array  $extra  Extra wp_remote_* args.
	 * @return GitHubResponse
	 */
	public function request( $method, $path, array $query = array(), array $extra = array() ) {
		$token = $this->auth->get_token();

		if ( is_wp_error( $token ) ) {
			$response          = new GitHubResponse();
			$response->status  = 0;
			$response->data    = array( 'message' => $token->get_error_message() );
			$response->body    = $token->get_error_message();
			return $response;
		}

		$url = 0 === strpos( $path, 'http' ) ? $path : self::API_ROOT . $path;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array_merge(
			array(
				'method'      => strtoupper( $method ),
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => array(
					'Authorization'        => 'Bearer ' . $token,
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'GitHubSync/' . GITHUBSYNC_VERSION,
				),
			),
			$extra
		);

		return GitHubResponse::from_wp( wp_remote_request( $url, $args ) );
	}

	/**
	 * Convert a failed response into a WP_Error with a stable code.
	 *
	 * @param GitHubResponse $response Response.
	 * @param string         $context  Optional context for the message.
	 * @return \WP_Error
	 */
	public function to_error( GitHubResponse $response, $context = '' ) {
		if ( $response->is_rate_limited() ) {
			return new \WP_Error( ErrorCodes::GITHUB_RATE_LIMIT, ErrorCodes::message( ErrorCodes::GITHUB_RATE_LIMIT ) );
		}

		if ( 0 === $response->status ) {
			return new \WP_Error( ErrorCodes::GITHUB_NETWORK_ERROR, $response->body ? $response->body : ErrorCodes::message( ErrorCodes::GITHUB_NETWORK_ERROR ) );
		}

		if ( 401 === $response->status ) {
			return new \WP_Error( ErrorCodes::AUTH_INVALID, ErrorCodes::message( ErrorCodes::AUTH_INVALID ) );
		}

		if ( 403 === $response->status ) {
			return new \WP_Error( ErrorCodes::AUTH_PERMISSION_DENIED, ErrorCodes::message( ErrorCodes::AUTH_PERMISSION_DENIED ) );
		}

		if ( 404 === $response->status ) {
			return new \WP_Error( ErrorCodes::REPOSITORY_NOT_FOUND, ErrorCodes::message( ErrorCodes::REPOSITORY_NOT_FOUND ) );
		}

		if ( $response->decode_failed ) {
			return new \WP_Error( ErrorCodes::INTERNAL_ERROR, __( 'GitHub returned an unreadable response.', 'githubsync' ) );
		}

		return new \WP_Error(
			ErrorCodes::INTERNAL_ERROR,
			sprintf(
				/* translators: 1: HTTP status, 2: optional context. */
				__( 'GitHub API returned HTTP %1$d. %2$s', 'githubsync' ),
				$response->status,
				$context
			)
		);
	}

	/**
	 * Identity of the authenticated token.
	 *
	 * @return array|\WP_Error
	 */
	public function whoami() {
		$response = $this->request( 'GET', '/user' );

		if ( $response->is_ok() && is_array( $response->data ) ) {
			return array(
				'account'      => isset( $response->data['login'] ) ? (string) $response->data['login'] : '',
				'display_name' => isset( $response->data['name'] ) ? (string) $response->data['name'] : '',
			);
		}

		// App installation tokens cannot call /user; that is not an error.
		if ( 403 === $response->status || 404 === $response->status ) {
			return array(
				'account'      => '',
				'display_name' => '',
			);
		}

		return $this->to_error( $response );
	}

	/**
	 * Repositories accessible to the connection, one page at a time.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Page size.
	 * @return array|\WP_Error { items: array[], total: int, has_next: bool }
	 */
	public function repositories( $page = 1, $per_page = 50 ) {
		$connection = $this->auth->connection();

		if ( 'app' === $connection['mode'] ) {
			$path = '/installation/repositories';
		} else {
			$path = '/user/repos';
		}

		$query = array(
			'per_page' => (int) $per_page,
			'page'     => (int) $page,
		);

		if ( 'app' !== $connection['mode'] ) {
			$query['sort']        = 'updated';
			$query['affiliation'] = 'owner,collaborator,organization_member';
		}

		$response = $this->request( 'GET', $path, $query );

		if ( ! $response->is_ok() || ! is_array( $response->data ) ) {
			return $this->to_error( $response );
		}

		$items = array();

		if ( isset( $response->data['repositories'] ) && is_array( $response->data['repositories'] ) ) {
			$items = $response->data['repositories'];
			$total = isset( $response->data['total_count'] ) ? (int) $response->data['total_count'] : count( $items );
		} elseif ( is_array( $response->data ) ) {
			$items = $response->data;
			$total = count( $items );
		} else {
			return new \WP_Error( ErrorCodes::INTERNAL_ERROR, __( 'Unexpected repository list response.', 'githubsync' ) );
		}

		$normalised = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['full_name'] ) || ! isset( $item['id'] ) ) {
				continue;
			}

			$normalised[] = array(
				'id'             => (int) $item['id'],
				'full_name'      => (string) $item['full_name'],
				'name'           => isset( $item['name'] ) ? (string) $item['name'] : (string) $item['full_name'],
				'private'        => ! empty( $item['private'] ),
				'visibility'     => isset( $item['visibility'] ) ? (string) $item['visibility'] : ( ! empty( $item['private'] ) ? 'private' : 'public' ),
				'default_branch' => isset( $item['default_branch'] ) ? (string) $item['default_branch'] : '',
			);
		}

		$has_next = '' !== $response->header( 'link' ) && false !== strpos( $response->header( 'link' ), 'rel="next"' );

		return array(
			'items'    => $normalised,
			'total'    => $total,
			'has_next' => $has_next,
		);
	}

	/**
	 * Repository metadata by full name or ID.
	 *
	 * @param string $full_name owner/repo.
	 * @return array|\WP_Error
	 */
	public function repository( $full_name ) {
		$full_name = trim( (string) $full_name, '/' );

		if ( ! preg_match( '#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $full_name ) ) {
			return new \WP_Error( ErrorCodes::REPOSITORY_NOT_FOUND, __( 'Repository must be in owner/name form.', 'githubsync' ) );
		}

		$response = $this->request( 'GET', '/repos/' . $full_name );

		if ( ! $response->is_ok() || ! is_array( $response->data ) || empty( $response->data['id'] ) ) {
			return $this->to_error( $response );
		}

		return array(
			'id'             => (int) $response->data['id'],
			'full_name'      => (string) $response->data['full_name'],
			'private'        => ! empty( $response->data['private'] ),
			'visibility'     => isset( $response->data['visibility'] ) ? (string) $response->data['visibility'] : '',
			'default_branch' => isset( $response->data['default_branch'] ) ? (string) $response->data['default_branch'] : '',
		);
	}

	/**
	 * Branches for a repository.
	 *
	 * @param string $full_name owner/repo.
	 * @param int    $page      Page.
	 * @param int    $per_page  Page size.
	 * @return array|\WP_Error { items: array[], has_next: bool }
	 */
	public function branches( $full_name, $page = 1, $per_page = 100 ) {
		$full_name = trim( (string) $full_name, '/' );
		$response  = $this->request(
			'GET',
			'/repos/' . $full_name . '/branches',
			array(
				'per_page' => (int) $per_page,
				'page'     => (int) $page,
			)
		);

		if ( ! $response->is_ok() || ! is_array( $response->data ) ) {
			return $this->to_error( $response );
		}

		$items = array();

		foreach ( $response->data as $branch ) {
			if ( ! is_array( $branch ) || empty( $branch['name'] ) ) {
				continue;
			}
			$items[] = array(
				'name' => (string) $branch['name'],
				'sha'  => isset( $branch['commit']['sha'] ) ? (string) $branch['commit']['sha'] : '',
			);
		}

		$has_next = '' !== $response->header( 'link' ) && false !== strpos( $response->header( 'link' ), 'rel="next"' );

		return array(
			'items'    => $items,
			'has_next' => $has_next,
		);
	}

	/**
	 * Head commit SHA of a branch (immutable resolution).
	 *
	 * @param string $full_name owner/repo.
	 * @param string $branch    Branch name.
	 * @return string|\WP_Error
	 */
	public function branch_head_sha( $full_name, $branch ) {
		$full_name = trim( (string) $full_name, '/' );
		$response  = $this->request( 'GET', '/repos/' . $full_name . '/branches/' . rawurlencode( (string) $branch ) );

		if ( 404 === $response->status ) {
			return new \WP_Error( ErrorCodes::BRANCH_NOT_FOUND, ErrorCodes::message( ErrorCodes::BRANCH_NOT_FOUND ) );
		}

		if ( ! $response->is_ok() || ! is_array( $response->data ) || empty( $response->data['commit']['sha'] ) ) {
			return $this->to_error( $response );
		}

		$sha = (string) $response->data['commit']['sha'];

		if ( ! preg_match( '/^[a-f0-9]{40,64}$/i', $sha ) ) {
			return new \WP_Error( ErrorCodes::INTERNAL_ERROR, __( 'GitHub returned an invalid commit SHA.', 'githubsync' ) );
		}

		return strtolower( $sha );
	}

	/**
	 * Commit metadata (message) for display.
	 *
	 * @param string $full_name owner/repo.
	 * @param string $sha       Commit SHA.
	 * @return string
	 */
	public function commit_message( $full_name, $sha ) {
		$response = $this->request( 'GET', '/repos/' . trim( (string) $full_name, '/' ) . '/commits/' . rawurlencode( (string) $sha ) );

		if ( $response->is_ok() && is_array( $response->data ) && isset( $response->data['commit']['message'] ) ) {
			return sanitize_text_field( (string) $response->data['commit']['message'] );
		}

		return '';
	}

	/**
	 * Request the archive redirect URL for a specific commit.
	 *
	 * @param string $full_name owner/repo.
	 * @param string $sha       Commit SHA.
	 * @return string|\WP_Error Signed HTTPS URL.
	 */
	public function archive_url( $full_name, $sha ) {
		$path = '/repos/' . trim( (string) $full_name, '/' ) . '/zipball/' . rawurlencode( (string) $sha );

		$response = $this->request( 'GET', $path, array(), array( 'redirection' => 0 ) );

		if ( $response->is_redirect() ) {
			$location = $response->header( 'location' );

			if ( '' === $location ) {
				return new \WP_Error( ErrorCodes::ARCHIVE_INVALID, __( 'GitHub did not provide an archive location.', 'githubsync' ) );
			}

			return $this->validate_download_url( $location );
		}

		// Some configurations return the archive directly.
		if ( $response->is_ok() ) {
			return self::API_ROOT . $path;
		}

		return $this->to_error( $response );
	}

	/**
	 * Validate a download URL against the GitHub host allowlist.
	 *
	 * @param string $url URL.
	 * @return string|\WP_Error
	 */
	public function validate_download_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( ErrorCodes::ARCHIVE_INVALID, __( 'The archive URL is malformed.', 'githubsync' ) );
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return new \WP_Error( ErrorCodes::ARCHIVE_INVALID, __( 'The archive URL is not HTTPS.', 'githubsync' ) );
		}

		$allowed = (array) apply_filters(
			'githubsync_archive_hosts',
			array(
				'api.github.com',
				'codeload.github.com',
				'github.com',
				'objects.githubusercontent.com',
				'github-releases.githubusercontent.com',
			)
		);

		$host = strtolower( $parts['host'] );

		foreach ( $allowed as $candidate ) {
			$candidate = strtolower( (string) $candidate );
			if ( $host === $candidate || ( '.' === substr( $candidate, 0, 1 ) && substr( $host, -strlen( $candidate ) ) === $candidate ) ) {
				return $url;
			}
		}

		return new \WP_Error( ErrorCodes::ARCHIVE_INVALID, __( 'The archive URL host is not allowed.', 'githubsync' ) );
	}
}
