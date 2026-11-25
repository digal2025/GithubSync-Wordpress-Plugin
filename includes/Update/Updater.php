<?php
/**
 * Self-update from GitHub Releases.
 *
 * @package GitHubSync
 */

namespace GitHubSync\Update;

use GitHubSync\Security\Capability;
use GitHubSync\Support\ErrorCodes;
use GitHubSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Teaches WordPress about GitHubSync releases published on GitHub.
 *
 * WordPress only knows about plugins from WordPress.org, so this class answers
 * for the plugin on three core filters:
 *
 *  - pre_set_site_transient_update_plugins: offer a newer release.
 *  - plugins_api (action=plugin_information): render the update details modal.
 *  - upgrader_source_selection: make sure the package's top folder matches the
 *    installed plugin folder before WordPress swaps it in.
 *
 * The package itself is downloaded by WordPress with no credentials, so the
 * `download_url` must be reachable anonymously. The release workflow uploads a
 * copy of `githubsync.zip` to the `latest-updates` tag in the public
 * "releases" repository and points the manifest at that asset.
 *
 * Everything is fail-open: any problem leaves WordPress's normal behaviour
 * (and the manual "Upload Plugin" screen) intact.
 */
final class Updater {

	/**
	 * Option holding the last successful check (used for the settings screen).
	 */
	public const STATUS_OPTION = 'githubsync_update_status';

	/**
	 * Option holding the release manifest URL.
	 */
	public const URL_OPTION = 'githubsync_update_url';

	/**
	 * Manifest schema we understand.
	 */
	/**
	 * How long a fetched manifest is reused (seconds).
	 */
	public const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * WordPress core hook that reads the plugin update list.
	 */
	public const CHECK_HOOK = 'githubsync_update_check';

	/**
	 * Update status slugs (used by the settings screen).
	 */
	public const STATE_CURRENT      = 'current';
	public const STATE_ERROR        = 'error';
	public const STATE_UNCONFIGURED = 'unconfigured';

	/**
	 * Register the WordPress filters.
	 *
	 * Safe to call on every request: nothing here does I/O until WordPress
	 * actually asks for the plugin update list.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'filter_source_selection' ), 20, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( GITHUBSYNC_FILE ), array( __CLASS__, 'action_links' ) );

		add_action( self::CHECK_HOOK, array( __CLASS__, 'run_scheduled_check' ) );
	}

	/**
	 * Whether self-updates are active.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( defined( 'GITHUBSYNC_DISABLE_SELF_UPDATE' ) && GITHUBSYNC_DISABLE_SELF_UPDATE ) {
			return false;
		}

		return 1 === (int) Settings::get( 'self_update_enabled', 1 );
	}

	/**
	 * Whether a newer release is already in WordPress's update list.
	 *
	 * @return bool
	 */
	public static function has_pending_update() {
		if ( ! self::enabled() ) {
			return false;
		}

		$pending = get_site_transient( 'update_plugins' );

		if ( ! is_object( $pending ) || empty( $pending->response ) || ! is_array( $pending->response ) ) {
			return false;
		}

		$entry = $pending->response[ plugin_basename( GITHUBSYNC_FILE ) ] ?? null;

		return is_object( $entry ) && ! empty( $entry->new_version ) && self::is_newer( $entry->new_version );
	}

	/**
	 * Why self-updates are not running, for the settings screen.
	 *
	 * @return string Empty when enabled.
	 */
	public static function disabled_reason() {
		if ( defined( 'GITHUBSYNC_DISABLE_SELF_UPDATE' ) && GITHUBSYNC_DISABLE_SELF_UPDATE ) {
			return __( 'Self-updates are switched off by the GITHUBSYNC_DISABLE_SELF_UPDATE constant in wp-config.php.', 'githubsync' );
		}

		if ( ! self::enabled() ) {
			return __( 'Self-updates are switched off. GitHubSync will only change when you upload a release zip yourself.', 'githubsync' );
		}

		return '';
	}

	/**
	 * Manifest URL, or '' when not configured.
	 *
	 * @return string
	 */
	public static function manifest_url() {
		$url = (string) get_option( self::URL_OPTION, '' );

		if ( '' === $url && defined( 'GITHUBSYNC_UPDATE_URL' ) && is_string( GITHUBSYNC_UPDATE_URL ) ) {
			$url = GITHUBSYNC_UPDATE_URL;
		}

		return esc_url_raw( trim( $url ) );
	}

	/**
	 * Store the manifest URL.
	 *
	 * @param string $url URL ('' to clear).
	 * @return void
	 */
	public static function save_manifest_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );

		if ( '' !== $url && 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			$url = '';
		}

		if ( '' === $url ) {
			delete_option( self::URL_OPTION );
		} else {
			update_option( self::URL_OPTION, $url, false );
		}

		delete_transient( self::cache_key() );
	}

	/**
	 * Normalise a version string for comparison.
	 *
	 * @param string $version Raw version.
	 * @return string
	 */
	public static function normalize_version( $version ) {
		return ltrim( trim( (string) $version ), 'vV' );
	}

	/**
	 * Whether a remote version is newer than the running one.
	 *
	 * @param string $remote  Remote version.
	 * @param string $current Currently installed version.
	 * @return bool
	 */
	public static function is_newer( $remote, $current = GITHUBSYNC_VERSION ) {
		$remote  = self::normalize_version( $remote );
		$current = self::normalize_version( $current );

		return '' !== $remote && version_compare( $remote, $current, '>' );
	}

	/**
	 * Validate a decoded manifest.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|\WP_Error
	 */
	public static function validate_manifest( $data ) {
		if ( ! is_array( $data ) ) {
			return new \WP_Error( ErrorCodes::UPDATE_MANIFEST_INVALID, __( 'The update manifest is not a JSON object.', 'githubsync' ) );
		}

		$missing = array();

		foreach ( array( 'version', 'download_url', 'sha256' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === trim( $data[ $key ] ) ) {
				$missing[] = $key;
			}
		}

		if ( $missing ) {
			return new \WP_Error(
				ErrorCodes::UPDATE_MANIFEST_INVALID,
				sprintf(
					/* translators: %s: comma separated field names. */
					__( 'The update manifest is missing: %s.', 'githubsync' ),
					implode( ', ', $missing )
				)
			);
		}

		$version = self::normalize_version( $data['version'] );

		if ( ! preg_match( '/^\d+(\.\d+)*(-[A-Za-z0-9.]+)?$/', $version ) ) {
			return new \WP_Error( ErrorCodes::UPDATE_MANIFEST_INVALID, __( 'The update manifest has an invalid version number.', 'githubsync' ) );
		}

		// Packages are downloaded by WordPress without credentials, so only
		// plain HTTPS over the public GitHub host is accepted.
		$host = strtolower( (string) wp_parse_url( $data['download_url'], PHP_URL_HOST ) );

		if ( 'https' !== wp_parse_url( $data['download_url'], PHP_URL_SCHEME ) || ! in_array( $host, array( 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' ), true ) ) {
			return new \WP_Error( ErrorCodes::UPDATE_MANIFEST_INVALID, __( 'The update package URL must be an https://github.com release download.', 'githubsync' ) );
		}

		// GitHub reports "sha256:<hex>"; a bare 64-character digest is also fine.
		$sha = preg_replace( '/^sha256:/i', '', trim( $data['sha256'] ) );

		if ( ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/i', $sha ) ) {
			return new \WP_Error( ErrorCodes::UPDATE_MANIFEST_INVALID, __( 'The update manifest has an invalid SHA-256 digest.', 'githubsync' ) );
		}

		$slug = isset( $data['slug'] ) && is_string( $data['slug'] ) && '' !== $data['slug'] ? sanitize_key( $data['slug'] ) : 'githubsync';

		return array(
			'version'      => $version,
			'download_url' => $data['download_url'],
			'sha256'       => strtolower( $sha ),
			'slug'         => $slug,
			'requires'     => isset( $data['requires'] ) ? (string) $data['requires'] : '',
			'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '',
			'homepage'     => isset( $data['homepage'] ) ? (string) $data['homepage'] : '',
			'changelog'    => isset( $data['changelog'] ) ? (string) $data['changelog'] : '',
			'notes'        => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			'released_at'  => isset( $data['released_at'] ) ? (string) $data['released_at'] : '',
			'fetched_at'   => time(),
		);
	}

	/**
	 * Fetch (and cache) the manifest.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|\WP_Error
	 */
	public static function get_release( $force = false ) {
		$url = self::manifest_url();

		if ( '' === $url ) {
			return new \WP_Error( ErrorCodes::UPDATE_NOT_CONFIGURED, __( 'No update manifest URL is configured.', 'githubsync' ) );
		}

		if ( ! $force ) {
			$cached = get_transient( self::cache_key() );
			if ( is_array( $cached ) && isset( $cached['version'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'headers'     => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'GitHubSync/' . GITHUBSYNC_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( ErrorCodes::GITHUB_NETWORK_ERROR, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return new \WP_Error(
				ErrorCodes::UPDATE_MANIFEST_INVALID,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The update manifest could not be downloaded (HTTP %d). Private repositories must publish it as a public release asset.', 'githubsync' ),
					$status
				)
			);
		}

		// The manifest is plain JSON; anything unparsable is rejected below.
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new \WP_Error( ErrorCodes::UPDATE_MANIFEST_INVALID, __( 'The update manifest is not valid JSON.', 'githubsync' ) );
		}

		$manifest = self::validate_manifest( $decoded );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		set_transient( self::cache_key(), $manifest, self::CACHE_TTL );

		return $manifest;
	}

	/**
	 * Drop the cached manifest.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::cache_key() );
	}

	/**
	 * Manifest cache key.
	 *
	 * @return string
	 */
	private static function cache_key() {
		return 'githubsync_update_' . substr( md5( self::manifest_url() ), 0, 12 );
	}

	/**
	 * Ask WordPress to rebuild its plugin update list now (rate limited).
	 *
	 * @param bool $throttle Respect a 5 minute floor between forced checks.
	 * @return void
	 */
	public static function request_check( $throttle = true ) {
		$last = (int) get_option( 'githubsync_update_checked_at', 0 );

		if ( $throttle && $last > time() - 5 * MINUTE_IN_SECONDS ) {
			return;
		}

		update_option( 'githubsync_update_checked_at', time(), false );

		wp_clean_plugins_cache( false );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * The plugin row entry WordPress expects.
	 *
	 * @param array $release Validated manifest.
	 * @return object
	 */
	public static function update_response( array $release ) {
		return (object) array(
			'slug'          => 'githubsync',
			'plugin'        => plugin_basename( GITHUBSYNC_FILE ),
			'new_version'   => $release['version'],
			'url'           => '' !== $release['homepage'] ? $release['homepage'] : 'https://github.com/digal2025/GithubSync-Wordpress-Plugin',
			'package'       => $release['download_url'],
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => $release['tested'],
			'requires_php'  => $release['requires'],
			'compatibility' => new \stdClass(),
		);
	}

	/**
	 * Add the release to the plugin update list.
	 *
	 * @param mixed $transient update_plugins transient.
	 * @return mixed
	 */
	public static function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! self::enabled() ) {
			return $transient;
		}

		$release = self::get_release();

		if ( is_wp_error( $release ) ) {
			return $transient;
		}

		$basename = plugin_basename( GITHUBSYNC_FILE );

		$transient->checked[ $basename ] = GITHUBSYNC_VERSION;

		if ( self::is_newer( $release['version'] ) ) {
			$transient->response[ $basename ] = self::update_response( $release );
			unset( $transient->no_update[ $basename ] );
		} elseif ( isset( $transient->response[ $basename ] ) ) {
			// A stale entry from an earlier check must not survive a downgrade.
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Render the "View version details" modal from the manifest.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function filter_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || 'githubsync' !== $args->slug ) {
			return $result;
		}

		if ( false !== $result || ! self::enabled() ) {
			return $result;
		}

		$release = self::get_release();

		if ( is_wp_error( $release ) || ! self::is_newer( $release['version'] ) ) {
			return $result;
		}

		$sections = array(
			'description' => '' !== $release['notes'] ? wp_kses_post( wpautop( $release['notes'] ) ) : '',
			'changelog'   => '' !== $release['changelog'] ? self::changelog_html( $release['changelog'] ) : '',
		);

		return (object) array(
			'name'          => 'GitHubSync',
			'slug'          => 'githubsync',
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/digal2025">digal2025</a>',
			'homepage'      => 'https://github.com/digal2025/GithubSync-Wordpress-Plugin',
			'download_link' => $release['download_url'],
			'requires'      => $release['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $release['requires'],
			'last_updated'  => '' !== $release['released_at'] ? $release['released_at'] : gmdate( 'Y-m-d', $release['fetched_at'] ),
			'sections'      => array_filter( $sections ),
			'banners'       => array(),
			'icons'         => array(),
		);
	}

	/**
	 * Convert plain-text changelog into a safe HTML section.
	 *
	 * @param string $markdown Changelog text.
	 * @return string
	 */
	private static function changelog_html( $markdown ) {
		return wpautop( wp_kses_post( $markdown ) );
	}

	/**
	 * Move the extracted package into a folder named after the plugin.
	 *
	 * @param string       $source        Source path.
	 * @param string       $remote_source Remote source path.
	 * @param \WP_Upgrader $upgrader     Upgrader instance.
	 * @param array        $hook_extra    Extra arguments.
	 * @return string|\WP_Error
	 */
	public static function filter_source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || plugin_basename( GITHUBSYNC_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$target = trailingslashit( dirname( plugin_basename( GITHUBSYNC_FILE ) ) );
		$folder = 'githubsync';
		$source = trailingslashit( $source );

		if ( basename( untrailingslashit( $source ) ) === $folder && is_file( $source . 'githubsync.php' ) ) {
			return $source;
		}

		// Some zips nest everything one level deeper (githubsync/githubsync/).
		if ( is_dir( $source . $folder ) && is_file( $source . $folder . '/githubsync.php' ) ) {
			$source = trailingslashit( $source . $folder );
		}

		if ( ! is_file( $source . 'githubsync.php' ) ) {
			return new \WP_Error( ErrorCodes::UPDATE_PACKAGE_INVALID, __( 'The GitHubSync release package does not contain githubsync.php.', 'githubsync' ) );
		}

		$remote = trailingslashit( $remote_source ) . $folder;

		if ( untrailingslashit( $source ) !== untrailingslashit( $remote ) ) {
			if ( ! @rename( $source, $remote ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return new \WP_Error( ErrorCodes::UPDATE_PACKAGE_INVALID, __( 'Could not prepare the GitHubSync release package for installation.', 'githubsync' ) );
			}
		}

		return trailingslashit( $remote );
	}

	/**
	 * "Check for updates" link on the Plugins screen.
	 *
	 * @param array $links Row links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! Capability::user_can_manage() || ! self::enabled() ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'githubsync_check_update',
						),
						admin_url( 'admin-post.php' )
					),
					'githubsync_check_update'
				)
			),
			esc_html__( 'Check for updates', 'githubsync' )
		);

		return $links;
	}

	/**
	 * Record a failed check so the settings screen can explain it.
	 *
	 * @param string|\WP_Error $error Failure.
	 * @return void
	 */
	public static function record_error( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

		update_option(
			self::STATUS_OPTION,
			array(
				'state'      => self::STATE_ERROR,
				'message'    => $message,
				'checked_at' => time(),
			),
			false
		);
	}

	/**
	 * Record a successful check.
	 *
	 * @param array $release Manifest.
	 * @return void
	 */
	public static function record_release( array $release ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'state'      => self::STATE_CURRENT,
				'message'    => '',
				'version'    => $release['version'],
				'checked_at' => time(),
			),
			false
		);
	}

	/**
	 * Data for the settings screen.
	 *
	 * @return array
	 */
	public static function status() {
		$stored = get_option( self::STATUS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return wp_parse_args(
			$stored,
			array(
				'state'      => '',
				'message'    => '',
				'version'    => '',
				'checked_at' => 0,
			)
		);
	}

	/**
	 * Human description of the current update state.
	 *
	 * @return string
	 */
	public static function describe_status() {
		if ( ! self::enabled() ) {
			return self::disabled_reason();
		}

		if ( '' === self::manifest_url() ) {
			return __( 'No release feed is configured, so WordPress cannot check for GitHubSync updates yet.', 'githubsync' );
		}

		$status = self::status();

		if ( self::STATE_ERROR === $status['state'] ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'The last check failed: %s', 'githubsync' ),
				$status['message']
			);
		}

		if ( ! $status['checked_at'] ) {
			return __( 'GitHubSync checks for a new release twice a day. No check has run yet.', 'githubsync' );
		}

		$when = sprintf(
			/* translators: 1: date, 2: time. */
			__( '%1$s at %2$s', 'githubsync' ),
			date_i18n( get_option( 'date_format' ), (int) $status['checked_at'] + (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ),
			date_i18n( get_option( 'time_format' ), (int) $status['checked_at'] + (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS )
		);

		if ( self::is_newer( $status['version'] ) ) {
			return sprintf(
				/* translators: 1: new version, 2: checked time. */
				__( 'Version %1$s is available. Update from Dashboard → Updates or the Plugins screen. Last checked %2$s.', 'githubsync' ),
				$status['version'],
				$when
			);
		}

		return sprintf(
			/* translators: 1: running version, 2: checked time. */
			__( 'Up to date (running %1$s). Last checked %2$s.', 'githubsync' ),
			GITHUBSYNC_VERSION,
			$when
		);
	}

	/**
	 * Twice-daily background check.
	 *
	 * @return void
	 */
	public static function run_scheduled_check() {
		if ( ! self::enabled() || '' === self::manifest_url() ) {
			return;
		}

		$release = self::get_release( true );

		if ( is_wp_error( $release ) ) {
			self::record_error( $release );
			return;
		}

		self::record_release( $release );

		// Only wake the Plugins screen up when there is something to install.
		if ( self::is_newer( $release['version'] ) ) {
			wp_clean_plugins_cache( false );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Schedule the background check.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CHECK_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'twicedaily', self::CHECK_HOOK );
		}
	}

	/**
	 * Remove the background check.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CHECK_HOOK );
	}

	/**
	 * Validate a download without network access (used by tests and diagnostics).
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	public static function is_allowed_package_url( $url ) {
		$result = self::validate_manifest(
			array(
				'version'      => GITHUBSYNC_VERSION,
				'download_url' => (string) $url,
				'sha256'       => str_repeat( '0', 64 ),
			)
		);

		return ! is_wp_error( $result );
	}
}
