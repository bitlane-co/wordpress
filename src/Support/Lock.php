<?php
/**
 * A lock across requests.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Support;

/**
 * A lock one request holds at a time, which expires on its own if the
 * request dies holding it:
 * - with a persistent object cache (Redis, Memcached): wp_cache_add(), an
 *   atomic add;
 * - without one, a transient carrying the holder's token, read back after
 *   it's set. That isn't atomic, so two requests can rarely both take it;
 *   a token refresh that overlaps another is covered by the server's grace
 *   window (docs/oauth-plan.md O4).
 */
final class Lock {

	/**
	 * Runs $callback holding the lock, waiting up to $wait seconds for it;
	 * without it after that, runs it anyway.
	 *
	 * @template T
	 * @param string        $name     The lock's name.
	 * @param callable(): T $callback What to run.
	 * @param int           $wait     Seconds to wait for the lock.
	 * @param int           $ttl      Seconds before a held lock counts as abandoned.
	 * @return T
	 */
	public function with( string $name, callable $callback, int $wait = 15, int $ttl = 30 ): mixed {
		$key      = 'bitlane_lock_' . $name;
		$token    = wp_generate_uuid4();
		$acquired = false;
		$deadline = microtime( true ) + $wait;
		do {
			$acquired = $this->acquire( $key, $token, $ttl );
			if ( ! $acquired ) {
				usleep( 200000 );
			}
		} while ( ! $acquired && microtime( true ) < $deadline );

		try {
			return $callback();
		} finally {
			if ( $acquired ) {
				$this->release( $key );
			}
		}
	}

	/**
	 * One try at the lock.
	 *
	 * @param string $key   The lock's key.
	 * @param string $token This request's token.
	 * @param int    $ttl   Seconds it may be held.
	 */
	private function acquire( string $key, string $token, int $ttl ): bool {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, $token, 'bitlane', $ttl );
		}
		// The database's value, not what this request read before.
		wp_cache_delete( '_transient_' . $key, 'options' );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, $token, $ttl );
		// Another request may have set it at the same moment: the last one wins.
		wp_cache_delete( '_transient_' . $key, 'options' );
		return get_transient( $key ) === $token;
	}

	/**
	 * Gives the lock up.
	 *
	 * @param string $key The lock's key.
	 */
	private function release( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, 'bitlane' );
			return;
		}
		delete_transient( $key );
	}
}
