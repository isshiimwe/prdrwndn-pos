<?php
/**
 * Updates the plugin from GitHub releases, like the PRDRWNDN theme.
 *
 * Each release must have prdrwndn-pos.zip attached under Assets (never
 * GitHub's auto-generated source zip). As a safety net, the downloaded
 * folder is renamed to prdrwndn-pos before WordPress installs it.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Updater {

	const REPO      = 'isshiimwe/prdrwndn-pos';
	const ASSET     = 'prdrwndn-pos.zip';
	const SLUG      = 'prdrwndn-pos';
	const TRANSIENT = 'prdrwndn_pos_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 0 );
		// Dashboard > Updates > "Check again" also asks GitHub again.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force' ) );
	}

	public static function maybe_force() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::clear();
		}
	}

	/**
	 * Newest version on GitHub (a release, or a vX.Y.Z tag), cached for 6 hours.
	 *
	 * @return array|null version, zip, url, notes, published.
	 */
	public static function release() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'prdrwndn-pos/' . PRDRWNDN_POS_VERSION,
		);
		if ( defined( 'PRDRWNDN_POS_GITHUB_TOKEN' ) && PRDRWNDN_POS_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . PRDRWNDN_POS_GITHUB_TOKEN;
		}
		$api  = 'https://api.github.com/repos/' . self::REPO;
		$args = array( 'headers' => $headers, 'timeout' => 10 );
		$data = array();

		// 1. The latest release: its prdrwndn-pos.zip, or else GitHub's own code zip.
		$res = wp_remote_get( $api . '/releases/latest', $args );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) ) {
				$zip = '';
				foreach ( (array) ( $json['assets'] ?? array() ) as $asset ) {
					if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
						$zip = $asset['browser_download_url'];
						break;
					}
				}
				$data = array(
					'version'   => ltrim( $json['tag_name'], 'vV' ),
					'zip'       => $zip ? $zip : self::tag_zip( $json['tag_name'] ),
					'url'       => $json['html_url'] ?? '',
					'notes'     => $json['body'] ?? '',
					'published' => $json['published_at'] ?? '',
				);
			}
		}

		// 2. A version tag newer than the latest release (vX.Y.Z), using GitHub's code zip.
		$res = wp_remote_get( $api . '/tags?per_page=50', $args );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $tag ) {
				$name = (string) ( $tag['name'] ?? '' );
				if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $name, $m ) ) {
					continue;
				}
				if ( empty( $data['version'] ) || version_compare( $m[1], $data['version'], '>' ) ) {
					$data = array(
						'version'   => $m[1],
						'zip'       => self::tag_zip( $name ),
						'url'       => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( $name ),
						'notes'     => '',
						'published' => '',
					);
				}
			}
		}
		// Cache misses too, so a missing repo does not slow down wp-admin.
		set_site_transient( self::TRANSIENT, $data, empty( $data ) ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return empty( $data ) ? null : $data;
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel = self::release();
		if ( ! $rel ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => self::REPO,
			'slug'        => self::SLUG,
			'plugin'      => PRDRWNDN_POS_BASENAME,
			'new_version' => $rel['version'],
			'url'         => $rel['url'],
			'package'     => $rel['zip'],
		);
		if ( version_compare( $rel['version'], PRDRWNDN_POS_VERSION, '>' ) ) {
			$transient->response[ PRDRWNDN_POS_BASENAME ] = $item;
		} else {
			$transient->no_update[ PRDRWNDN_POS_BASENAME ] = $item;
		}
		return $transient;
	}

	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$rel = self::release();
		if ( ! $rel ) {
			return $result;
		}
		return (object) array(
			'name'          => 'PRDRWNDN POS',
			'slug'          => self::SLUG,
			'version'       => $rel['version'],
			'author'        => 'PRDRWNDN',
			'homepage'      => $rel['url'],
			'download_link' => $rel['zip'],
			'last_updated'  => $rel['published'],
			'sections'      => array( 'changelog' => wpautop( esc_html( $rel['notes'] ) ) ),
		);
	}

	/**
	 * Rename the unpacked folder to prdrwndn-pos so WordPress replaces the
	 * plugin instead of installing a second copy.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || PRDRWNDN_POS_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return $wanted;
		}
		return new WP_Error( 'prdrwndn_pos_folder', __( 'The update zip has the wrong folder name. Attach prdrwndn-pos.zip to the release.', 'prdrwndn-pos' ) );
	}

	/** GitHub's own zip of the code at a tag (its folder is renamed to prdrwndn-pos on install). */
	private static function tag_zip( $tag ) {
		return 'https://github.com/' . self::REPO . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip';
	}

	public static function clear() {
		delete_site_transient( self::TRANSIENT );
	}
}
