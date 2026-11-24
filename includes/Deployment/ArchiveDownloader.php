<?php
/**
 * Streaming archive download with size enforcement.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Domain\Exceptions\GitHubSyncException;
use GitHubSync\GitHub\GitHubApiClient;
use GitHubSync\Support\ErrorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a GitHub commit archive to disk without loading it into memory.
 */
final class ArchiveDownloader {

	/**
	 * Download an archive URL to a file.
	 *
	 * @param string $url       Validated HTTPS URL.
	 * @param string $target    Destination file path.
	 * @param int    $max_bytes Maximum allowed size.
	 * @return true
	 * @throws GitHubSyncException On failure or oversize.
	 */
	public function download( $url, $target, $max_bytes ) {
		if ( function_exists( 'curl_init' ) ) {
			$this->download_with_curl( $url, $target, $max_bytes );
		} else {
			$this->download_with_wp_http( $url, $target, $max_bytes );
		}

		clearstatcache( true, $target );

		if ( ! is_file( $target ) || 0 === (int) filesize( $target ) ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'Downloaded archive is empty.' );
		}

		if ( (int) filesize( $target ) > $max_bytes ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_TOO_LARGE );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'The PHP zip extension is required.' );
		}

		$zip  = new \ZipArchive();
		$open = $zip->open( $target );

		if ( true !== $open || 0 === $zip->numFiles ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'The downloaded file is not a readable ZIP archive.' );
		}

		$zip->close();

		return true;
	}

	/**
	 * Fetch a commit archive for a repository.
	 *
	 * @param GitHubApiClient $client    API client.
	 * @param string          $full_name owner/repo.
	 * @param string          $sha       Immutable commit SHA.
	 * @param string          $target    Destination file.
	 * @param int             $max_bytes Maximum size.
	 * @return true
	 * @throws GitHubSyncException
	 */
	public function fetch( GitHubApiClient $client, $full_name, $sha, $target, $max_bytes ) {
		$url = $client->archive_url( $full_name, $sha );

		if ( is_wp_error( $url ) ) {
			throw new GitHubSyncException( $url->get_error_code(), $url->get_error_message() );
		}

		return $this->download( $url, $target, $max_bytes );
	}

	/**
	 * curl streaming download that aborts when the size limit is exceeded.
	 *
	 * @param string $url       URL.
	 * @param string $target    Target file.
	 * @param int    $max_bytes Maximum size.
	 * @return void
	 * @throws GitHubSyncException
	 */
	private function download_with_curl( $url, $target, $max_bytes ) {
		$handle = @fopen( $target, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			throw new GitHubSyncException( ErrorCodes::INTERNAL_ERROR, 'Could not open the download file.' );
		}

		$written   = 0;
		$too_large = false;

		$curl = curl_init( $url );

		if ( false === $curl ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new GitHubSyncException( ErrorCodes::GITHUB_NETWORK_ERROR, 'Could not initialise curl.' );
		}

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_FILE            => $handle,
				CURLOPT_FOLLOWLOCATION  => true,
				CURLOPT_MAXREDIRS       => 3,
				CURLOPT_CONNECTTIMEOUT  => 15,
				CURLOPT_TIMEOUT         => 300,
				CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
				CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_USERAGENT       => 'GitHubSync/' . GITHUBSYNC_VERSION,
				CURLOPT_FAILONERROR     => true,
				CURLOPT_BUFFERSIZE      => 65536,
			)
		);

		// Enforce the limit while bytes arrive rather than after they are written.
		curl_setopt(
			$curl,
			CURLOPT_WRITEFUNCTION,
			static function ( $curl_handle, $chunk ) use ( $handle, &$written, &$too_large, $max_bytes ) {
				$length   = strlen( $chunk );
				$written += $length;

				if ( $written > $max_bytes ) {
					$too_large = true;
					return 0; // Abort the transfer.
				}

				$result = fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

				return false === $result ? 0 : $result;
			}
		);

		$ok     = curl_exec( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$errno  = curl_errno( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		$error  = curl_error( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		curl_close( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $too_large ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_TOO_LARGE );
		}

		if ( false === $ok || 0 !== $errno ) {
			throw new GitHubSyncException(
				ErrorCodes::GITHUB_NETWORK_ERROR,
				sprintf( 'Archive download failed (%d).', (int) $errno )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new GitHubSyncException(
				ErrorCodes::ARCHIVE_INVALID,
				sprintf( 'Archive download returned HTTP %d.', $status )
			);
		}

		unset( $error );
	}

	/**
	 * Fallback streaming download through the WordPress HTTP API.
	 *
	 * @param string $url       URL.
	 * @param string $target    Target file.
	 * @param int    $max_bytes Maximum size.
	 * @return void
	 * @throws GitHubSyncException
	 */
	private function download_with_wp_http( $url, $target, $max_bytes ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'redirection' => 3,
				'stream'      => true,
				'filename'    => $target,
				'headers'     => array( 'User-Agent' => 'GitHubSync/' . GITHUBSYNC_VERSION ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new GitHubSyncException( ErrorCodes::GITHUB_NETWORK_ERROR, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, sprintf( 'Archive download returned HTTP %d.', $status ) );
		}

		clearstatcache( true, $target );

		if ( is_file( $target ) && (int) filesize( $target ) > $max_bytes ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_TOO_LARGE );
		}
	}
}
