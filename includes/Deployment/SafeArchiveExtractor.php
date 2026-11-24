<?php
/**
 * Safe ZIP extraction (Zip Slip, symlink and bomb defence).
 *
 * @package GitHubSync
 */

namespace GitHubSync\Deployment;

use GitHubSync\Domain\Exceptions\GitHubSyncException;
use GitHubSync\Security\PathGuard;
use GitHubSync\Support\ErrorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts a GitHub archive into a fresh staging directory after validating
 * every entry name, type and size.
 */
final class SafeArchiveExtractor {

	/**
	 * Extract an archive.
	 *
	 * @param string $archive Absolute path to the ZIP.
	 * @param string $target  Absolute extraction root (must be empty/new).
	 * @param array  $limits  { max_files, max_file_bytes, max_total_bytes }.
	 * @return array { files: int, bytes: int, prefix: string }
	 * @throws GitHubSyncException
	 */
	public function extract( $archive, $target, array $limits ) {
		$limits = wp_parse_args(
			$limits,
			array(
				'max_files'       => 10000,
				'max_file_bytes'  => 50 * MB_IN_BYTES,
				'max_total_bytes' => 300 * MB_IN_BYTES,
			)
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'The PHP zip extension is required.' );
		}

		$zip  = new \ZipArchive();
		$open = $zip->open( $archive );

		if ( true !== $open ) {
			throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'Could not open the archive.' );
		}

		try {
			$prefix = $this->detect_prefix( $zip );

			$result = $this->extract_entries( $zip, $target, $limits, $prefix );
		} finally {
			$zip->close();
		}

		return $result;
	}

	/**
	 * GitHub zipballs wrap everything in `owner-repo-sha/`. Detect and strip it.
	 *
	 * @param \ZipArchive $zip Archive.
	 * @return string Prefix including the trailing slash, or ''.
	 */
	private function detect_prefix( \ZipArchive $zip ) {
		$first_segments = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$name = str_replace( '\\', '/', $name );
			$name = ltrim( $name, '/' );
			$pos  = strpos( $name, '/' );

			$first_segments[] = false === $pos ? $name : substr( $name, 0, $pos );
		}

		$first_segments = array_values( array_unique( $first_segments ) );

		if ( 1 !== count( $first_segments ) ) {
			return '';
		}

		$segment = $first_segments[0];

		// GitHub appends the commit SHA to the wrapper directory name.
		if ( preg_match( '/-[0-9a-f]{7,64}$/i', $segment ) ) {
			return $segment . '/';
		}

		return '';
	}

	/**
	 * Validate and extract each entry.
	 *
	 * @param \ZipArchive $zip    Archive.
	 * @param string      $target Extraction root.
	 * @param array       $limits Limits.
	 * @param string      $prefix Wrapper prefix to strip.
	 * @return array
	 * @throws GitHubSyncException
	 */
	private function extract_entries( \ZipArchive $zip, $target, array $limits, $prefix ) {
		$files        = 0;
		$bytes        = 0;
		$actual_bytes = 0;
		$prefix_len   = strlen( $prefix );
		$target_norm  = rtrim( wp_normalize_path( $target ), '/' );
		$symlink_mode = 0120000;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
				throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'Unreadable archive entry.' );
			}

			$name      = (string) $stat['name'];
			$is_dir    = '/' === substr( $name, -1 ) || ( isset( $stat['size'] ) && 0 === (int) $stat['size'] && $this->is_directory_entry( $zip, $i ) );
			$normalise = str_replace( '\\', '/', $name );

			// Reject null bytes outright.
			if ( false !== strpos( $normalise, "\0" ) ) {
				throw new GitHubSyncException( ErrorCodes::ARCHIVE_PATH_INVALID, 'Null byte in archive entry.' );
			}

			// Symlink / special file detection on UNIX-created archives.
			$opsys = 0;
			$attr  = 0;
			$zip->getExternalAttributesIndex( $i, $opsys, $attr );

			if ( \ZipArchive::OPSYS_UNIX === $opsys ) {
				$mode = ( $attr >> 16 ) & 0170000;
				if ( $symlink_mode === $mode ) {
					throw new GitHubSyncException( ErrorCodes::ARCHIVE_PATH_INVALID, 'Archive contains a symlink.' );
				}
				// Block device, char device, fifo, socket.
				if ( in_array( $mode, array( 0060000, 0020000, 0010000, 0140000 ), true ) ) {
					throw new GitHubSyncException( ErrorCodes::ARCHIVE_PATH_INVALID, 'Archive contains a special file.' );
				}
			}

			$relative = $normalise;

			if ( '' !== $prefix && 0 === strpos( $relative, $prefix ) ) {
				$relative = substr( $relative, $prefix_len );
			} elseif ( '' !== $prefix && $relative . '/' === $prefix ) {
				continue; // The wrapper directory itself.
			}

			$relative = rtrim( $relative, '/' );

			if ( '' === $relative ) {
				continue;
			}

			if ( ! PathGuard::is_safe_relative( $relative ) ) {
				throw new GitHubSyncException( ErrorCodes::ARCHIVE_PATH_INVALID, 'Unsafe path in archive.' );
			}

			$destination = PathGuard::join( $target, $relative );

			if ( false === $destination || ! PathGuard::is_inside( wp_normalize_path( $destination ), $target_norm ) ) {
				throw new GitHubSyncException( ErrorCodes::ARCHIVE_PATH_INVALID, 'Archive path escaped the staging root.' );
			}

			if ( $is_dir ) {
				if ( ! wp_mkdir_p( $destination ) ) {
					throw new GitHubSyncException( ErrorCodes::INTERNAL_ERROR, 'Could not create an archive directory.' );
				}
				continue;
			}

			$files++;

			if ( $files > (int) $limits['max_files'] ) {
				throw new GitHubSyncException( ErrorCodes::LIMIT_EXCEEDED, 'Archive contains too many files.' );
			}

			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;

			if ( $size > (int) $limits['max_file_bytes'] ) {
				throw new GitHubSyncException( ErrorCodes::LIMIT_EXCEEDED, 'Archive contains an oversized file.' );
			}

			$bytes += $size;

			if ( $bytes > (int) $limits['max_total_bytes'] ) {
				throw new GitHubSyncException( ErrorCodes::LIMIT_EXCEEDED, 'Archive expands beyond the allowed size.' );
			}

			$source_name = (string) $stat['name'];

			if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
				throw new GitHubSyncException( ErrorCodes::INTERNAL_ERROR, 'Could not create a parent directory.' );
			}

			$in = $zip->getStream( $source_name );

			if ( false === $in ) {
				throw new GitHubSyncException( ErrorCodes::ARCHIVE_INVALID, 'Could not read an archive entry.' );
			}

			$out = @fopen( $destination, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( false === $out ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				throw new GitHubSyncException( ErrorCodes::INTERNAL_ERROR, 'Could not write an extracted file.' );
			}

			$copied = stream_copy_to_stream( $in, $out );

			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			if ( false === $copied || $copied > (int) $limits['max_file_bytes'] ) {
				throw new GitHubSyncException( ErrorCodes::LIMIT_EXCEEDED, 'Extracted file exceeded the size limit.' );
			}

			$actual_bytes += (int) $copied;

			if ( $actual_bytes > (int) $limits['max_total_bytes'] ) {
				throw new GitHubSyncException( ErrorCodes::LIMIT_EXCEEDED, 'Extracted data exceeded the total size limit.' );
			}

			@chmod( $destination, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return array(
			'files'  => $files,
			'bytes'  => $bytes,
			'prefix' => $prefix,
		);
	}

	/**
	 * Whether a zero-size entry is a directory record.
	 *
	 * @param \ZipArchive $zip   Archive.
	 * @param int         $index Index.
	 * @return bool
	 */
	private function is_directory_entry( \ZipArchive $zip, $index ) {
		$name = $zip->getNameIndex( $index );
		return is_string( $name ) && '/' === substr( $name, -1 );
	}
}
