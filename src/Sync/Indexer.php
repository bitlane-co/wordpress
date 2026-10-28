<?php
/**
 * Writes items' documents to their source's index.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sources\Context;
use Bitlane\WordPress\Sources\Source;
use Bitlane\WordPress\Sources\Sources;
use WP_Error;

/**
 * Turns a source's changed items into writes to its index in each language:
 * their documents in that language are upserted in one call (stamped with
 * the run, with only the picked filters), then what the items no longer
 * have there is deleted. A product's documents share its parent_id, so the
 * ones it had are found with it (variation 46 removed: the index has 43–46,
 * the product now gives 43–45, 46 goes); any other item has one document,
 * its id, deleted when the item gives none. A translated item gives none in
 * the other languages, so it leaves the index of a language it's no longer
 * in.
 */
final class Indexer {

	/**
	 * Builds the indexer.
	 *
	 * @param Sources     $sources     The sources.
	 * @param Settings    $settings    The setup.
	 * @param Languages   $languages   The languages.
	 * @param Credentials $credentials Where documents go.
	 * @param Meilisearch $meilisearch Writes them.
	 */
	public function __construct(
		private Sources $sources,
		private Settings $settings,
		private Languages $languages,
		private Credentials $credentials,
		private Meilisearch $meilisearch,
	) {}

	/**
	 * Syncs a source's items in a language, or in every one that has its
	 * index (a language just added has none until Bitlane sets it up, and
	 * its full sync follows). A full sync doesn't look for documents an
	 * item no longer has: its final sweep deletes them.
	 *
	 * @param string            $source_id The source.
	 * @param array<string|int> $ids       Its items.
	 * @param string            $run       The run stamped on the documents.
	 * @param string            $language  The language; empty for every one.
	 * @param bool              $full      Part of a full sync.
	 * @return true|WP_Error
	 */
	public function sync( string $source_id, array $ids, string $run, string $language = '', bool $full = false ): bool|WP_Error {
		$settings = $this->settings->get();
		$source   = $this->sources->get( $source_id );
		if ( ! $source || ! in_array( $source_id, $settings['sources'], true ) || ! $ids ) {
			return true;
		}

		$ids    = array_values( array_unique( array_map( 'strval', $ids ) ) );
		$facets = array_values( array_intersect( $settings['facets'], array_keys( $source->facets() ) ) );
		foreach ( '' !== $language ? array( $language ) : $this->languages->codes() as $code ) {
			$index = $this->credentials->index( $source_id, $code );
			if ( is_wp_error( $index ) && '' === $language && 'bitlane_no_index' === $index->get_error_code() ) {
				continue;
			}
			$sent = is_wp_error( $index ) ? $index : $this->write( $source, $index, $ids, new Context( $facets, $settings['product_documents'], $code ), $run, $full );
			if ( is_wp_error( $sent ) ) {
				$status = (int) ( $sent->get_error_data()['status'] ?? 0 );
				// The write key may have been replaced: the next try reads it again.
				if ( 401 === $status || 403 === $status ) {
					$this->credentials->forget();
				}
				return $sent;
			}
		}
		return true;
	}

	/**
	 * Writes the items' documents in a language to its index.
	 *
	 * @param Source                                         $source  The source.
	 * @param array{url: string, key: string, index: string} $index   Its index in the language.
	 * @param string[]                                       $ids     The items.
	 * @param Context                                        $context The setup and language.
	 * @param string                                         $run     The run.
	 * @param bool                                           $full    Part of a full sync.
	 * @return true|WP_Error
	 */
	private function write( Source $source, array $index, array $ids, Context $context, string $run, bool $full ): bool|WP_Error {
		$docs = $this->languages->in( $context->language, fn() => $this->documents( $source, $ids, $context, $run ) );
		$sent = $this->meilisearch->add( $index, $docs );
		return true === $sent && ! $full ? $this->delete_others( $source, $index, $ids, wp_list_pluck( $docs, 'id' ) ) : $sent;
	}

	/**
	 * The items' documents as they're sent.
	 *
	 * @param Source   $source  The source.
	 * @param string[] $ids     The items.
	 * @param Context  $context The setup.
	 * @param string   $run     The run.
	 * @return list<array<string, mixed>>
	 */
	private function documents( Source $source, array $ids, Context $context, string $run ): array {
		$by_item  = $source->documents( $ids, $context );
		$products = Source::PRODUCTS === $source->kind();
		$picked   = array_flip( $context->facets );
		$docs     = array();
		foreach ( $ids as $item ) {
			$item_docs = array_values( array_filter( (array) ( $by_item[ $item ] ?? array() ), 'is_array' ) );
			foreach ( $products ? $item_docs : array_slice( $item_docs, 0, 1 ) as $doc ) {
				$doc['id'] = $products ? (string) ( $doc['id'] ?? $item ) : $item;
				if ( $products ) {
					$doc['parent_id'] = (string) ( $doc['parent_id'] ?? $item );
				}
				foreach ( array( 'facets', 'product_facets', 'available' ) as $field ) {
					if ( isset( $doc[ $field ] ) ) {
						$doc[ $field ] = (object) array_intersect_key( (array) $doc[ $field ], $picked );
					}
				}
				$doc['sync_run'] = $run;
				$docs[]          = $doc;
			}
		}
		return $docs;
	}

	/**
	 * Deletes what the items had and no longer have. Which of them the index
	 * holds is read first, so the other languages' indexes, which mostly
	 * hold none, get a read rather than a write.
	 *
	 * @param Source                                         $source The source.
	 * @param array{url: string, key: string, index: string} $index  Its index.
	 * @param string[]                                       $ids    The items.
	 * @param string[]                                       $kept   The documents just sent.
	 * @return true|WP_Error
	 */
	private function delete_others( Source $source, array $index, array $ids, array $kept ): bool|WP_Error {
		if ( Source::PRODUCTS === $source->kind() ) {
			$quoted   = array_map( static fn( $item ) => '"' . addcslashes( $item, '"\\' ) . '"', $ids );
			$existing = $this->meilisearch->ids_where( $index, 'parent_id IN [' . implode( ', ', $quoted ) . ']' );
		} else {
			$gone     = array_values( array_diff( $ids, $kept ) );
			$existing = $gone ? $this->meilisearch->present( $index, false, $gone ) : array( 'items' => array() );
			$existing = is_wp_error( $existing ) ? $existing : $existing['items'];
		}
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		return $this->meilisearch->delete( $index, array_values( array_diff( $existing, $kept ) ) );
	}
}
