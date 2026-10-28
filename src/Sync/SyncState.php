<?php
/**
 * Where the sync is.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use WP_Error;

/**
 * One small option, not autoloaded, the same size for any catalog: items
 * wait in Action Scheduler's tables, and a full sync's cursor travels in its
 * actions' arguments. It holds, per source:
 * - run: stamped on every document of the source written (sync_run). A full
 *   sync of the source starts a new one and, at its end, deletes the
 *   documents of the source's index with another, so what changed meanwhile
 *   survives it;
 * - setup: the source's setup it was last synced in full for (Settings::hash());
 * - done, total, started_at, finished_at: its last full sync, and the
 *   language it's on (language);
 * the last error, until a sync succeeds; and the engine the site's indexes
 * are on (a new one means syncing everything again, Sync::ready()).
 */
final class SyncState {

	/**
	 * The state.
	 *
	 * @return array{sources?: array<string, array<string, mixed>>, error?: array{message: string, at: int}, engine?: string}
	 */
	public function get(): array {
		// Read again: Action Scheduler runs batches in other requests.
		wp_cache_delete( 'bitlane_sync', 'options' );
		$state = get_option( 'bitlane_sync', array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * The engine the site's indexes are on, as Bitlane last said; empty before.
	 */
	public function engine(): string {
		return (string) ( $this->get()['engine'] ?? '' );
	}

	/**
	 * Notes the engine the site's indexes are on.
	 *
	 * @param string $engine_id The engine.
	 */
	public function set_engine( string $engine_id ): void {
		$state           = $this->get();
		$state['engine'] = $engine_id;
		update_option( 'bitlane_sync', $state, false );
	}

	/**
	 * A source's state.
	 *
	 * @param string $source The source's id.
	 * @return array<string, mixed>
	 */
	public function source( string $source ): array {
		return (array) ( $this->get()['sources'][ $source ] ?? array() );
	}

	/**
	 * Merges changes into a source's state; a null value removes the key,
	 * and null for $changes removes the source.
	 *
	 * @param string                    $source  The source's id.
	 * @param array<string, mixed>|null $changes The changes.
	 */
	public function update_source( string $source, ?array $changes ): void {
		$state = $this->get();
		if ( null === $changes ) {
			unset( $state['sources'][ $source ] );
		} else {
			$state['sources'][ $source ] = array_filter(
				array_merge( (array) ( $state['sources'][ $source ] ?? array() ), $changes ),
				static fn( $value ) => null !== $value
			);
		}
		update_option( 'bitlane_sync', $state, false );
	}

	/**
	 * A source's current run, started when it has none.
	 *
	 * @param string $source The source's id.
	 */
	public function run( string $source ): string {
		$run = (string) ( $this->source( $source )['run'] ?? '' );
		if ( '' === $run ) {
			$run = self::new_run();
			$this->update_source( $source, array( 'run' => $run ) );
		}
		return $run;
	}

	/**
	 * A run id: short, and fine in a Meilisearch filter.
	 */
	public static function new_run(): string {
		return gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false ) );
	}

	/**
	 * Notes a failure, or clears it after a success.
	 *
	 * @param WP_Error|null $error The failure; null after a success.
	 */
	public function error( ?WP_Error $error ): void {
		$state = $this->get();
		if ( null === $error && ! isset( $state['error'] ) ) {
			return;
		}
		if ( $error ) {
			$state['error'] = array(
				'message' => $error->get_error_message(),
				'at'      => time(),
			);
		} else {
			unset( $state['error'] );
		}
		update_option( 'bitlane_sync', $state, false );
	}

	/**
	 * Forgets everything: the site disconnected.
	 */
	public function clear(): void {
		delete_option( 'bitlane_sync' );
	}
}
