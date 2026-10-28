<?php
/**
 * What the plugin keeps about its connection to Bitlane.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Connection;

use Bitlane\WordPress\Support\Crypto;

/**
 * The connection, encrypted in one option that isn't autoloaded:
 * - client_id, client_secret, redirect_uri: the plugin's OAuth client,
 *   registered with Bitlane on the first Connect;
 * - access_token, refresh_token, expires_at, scope: the grant's tokens;
 * - project_id: the project the grant is for (null: all the user's);
 * - integration_id: this site in Bitlane;
 * - revoked: Bitlane ended the grant (the app was revoked there, or the
 *   site disconnected).
 */
final class ConnectionStore {

	public const OPTION = 'bitlane_connection';

	/**
	 * Builds the store.
	 *
	 * @param Crypto $crypto Encrypts the option.
	 */
	public function __construct( private Crypto $crypto ) {}

	/**
	 * The stored connection, empty when there's none or it can't be read.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		// Read again: another request may have refreshed the tokens.
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}
		$json = $this->crypto->decrypt( $stored );
		$data = null === $json ? null : json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Merges changes into the connection; a null value removes the key.
	 *
	 * @param array<string, mixed> $changes The changes.
	 * @return array<string, mixed> The connection.
	 */
	public function update( array $changes ): array {
		$data = array_filter(
			array_merge( $this->get(), $changes ),
			static fn( $value ) => null !== $value
		);
		update_option( self::OPTION, $this->crypto->encrypt( (string) wp_json_encode( $data ) ), false );
		return $data;
	}

	/**
	 * Ends the grant's part of the connection; the OAuth client stays, for
	 * the next Connect.
	 *
	 * @param bool $revoked Whether Bitlane ended it (the admin gets a notice).
	 */
	public function end( bool $revoked ): void {
		$this->update(
			array(
				'access_token'   => null,
				'refresh_token'  => null,
				'expires_at'     => null,
				'scope'          => null,
				'project_id'     => null,
				'integration_id' => null,
				'revoked'        => $revoked ? true : null,
			)
		);
	}

	/**
	 * Whether the site has a grant.
	 */
	public function connected(): bool {
		return ! empty( $this->get()['refresh_token'] );
	}
}
