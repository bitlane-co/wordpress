<?php
/**
 * The daily check that each index matches its source.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sources\Source;
use Bitlane\WordPress\Sources\Sources;
use WP_Error;

/**
 * Finds what no hook saw (imports writing the database directly, the plugin
 * switched off, actions that gave up) once a day (docs/wordpress-plan.md,
 * "Sync"). Each chosen source's ids in each language are walked 1,000 at a
 * time, and Meilisearch says which of them the language's index has and when
 * their documents say each changed (modified_at): missing items, and items
 * changed since (Source::modified()), are synced like any change, so their
 * documents decide (an item that isn't searchable stays out). If the index then holds more documents than
 * the walk found, some are of items gone from the site (or from the
 * language), and the source's full sync sweeps them. Ten batches an action,
 * retried with backoff; a source in a full sync is skipped.
 */
final class Check implements HasHooks {

	/**
	 * Builds the check.
	 *
	 * @param Sources     $sources     The sources.
	 * @param Settings    $settings    The chosen sources.
	 * @param Languages   $languages   The languages.
	 * @param SyncState   $state       Which sources are in a full sync.
	 * @param Credentials $credentials The indexes.
	 * @param Meilisearch $meilisearch Asks which items an index has.
	 * @param FullSync    $full        Sweeps an index with items gone.
	 */
	public function __construct(
		private Sources $sources,
		private Settings $settings,
		private Languages $languages,
		private SyncState $state,
		private Credentials $credentials,
		private Meilisearch $meilisearch,
		private FullSync $full,
	) {}

	/**
	 * The daily start and the batches.
	 */
	public function hooks(): void {
		add_action( 'bitlane_check', array( $this, 'start' ) );
		add_action( 'bitlane_check_source', array( $this, 'batch' ), 10, 5 );
	}

	/**
	 * Checks every day, at night in the store's timezone: from the next
	 * 2 AM, at a random minute of that hour so stores don't all check at
	 * once. A daylight saving change moves it by an hour.
	 */
	public function schedule(): void {
		if ( as_has_scheduled_action( 'bitlane_check' ) ) {
			return;
		}
		$at = new \DateTimeImmutable( 'today 02:00', wp_timezone() );
		if ( $at->getTimestamp() <= time() ) {
			$at = $at->modify( '+1 day' );
		}
		as_schedule_recurring_action( $at->getTimestamp() + wp_rand( 0, 59 ) * MINUTE_IN_SECONDS, DAY_IN_SECONDS, 'bitlane_check', array(), 'bitlane', true );
	}

	/**
	 * Stops checking: the site disconnected.
	 */
	public function stop(): void {
		as_unschedule_all_actions( 'bitlane_check' );
		as_unschedule_all_actions( 'bitlane_check_source' );
	}

	/**
	 * Starts the walk of each chosen source in each language.
	 */
	public function start(): void {
		foreach ( $this->settings->get()['sources'] as $source ) {
			foreach ( $this->languages->codes() as $language ) {
				as_enqueue_async_action( 'bitlane_check_source', array( $source, $language, '', 0, 0 ), 'bitlane' );
			}
		}
	}

	/**
	 * Up to ten batches of a source in a language, then the next action; at
	 * the end, the index's documents against those the walk found.
	 *
	 * @param string $source    The source's id.
	 * @param string $language  The language.
	 * @param string $after     The last id checked.
	 * @param int    $documents Documents of the items found so far.
	 * @param int    $attempt   Attempts so far.
	 */
	public function batch( string $source, string $language, string $after, int $documents, int $attempt = 0 ): void {
		$found = $this->sources->get( $source );
		$state = $this->state->source( $source );
		if ( ! $found || ! in_array( $source, $this->settings->get()['sources'], true ) || ( isset( $state['started_at'] ) && empty( $state['finished_at'] ) ) ) {
			return;
		}

		$walked = $this->walk( $found, $source, $language, $after, $documents );
		if ( is_wp_error( $walked ) ) {
			$delay = Retry::after( $walked, $attempt );
			if ( null !== $delay ) {
				as_schedule_single_action( time() + $delay, 'bitlane_check_source', array( $source, $language, $after, $documents, $attempt + 1 ), 'bitlane' );
			}
			return;
		}
		if ( null !== $walked ) {
			as_enqueue_async_action( 'bitlane_check_source', array( $source, $language, $walked['after'], $walked['documents'], 0 ), 'bitlane' );
		}
	}

	/**
	 * Ten batches: where the walk is, or null once it's done.
	 *
	 * @param Source $found     The source.
	 * @param string $source    Its id.
	 * @param string $language  The language.
	 * @param string $after     The last id checked.
	 * @param int    $documents Documents of the items found so far.
	 * @return array{after: string, documents: int}|null|WP_Error
	 */
	private function walk( Source $found, string $source, string $language, string $after, int $documents ): array|null|WP_Error {
		$index = $this->credentials->index( $source, $language );
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		for ( $i = 0; $i < 10; $i++ ) {
			$ids = $found->ids( $after, 1000, $language );
			if ( ! $ids ) {
				$total = $this->meilisearch->count( $index );
				if ( is_wp_error( $total ) ) {
					return $total;
				}
				if ( $total > $documents ) {
					$this->full->start( $source );
				}
				return null;
			}

			$present = $this->meilisearch->present( $index, Source::PRODUCTS === $found->kind(), $ids );
			if ( is_wp_error( $present ) ) {
				return $present;
			}
			$missing = array_values( array_diff( array_map( 'strval', $ids ), $present['items'] ) );
			// Changed since its document was written (no modified_at: before it).
			$stale = array();
			foreach ( $found->modified( $present['items'] ) as $item => $changed ) {
				if ( $changed > ( $present['modified'][ (string) $item ] ?? 0 ) ) {
					$stale[] = (string) $item;
				}
			}
			if ( $missing || $stale ) {
				do_action( 'bitlane_sync_documents', $source, array_merge( $missing, $stale ) );
			}
			$documents += $present['documents'];
			$after      = (string) end( $ids );
		}
		return array(
			'after'     => $after,
			'documents' => $documents,
		);
	}
}
