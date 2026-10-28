<?php
/**
 * Changes, collected and synced in the background.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sources\Sources;
use WP_Post;

/**
 * Collects the items that changed during a request: each source reports its
 * own (Source::watch(), hooked on init), and code that writes items can call
 * do_action( 'bitlane_sync_documents', $source, $ids ). On shutdown they're
 * queued as Action Scheduler actions (bitlane_sync, group bitlane) of 50
 * items each: bounded work per action, and a product lookup within
 * Meilisearch's limits. Indexer runs them; failures are retried with backoff
 * (Retry). What no single source watches is here: a renamed or deleted term
 * syncs its posts, and settings that change every document of a source
 * start its full sync.
 */
final class Queue implements HasHooks {

	/**
	 * The request's changes: source => item id => true.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $pending = array();

	/**
	 * Builds the queue.
	 *
	 * @param Sources         $sources    The sources, which report changes.
	 * @param Settings        $settings   The chosen sources.
	 * @param Indexer         $indexer    Syncs the changes.
	 * @param SyncState       $state      The runs, and errors.
	 * @param ConnectionStore $connection Whether the site is connected.
	 * @param FullSync        $full       For settings that change every document.
	 */
	public function __construct(
		private Sources $sources,
		private Settings $settings,
		private Indexer $indexer,
		private SyncState $state,
		private ConnectionStore $connection,
		private FullSync $full,
	) {}

	/**
	 * The sources' watching, the other changes, and the actions that sync.
	 */
	public function hooks(): void {
		// Late: post types and developers' sources are registered by then.
		add_action( 'init', array( $this, 'watch' ), 99 );
		add_action( 'bitlane_sync_documents', array( $this, 'add' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'term_changed' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term_deleted' ), 10, 5 );
		add_action( 'updated_option', array( $this, 'option_changed' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
		add_action( 'bitlane_sync', array( $this, 'run' ), 10, 3 );
		add_action( 'bitlane_sync_term', array( $this, 'term' ), 10, 3 );
	}

	/**
	 * Has each source report its changes here.
	 */
	public function watch(): void {
		foreach ( $this->sources->all() as $id => $source ) {
			$source->watch( fn( array $ids ) => $this->add( $id, $ids ) );
		}
	}

	/**
	 * Notes changed items of a source.
	 *
	 * @param string            $source The source's id.
	 * @param array<string|int> $ids    The items.
	 */
	public function add( string $source, array $ids ): void {
		foreach ( $ids as $id ) {
			$this->pending[ $source ][ (string) $id ] = true;
		}
	}

	/**
	 * A term was renamed or moved: its posts sync, in the background.
	 *
	 * @param int    $term_id  The term.
	 * @param int    $tt_id    Its taxonomy term id.
	 * @param string $taxonomy The taxonomy.
	 */
	public function term_changed( $term_id, $tt_id, $taxonomy ): void {
		if ( $this->active() ) {
			as_enqueue_async_action( 'bitlane_sync_term', array( (string) $taxonomy, (int) $term_id, 0 ), 'bitlane' );
		}
	}

	/**
	 * A term was deleted: the posts it had sync.
	 *
	 * @param int    $term_id    The term.
	 * @param int    $tt_id      Its taxonomy term id.
	 * @param string $taxonomy   The taxonomy.
	 * @param mixed  $deleted    The term as it was.
	 * @param int[]  $object_ids Its posts.
	 */
	public function term_deleted( $term_id, $tt_id, $taxonomy, $deleted = null, $object_ids = array() ): void {
		foreach ( (array) $object_ids as $id ) {
			$this->post( (int) $id );
		}
	}

	/**
	 * A page of 500 of a changed term's posts, then the next page.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param int    $term_id  The term.
	 * @param int    $page     The page.
	 */
	public function term( string $taxonomy, int $term_id, int $page ): void {
		$ids = get_objects_in_term( $term_id, $taxonomy );
		if ( ! is_array( $ids ) ) {
			return;
		}
		$size = 500;
		foreach ( array_slice( $ids, $page * $size, $size ) as $id ) {
			$this->post( (int) $id );
		}
		if ( count( $ids ) > ( $page + 1 ) * $size ) {
			as_enqueue_async_action( 'bitlane_sync_term', array( $taxonomy, $term_id, $page + 1 ), 'bitlane' );
		}
	}

	/**
	 * A setting that changes every document of a source: the store's prices
	 * and stock (products), the links (every source).
	 *
	 * @param string $option The option.
	 */
	public function option_changed( $option ): void {
		$store = array(
			'woocommerce_hide_out_of_stock_items',
			'woocommerce_currency',
			'woocommerce_calc_taxes',
			'woocommerce_prices_include_tax',
			'woocommerce_tax_display_shop',
		);
		if ( ! $this->active() ) {
			return;
		}
		$sources = $this->settings->get()['sources'];
		if ( in_array( $option, $store, true ) && in_array( 'product', $sources, true ) ) {
			$this->full->start( 'product' );
		} elseif ( 'permalink_structure' === $option ) {
			array_map( array( $this->full, 'start' ), $sources );
		}
	}

	/**
	 * Queues the request's changes, 50 items an action.
	 */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = array();
		if ( ! $pending || ! $this->active() ) {
			return;
		}
		foreach ( $pending as $source => $ids ) {
			foreach ( array_chunk( array_map( 'strval', array_keys( $ids ) ), 50 ) as $chunk ) {
				as_enqueue_async_action( 'bitlane_sync', array( (string) $source, $chunk, 0 ), 'bitlane' );
			}
		}
	}

	/**
	 * Syncs queued changes of a source in every language, stamped with its
	 * current run; a failure is retried with backoff.
	 *
	 * @param string   $source  The source's id.
	 * @param string[] $ids     Its items.
	 * @param int      $attempt Attempts so far.
	 */
	public function run( string $source, array $ids, int $attempt = 0 ): void {
		$synced = $this->indexer->sync( $source, $ids, $this->state->run( $source ) );
		if ( ! is_wp_error( $synced ) ) {
			$this->state->error( null );
			return;
		}
		$this->state->error( $synced );
		$delay = Retry::after( $synced, $attempt );
		if ( null !== $delay ) {
			as_schedule_single_action( time() + $delay, 'bitlane_sync', array( $source, $ids, $attempt + 1 ), 'bitlane' );
		}
	}

	/**
	 * Whether changes are synced: connected, with a setup synced once.
	 */
	public function active(): bool {
		return $this->connection->connected() && ! empty( $this->state->get()['sources'] );
	}

	/**
	 * Stops the queued work: the site disconnected.
	 */
	public function stop(): void {
		$this->pending = array();
		// The hook alone: with a group, only actions without arguments would go.
		as_unschedule_all_actions( 'bitlane_sync' );
		as_unschedule_all_actions( 'bitlane_sync_term' );
	}

	/**
	 * Notes a post under its source; a variation is its product.
	 *
	 * @param int $id The post.
	 */
	private function post( int $id ): void {
		$post = get_post( $id );
		if ( $post instanceof WP_Post ) {
			$this->add( 'product_variation' === $post->post_type ? 'product' : $post->post_type, array( 'product_variation' === $post->post_type ? $post->post_parent : $post->ID ) );
		}
	}
}
