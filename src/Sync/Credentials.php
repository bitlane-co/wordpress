<?php
/**
 * Where the site's documents go, and the key that writes them.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Connection\BitlaneApi;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Support\Crypto;
use WP_Error;

/**
 * Bitlane's GET /api/integrations/:id/credentials: each engine's address and
 * a write key limited to the site's indexes (a week long, renewed with two
 * days left), cached encrypted for an hour (docs/wordpress-plan.md W4).
 */
final class Credentials {

	private const CACHE = 'bitlane_credentials';

	/**
	 * Builds the credentials.
	 *
	 * @param BitlaneApi      $api        The API.
	 * @param ConnectionStore $connection The connection, for the site's id.
	 * @param Crypto          $crypto     Encrypts the cache.
	 */
	public function __construct(
		private BitlaneApi $api,
		private ConnectionStore $connection,
		private Crypto $crypto,
	) {}

	/**
	 * Where a source's documents in a language go: its own index. An index
	 * the cached credentials don't have yet (a new setup made it) is looked
	 * for again in fresh ones.
	 *
	 * @param string $source   The source's id.
	 * @param string $language The language, as the setup sent it ("en-US").
	 * @return array{url: string, key: string, index: string}|WP_Error
	 */
	public function index( string $source, string $language ): array|WP_Error {
		foreach ( array( false, true ) as $fresh ) {
			if ( $fresh ) {
				$this->forget();
			}
			$credentials = $this->get();
			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}
			foreach ( $credentials['engines'] as $engine ) {
				foreach ( (array) $engine['indexes'] as $index ) {
					if ( ( $index['source'] ?? '' ) === $source && strtolower( (string) ( $index['language'] ?? '' ) ) === strtolower( $language ) ) {
						return array(
							'url'   => untrailingslashit( (string) $engine['engine_url'] ),
							'key'   => (string) $engine['write_key'],
							'index' => (string) $index['uid'],
						);
					}
				}
			}
		}
		return new WP_Error( 'bitlane_no_index', sprintf( 'Bitlane has no index for %s in %s on this site yet.', $source, $language ) );
	}

	/**
	 * The credentials, from the cache or Bitlane.
	 *
	 * @return array{index_prefix: string, engines: list<array<string, mixed>>}|WP_Error
	 */
	public function get(): array|WP_Error {
		$cached = get_transient( self::CACHE );
		if ( is_string( $cached ) ) {
			$json = $this->crypto->decrypt( $cached );
			$data = null === $json ? null : json_decode( $json, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		$id = $this->connection->get()['integration_id'] ?? null;
		if ( ! $id ) {
			return new WP_Error( 'bitlane_not_connected', __( 'This site isn\'t connected to Bitlane.', 'bitlane' ) );
		}
		$data = $this->api->request( 'GET', '/api/integrations/' . rawurlencode( (string) $id ) . '/credentials' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data = array(
			'index_prefix' => (string) ( $data['index_prefix'] ?? '' ),
			'engines'      => array_values( (array) ( $data['engines'] ?? array() ) ),
		);
		set_transient( self::CACHE, $this->crypto->encrypt( (string) wp_json_encode( $data ) ), HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Drops the cache: a refused key, a new setup, a disconnect.
	 */
	public function forget(): void {
		delete_transient( self::CACHE );
	}
}
