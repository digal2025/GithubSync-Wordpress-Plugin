<?php
/**
 * Filesystem primitives with containment guards.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Filesystem;

use GitHubSync\Security\PathGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over PHP filesystem calls. Every destructive operation
 * requires an explicit root so a caller bug cannot delete outside it.
 */
final class FilesystemAdapter {

	/**
	 * Create a directory recursively.
	 *
	 * @param string $dir  Absolute path.
	 * @param int    $mode Permission bits.
	 * @return bool
	 */
	public function ensure_dir( $dir, $mode = 0755 ) {
		if ( is_dir( $dir ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		@chmod( $dir, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return is_dir( $dir );
	}

	/**
	 * Whether a directory (or its parent, if missing) is writable.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public function is_writable( $dir ) {
		if ( is_dir( $dir ) ) {
			return is_writable( $dir );
		}

		$parent = dirname( $dir );

		return is_dir( $parent ) && is_writable( $parent );
	}

	/**
	 * Copy a single file with conservative permissions.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @param int    $mode   Permission bits.
	 * @return bool
	 */
	public function copy_file( $source, $target, $mode = 0644 ) {
		if ( ! is_file( $source ) || is_link( $source ) ) {
			return false;
		}

		if ( ! $this->ensure_dir( dirname( $target ) ) ) {
			return false;
		}

		if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		@chmod( $target, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return true;
	}

	/**
	 * Write a file.
	 *
	 * @param string $target  Target path.
	 * @param string $content Content.
	 * @param int    $mode    Permission bits.
	 * @return bool
	 */
	public function write_file( $target, $content, $mode = 0644 ) {
		if ( ! $this->ensure_dir( dirname( $target ) ) ) {
			return false;
		}

		$written = @file_put_contents( $target, $content, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( false === $written ) {
			return false;
		}

		@chmod( $target, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return true;
	}

	/**
	 * Recursively list files as paths relative to a root. Symlinks are
	 * skipped (never followed).
	 *
	 * @param string $root Absolute root.
	 * @return string[]|\WP_Error
	 */
	public function files( $root ) {
		if ( ! is_dir( $root ) ) {
			return new \WP_Error( 'FS_MISSING', __( 'Directory does not exist.', 'githubsync' ) );
		}

		$out      = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( $file->isLink() || ! $file->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );
			$base = rtrim( wp_normalize_path( $root ), '/' ) . '/';

			if ( 0 !== strpos( $path, $base ) ) {
				continue;
			}

			$relative = substr( $path, strlen( $base ) );

			if ( ! PathGuard::is_safe_relative( $relative ) ) {
				continue;
			}

			$out[] = $relative;
		}

		sort( $out );

		return $out;
	}

	/**
	 * Copy a tree, returning counts.
	 *
	 * @param string $source Absolute source.
	 * @param string $target Absolute target.
	 * @return array|\WP_Error { files: int, bytes: int }
	 */
	public function copy_tree( $source, $target ) {
		$files = $this->files( $source );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		if ( ! $this->ensure_dir( $target ) ) {
			return new \WP_Error( 'FS_WRITE', __( 'Could not create the target directory.', 'githubsync' ) );
		}

		$bytes = 0;

		foreach ( $files as $relative ) {
			$from = rtrim( $source, '/' ) . '/' . $relative;
			$to   = rtrim( $target, '/' ) . '/' . $relative;

			if ( ! PathGuard::is_inside( $to, $target ) ) {
				return new \WP_Error( 'FS_PATH', __( 'A file path escaped the target directory.', 'githubsync' ) );
			}

			if ( ! $this->copy_file( $from, $to ) ) {
				return new \WP_Error( 'FS_COPY', sprintf( /* translators: %s: relative path. */ __( 'Could not copy %s.', 'githubsync' ), $relative ) );
			}

			$bytes += (int) filesize( $to );
		}

		return array(
			'files' => count( $files ),
			'bytes' => $bytes,
		);
	}

	/**
	 * File count and total bytes for a tree.
	 *
	 * @param string $root Absolute root.
	 * @return array { files: int, bytes: int }
	 */
	public function stats( $root ) {
		$files = $this->files( $root );

		if ( is_wp_error( $files ) ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}

		$bytes = 0;

		foreach ( $files as $relative ) {
			$bytes += (int) filesize( rtrim( $root, '/' ) . '/' . $relative );
		}

		return array(
			'files' => count( $files ),
			'bytes' => $bytes,
		);
	}

	/**
	 * SHA-1 map of a tree for backup verification.
	 *
	 * @param string $root Absolute root.
	 * @param int    $max  Maximum files to hash.
	 * @return array|\WP_Error
	 */
	public function hash_tree( $root, $max = 20000 ) {
		$files = $this->files( $root );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		if ( count( $files ) > $max ) {
			return new \WP_Error( 'FS_TOO_MANY', __( 'Too many files to verify by hash.', 'githubsync' ) );
		}

		$hashes = array();

		foreach ( $files as $relative ) {
			$hash = @sha1_file( rtrim( $root, '/' ) . '/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_sha1

			if ( false === $hash ) {
				return new \WP_Error( 'FS_HASH', __( 'Could not hash a file.', 'githubsync' ) );
			}

			$hashes[ $relative ] = $hash;
		}

		return $hashes;
	}

	/**
	 * Recursively delete a tree. The path must be strictly inside $root and
	 * must not be the root itself.
	 *
	 * @param string $path Absolute path to delete.
	 * @param string $root Absolute containment root.
	 * @return bool
	 */
	public function remove_tree( $path, $root ) {
		$canonical_root = realpath( $root );
		$canonical      = realpath( $path );

		if ( false === $canonical_root || false === $canonical ) {
			return false;
		}

		$canonical_root = wp_normalize_path( $canonical_root );
		$canonical      = wp_normalize_path( $canonical );

		if ( $canonical === $canonical_root ) {
			return false;
		}

		if ( ! PathGuard::is_inside( $canonical, $canonical_root ) ) {
			return false;
		}

		if ( is_link( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( is_file( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! is_dir( $path ) ) {
			return true;
		}

		$entries = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $entries ) ) {
			return false;
		}

		$ok = true;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$child = $path . '/' . $entry;

			if ( ! $this->remove_tree( $child, $root ) ) {
				$ok = false;
			}
		}

		return $ok && @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Rename (same-filesystem move).
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 * @return bool
	 */
	public function move( $from, $to ) {
		return @rename( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Free disk space in bytes, or -1 when unknown.
	 *
	 * @param string $path Existing path.
	 * @return int
	 */
	public function free_space( $path ) {
		$probe = is_dir( $path ) ? $path : dirname( $path );

		if ( ! function_exists( 'disk_free_space' ) ) {
			return -1;
		}

		$free = @disk_free_space( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $free ? -1 : (int) $free;
	}
}
