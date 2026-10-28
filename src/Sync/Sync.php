<?php
/**
 * The sync, as the admin screen and WP-CLI see it.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Site\Settings;

/**
 * When a source syncs in full (Bitlane made a setup ready that changed it;
 * a merchant's Resync), where each is, and stopping it all when the site
 * disconnects.
 */
final class Sync {

	/**
	 * Builds the sync.
	 *
	 * @param FullSync    $full        The full syncs.
	 * @param Queue       $queue       The changes' queue.
	 * @param SyncState   $state       The progress.
	 * @param Settings    $settings    The setup.
	 * @param Credentials $credentials The indexes and key.
	 * @param Check       $check       The daily check.
	 */
	public function __construct(
		private FullSync $full,
		private Queue $queue,
		private SyncState $state,
		private Settings $settings,
		private Credentials $credentials,
		private Check $check,
	) {}

	/**
	 * Bitlane made the setup ready: each source the setup changed (new, or
	 * other filters) syncs in full, with fresh credentials (its index or the
	 * key may be new), and every source when the site moved to another engine
	 * (the new one's indexes start empty); sources no longer chosen are
	 * forgotten (Bitlane deleted their indexes). The daily check runs from
	 * then on.
	 *
	 * @param string $engine_id The site's engine in Bitlane; empty when unknown.
	 */
	public function ready( string $engine_id = '' ): void {
		$this->check->schedule();
		$chosen = $this->settings->get()['sources'];
		$kept   = $this->state->engine();
		$moved  = '' !== $engine_id && '' !== $kept && $kept !== $engine_id;
		if ( '' !== $engine_id && $kept !== $engine_id ) {
			$this->state->set_engine( $engine_id );
		}
		$changed = $moved ? $chosen : array_values( array_filter( $chosen, fn( $source ) => ( $this->state->source( $source )['setup'] ?? '' ) !== $this->settings->hash( $source ) ) );
		foreach ( array_diff( array_keys( (array) ( $this->state->get()['sources'] ?? array() ) ), $chosen ) as $removed ) {
			$this->state->update_source( (string) $removed, null );
		}
		if ( ! $changed ) {
			return;
		}
		$this->credentials->forget();
		foreach ( $changed as $source ) {
			$this->full->start( $source );
			$this->state->update_source( $source, array( 'setup' => $this->settings->hash( $source ) ) );
		}
	}

	/**
	 * Syncs a source again, or every chosen one.
	 *
	 * @param string|null $source The source's id; null for all.
	 */
	public function resync( ?string $source = null ): void {
		$chosen = $this->settings->get()['sources'];
		$this->credentials->forget();
		foreach ( null === $source ? $chosen : array_intersect( array( $source ), $chosen ) as $id ) {
			$this->full->start( $id );
		}
	}

	/**
	 * Where each chosen source is, for the screen.
	 *
	 * @return array{sources: list<array{id: string, running: bool, language: ?string, done: int, total: int, started_at: ?int, finished_at: ?int}>, queued: int, error: ?array{message: string, at: int}}
	 */
	public function summary(): array {
		$state   = $this->state->get();
		$sources = array();
		foreach ( $this->settings->get()['sources'] as $id ) {
			$source    = (array) ( $state['sources'][ $id ] ?? array() );
			$running   = isset( $source['started_at'] ) && empty( $source['finished_at'] );
			$sources[] = array(
				'id'          => $id,
				'running'     => $running,
				// The language its full sync is on.
				'language'    => $running && isset( $source['language'] ) ? (string) $source['language'] : null,
				'done'        => (int) ( $source['done'] ?? 0 ),
				'total'       => (int) ( $source['total'] ?? 0 ),
				'started_at'  => isset( $source['started_at'] ) ? (int) $source['started_at'] : null,
				'finished_at' => isset( $source['finished_at'] ) ? (int) $source['finished_at'] : null,
			);
		}
		return array(
			'sources' => $sources,
			'queued'  => count(
				as_get_scheduled_actions(
					array(
						'hook'     => 'bitlane_sync',
						'group'    => 'bitlane',
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => -1,
					),
					'ids'
				)
			),
			'error'   => $state['error'] ?? null,
		);
	}

	/**
	 * The site disconnected: nothing more is sent.
	 */
	public function disconnected(): void {
		$this->queue->stop();
		$this->full->stop();
		$this->check->stop();
		$this->state->clear();
		$this->credentials->forget();
	}
}
