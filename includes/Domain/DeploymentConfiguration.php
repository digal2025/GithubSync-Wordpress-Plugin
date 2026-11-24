<?php
/**
 * Deployment configuration value object.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Domain;

use GitHubSync\Security\FileFilter;
use GitHubSync\Security\InputValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view over a deployment row with typed accessors.
 */
final class DeploymentConfiguration {

	/**
	 * Raw row.
	 *
	 * @var array
	 */
	private $row;

	/**
	 * Constructor.
	 *
	 * @param array $row Database row.
	 */
	public function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * Load from a row, or null.
	 *
	 * @param array|null $row Row.
	 * @return self|null
	 */
	public static function from_row( $row ) {
		return is_array( $row ) ? new self( $row ) : null;
	}

	/**
	 * Raw row.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->row;
	}

	/**
	 * Magic getter for column access.
	 *
	 * @param string $key Column.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return isset( $this->row[ $key ] ) ? $this->row[ $key ] : $default;
	}

	/**
	 * ID.
	 *
	 * @return int
	 */
	public function id() {
		return (int) $this->get( 'id', 0 );
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name() {
		return (string) $this->get( 'name', '' );
	}

	/**
	 * Repository full name (owner/repo).
	 *
	 * @return string
	 */
	public function repository() {
		return (string) $this->get( 'repository_full_name', '' );
	}

	/**
	 * Repository ID.
	 *
	 * @return int
	 */
	public function repository_id() {
		return (int) $this->get( 'github_repository_id', 0 );
	}

	/**
	 * Branch.
	 *
	 * @return string
	 */
	public function branch() {
		return (string) $this->get( 'branch', '' );
	}

	/**
	 * Source path inside the repository ('' = root).
	 *
	 * @return string
	 */
	public function source_path() {
		return (string) $this->get( 'source_path', '' );
	}

	/**
	 * Absolute destination path.
	 *
	 * @return string
	 */
	public function destination_path() {
		return (string) $this->get( 'destination_path', '' );
	}

	/**
	 * Destination slug.
	 *
	 * @return string
	 */
	public function destination_slug() {
		return (string) $this->get( 'destination_slug', '' );
	}

	/**
	 * Deployment type.
	 *
	 * @return string
	 */
	public function type() {
		return (string) $this->get( 'deployment_type', DestinationPolicy::TYPE_PLUGIN );
	}

	/**
	 * Whether automatic deployment is enabled.
	 *
	 * @return bool
	 */
	public function auto_deploy() {
		return (bool) $this->get( 'auto_deploy', 0 );
	}

	/**
	 * Whether manual deployment is enabled.
	 *
	 * @return bool
	 */
	public function manual_deploy() {
		return (bool) $this->get( 'manual_deploy', 1 );
	}

	/**
	 * Whether adoption of existing content was approved.
	 *
	 * @return bool
	 */
	public function adopt_existing() {
		return (bool) $this->get( 'adopt_existing', 0 );
	}

	/**
	 * Configuration status.
	 *
	 * @return string
	 */
	public function status() {
		return (string) $this->get( 'status', DeploymentStatus::ACTIVE );
	}

	/**
	 * Whether the configuration accepts deployments.
	 *
	 * @return bool
	 */
	public function is_active() {
		return DeploymentStatus::ACTIVE === $this->status();
	}

	/**
	 * Webhook token.
	 *
	 * @return string
	 */
	public function webhook_token() {
		return (string) $this->get( 'webhook_token', '' );
	}

	/**
	 * Last deployed commit.
	 *
	 * @return string
	 */
	public function last_deployed_sha() {
		return (string) $this->get( 'last_deployed_sha', '' );
	}

	/**
	 * Exclude patterns as an array.
	 *
	 * @return string[]
	 */
	public function exclude_patterns() {
		$raw = $this->get( 'exclude_patterns', '' );
		if ( is_string( $raw ) && '' !== $raw && '[' === substr( $raw, 0, 1 ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return InputValidator::pattern_list( (string) $raw );
	}

	/**
	 * File filter for this configuration.
	 *
	 * @return FileFilter
	 */
	public function file_filter() {
		return new FileFilter( $this->exclude_patterns() );
	}
}
