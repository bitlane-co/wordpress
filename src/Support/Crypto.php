<?php
/**
 * Encryption of what the plugin keeps about its connection.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Support;

/**
 * Encrypts with libsodium's secretbox, with a key derived from the site's
 * auth salt: a copy of the database alone doesn't give the tokens away.
 * Changing the salts makes the stored connection unreadable, and the site
 * connects again.
 */
final class Crypto {

	/**
	 * Encrypts a string.
	 *
	 * @param string $plain The value.
	 */
	public function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts what encrypt() made; null when it can't.
	 *
	 * @param string $encrypted The encrypted value.
	 */
	public function decrypt( string $encrypted ): ?string {
		$raw = base64_decode( $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		// A request that ended before pluggable.php loaded (its shutdown
		// hooks still run) has no wp_salt(): the value can't be read there.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || ! function_exists( 'wp_salt' ) ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key() );
		return false === $plain ? null : $plain;
	}

	/**
	 * The key, from the auth salt.
	 */
	private function key(): string {
		return hash( 'sha256', 'bitlane-connection|' . wp_salt( 'auth' ), true );
	}
}
