<?php
/**
 * When a failed sync tries again.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use WP_Error;

/**
 * Retries what can pass later, with backoff: no connection, 429, 401/403 (a
 * replaced write key, read again), and 5xx, 503 with Retry-After while an
 * engine upgrades (docs/wordpress-plan.md, "Sync").
 */
final class Retry {

	/**
	 * Seconds until the next attempt, or null to give up (after 8).
	 *
	 * @param WP_Error $error   The failure.
	 * @param int      $attempt Attempts so far, 0 after the first.
	 */
	public static function after( WP_Error $error, int $attempt ): ?int {
		if ( $attempt >= 8 || 'bitlane_disconnected' === $error->get_error_code() ) {
			return null;
		}
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		if ( 0 !== $status && 401 !== $status && 403 !== $status && 429 !== $status && $status < 500 ) {
			return null;
		}
		$retry_after = (int) ( $data['retry_after'] ?? 0 );
		return $retry_after > 0 ? $retry_after : min( HOUR_IN_SECONDS, 30 * ( 2 ** $attempt ) );
	}
}
