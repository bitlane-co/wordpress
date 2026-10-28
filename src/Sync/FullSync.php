<?php
/**
 * Sends a source's items again.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sources\Sources;
use WP_Error;

/**
 * A source's full sync, in Action Scheduler actions (bitlane_full_sync,
 * group bitlane) of 200 items each, one after another; each source has its
 * own, so they run side by side and one can start again alone. An action's
 * arguments are its cursor (source, run, language, last id), so a retry
 * redoes exactly that batch. Documents are stamped with a new run for the
 * source; once all its items in a language are sent, documents of that
 * language's index with another run are deleted, which removes what's gone
 * or unpublished since, while the index stays searchable throughout
 * (docs/wordpress-plan.md W6). Then the next language.
 */
final class FullSync implements HasHooks {

	/**
	 * Builds the full sync.
	 *
	 * @param Indexer     $indexer     Writes batches.
	 * @param Sources     $sources     The sources.
	 * @param Settings    $settings    The setup.
	 * @param Languages   $languages   The languages.
	 * @param SyncState   $state       The progress.
	 * @param Credentials $credentials The index, for the sweep.
	 * @param Meilisearch $meilisearch Deletes in the sweep.
	 */
	public function __construct(
		private Indexer $indexer,
		private Sources $sources,
		private Settings $settings,
		private Languages $languages,
		private SyncState $state,
		private Credentials $credentials,
		private Meilisearch $meilisearch,
	) {}

	/**
	 * Runs the batches.
	 */
	public function hooks(): void {
		add_action( 'bitlane_full_sync', array( $this, 'batch' ), 10, 5 );
	}

	/**
	 * Starts a source's full sync; one of it still running stops (its
	 * actions see a newer run and end).
	 *
	 * @param string $source The source's id.
	 * @return string The run.
	 */
	public function start( string $source ): string {
		$found     = $this->sources->get( $source );
		$languages = $this->languages->codes();
		$run       = SyncState::new_run();
		$this->state->update_source(
			$source,
			array(
				'run'         => $run,
				'language'    => $languages[0],
				'done'        => 0,
				'total'       => $found ? array_sum( array_map( array( $found, 'count' ), $languages ) ) : 0,
				'started_at'  => time(),
				'finished_at' => null,
			)
		);
		as_enqueue_async_action( 'bitlane_full_sync', array( $source, $run, $languages[0], '', 0 ), 'bitlane' );
		return $run;
	}

	/**
	 * One batch, then the next is queued; a failure is retried with backoff.
	 *
	 * @param string $source   The source's id.
	 * @param string $run      The run.
	 * @param string $language The language.
	 * @param string $after    The last id done.
	 * @param int    $attempt  Attempts so far.
	 */
	public function batch( string $source, string $run, string $language, string $after = '', int $attempt = 0 ): void {
		$next = $this->step( $source, $run, $language, $after );
		if ( is_wp_error( $next ) ) {
			$this->state->error( $next );
			$delay = Retry::after( $next, $attempt );
			if ( null !== $delay ) {
				as_schedule_single_action( time() + $delay, 'bitlane_full_sync', array( $source, $run, $language, $after, $attempt + 1 ), 'bitlane' );
			}
			return;
		}
		if ( null !== $next ) {
			as_enqueue_async_action( 'bitlane_full_sync', array( $source, $run, $next['language'], $next['after'], 0 ), 'bitlane' );
		}
	}

	/**
	 * One batch: where the next starts (the language, the last id done);
	 * null when the source is done in every language, or a newer run
	 * replaced this one.
	 *
	 * @param string $source   The source's id.
	 * @param string $run      The run.
	 * @param string $language The language.
	 * @param string $after    The last id done.
	 * @return array{language: string, after: string}|null|WP_Error
	 */
	public function step( string $source, string $run, string $language, string $after ): array|null|WP_Error {
		$state = $this->state->source( $source );
		$found = $this->sources->get( $source );
		if ( ( $state['run'] ?? '' ) !== $run || ! empty( $state['finished_at'] ) || ! $found || ! in_array( $source, $this->settings->get()['sources'], true ) ) {
			return null;
		}

		$ids = $found->ids( $after, 200, $language );
		if ( ! $ids ) {
			$index = $this->credentials->index( $source, $language );
			$swept = is_wp_error( $index ) ? $index : $this->meilisearch->delete_where( $index, 'sync_run != "' . $run . '"' );
			if ( is_wp_error( $swept ) ) {
				return $swept;
			}
			$languages = $this->languages->codes();
			$at        = array_search( $language, $languages, true );
			$next      = false === $at ? null : ( $languages[ $at + 1 ] ?? null );
			if ( null !== $next ) {
				return array(
					'language' => $next,
					'after'    => '',
				);
			}
			$this->state->update_source( $source, array( 'finished_at' => time() ) );
			$this->state->error( null );
			return null;
		}

		$synced = $this->indexer->sync( $source, $ids, $run, $language, true );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}
		$this->state->update_source(
			$source,
			array(
				'done'     => (int) ( $state['done'] ?? 0 ) + count( $ids ),
				'language' => $language,
			)
		);
		return array(
			'language' => $language,
			'after'    => (string) end( $ids ),
		);
	}

	/**
	 * Stops every full sync: the site disconnected.
	 */
	public function stop(): void {
		// The hook alone: with a group, only actions without arguments would go.
		as_unschedule_all_actions( 'bitlane_full_sync' );
	}
}
