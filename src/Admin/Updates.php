<?php
/**
 * The plugin's updates, from Bitlane's CDN.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Admin;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Support\Http;

/**
 * The plugin isn't on wordpress.org yet (docs/wordpress-plan.md W9): its
 * Update URI header names the CDN's wordpress/ folder (cdn.bitlane.co, or
 * cdn-stg.bitlane.co in staging's builds), and WordPress asks the
 * update_plugins_<host> filter, which reads the latest release from
 * info.json there (.github/deploy/wordpress.sh), when it checks for updates
 * (twice a day, and on the Updates screen).
 */
final class Updates implements HasHooks {

	/**
	 * Builds the update check.
	 *
	 * @param Http   $http    HTTP.
	 * @param string $version The installed version (BITLANE_VERSION).
	 */
	public function __construct(
		private Http $http,
		private string $version,
	) {}

	/**
	 * Answers for both CDNs: the header says which one.
	 */
	public function hooks(): void {
		add_filter( 'update_plugins_cdn.bitlane.co', array( $this, 'check' ), 10, 3 );
		add_filter( 'update_plugins_cdn-stg.bitlane.co', array( $this, 'check' ), 10, 3 );
	}

	/**
	 * The latest release, which WordPress offers when it's newer than the
	 * installed one; nothing when info.json can't be read.
	 *
	 * @param array<string, mixed>|false $update      Another filter's answer.
	 * @param array<string, string>      $plugin_data The plugin's headers.
	 * @param string                     $plugin_file The plugin's file.
	 * @return array<string, mixed>|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		// A copy from git (0.0.0-dev; releases write the tag's version) never updates itself.
		if ( plugin_basename( BITLANE_FILE ) !== $plugin_file || str_ends_with( $this->version, '-dev' ) ) {
			return $update;
		}
		$res  = $this->http->request( 'GET', trailingslashit( $plugin_data['UpdateURI'] ) . 'info.json' );
		$info = is_wp_error( $res ) || 200 !== $res['status'] ? array() : $res['body'];
		if ( empty( $info['version'] ) || empty( $info['package'] ) ) {
			return $update;
		}
		return array(
			'slug'         => 'bitlane',
			'version'      => (string) $info['version'],
			'url'          => 'https://bitlane.co',
			'package'      => (string) $info['package'],
			'requires'     => (string) ( $info['requires'] ?? '' ),
			'requires_php' => (string) ( $info['requires_php'] ?? '' ),
		);
	}
}
