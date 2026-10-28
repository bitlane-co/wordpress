<?php
/**
 * Every source the site has.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

use Bitlane\WordPress\Documents\OtherDocument;
use Bitlane\WordPress\Documents\ProductDocuments;
use Bitlane\WordPress\Languages\Languages;

/**
 * WooCommerce's products, the public post types and the sources developers
 * add (bitlane_document_sources), products first.
 */
final class Sources {

	/**
	 * Builds the registry.
	 *
	 * @param ProductDocuments $products  Builds product documents.
	 * @param OtherDocument    $other     Builds post documents.
	 * @param Languages        $languages The posts' languages.
	 */
	public function __construct(
		private ProductDocuments $products,
		private OtherDocument $other,
		private Languages $languages,
	) {}

	/**
	 * The sources by id.
	 *
	 * @return array<string, Source>
	 */
	public function all(): array {
		$names = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		/**
		 * The post types the setup screen offers to search: add a post type
		 * that isn't public, or leave one out. Products are WooCommerce's
		 * (their own index); any other is "other" content.
		 *
		 * @param string[] $post_types By default the public ones, but attachments.
		 */
		$names = (array) apply_filters( 'bitlane_searchable_post_types', $names );

		$sources     = array();
		$woocommerce = in_array( 'product', $names, true ) && function_exists( 'wc_get_product' );
		if ( $woocommerce ) {
			$sources[] = new ProductSource( $this->products, $this->languages );
		}
		foreach ( $names as $name ) {
			$type = get_post_type_object( (string) $name );
			// Without WooCommerce, a theme's own "product" post type is other content.
			if ( $type && ( ! $woocommerce || 'product' !== $type->name ) ) {
				$sources[] = new PostTypeSource( $type, $this->other, $this->languages );
			}
		}

		/**
		 * Sources of documents besides products and post types: a custom
		 * table, another plugin's data, an external API. Each implements
		 * Bitlane\WordPress\Sources\Source; report changes with
		 * do_action( 'bitlane_sync_documents', $source_id, $item_ids ).
		 *
		 * @param Source[] $sources None by default.
		 */
		foreach ( apply_filters( 'bitlane_document_sources', array() ) as $source ) {
			// Its id is in index names.
			if ( preg_match( '/^[a-z0-9_-]+$/', $source->id() ) ) {
				$sources[] = $source;
			}
		}

		$by_id = array();
		foreach ( $sources as $source ) {
			$by_id[ $source->id() ] ??= $source;
		}
		return $by_id;
	}

	/**
	 * A source by id.
	 *
	 * @param string $id The id.
	 */
	public function get( string $id ): ?Source {
		return $this->all()[ $id ] ?? null;
	}
}
