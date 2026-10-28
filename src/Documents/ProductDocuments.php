<?php
/**
 * A WooCommerce product as products-index documents.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Documents;

use Bitlane\WordPress\Site\Settings;
use WC_Product;
use WC_Product_Variation;
use WP_Term;

/**
 * Builds a product's documents (widget_public.ProductDocumentV1;
 * docs/wordpress-plan.md, "Documents"): one per variation, or one for the
 * whole product, as Settings' product_documents says. A product that isn't
 * for sale in the search (not published, hidden from search, out of stock
 * when the store hides those, password protected) has none. Each step has a
 * filter for developers (bitlane_product_*).
 */
final class ProductDocuments {

	/**
	 * The availability filter (facets.stock_status): in stock, on backorder,
	 * out of stock.
	 */
	public const STOCK_STATUS = 'stock_status';

	/**
	 * The product's documents; none when it isn't searchable.
	 *
	 * @param WC_Product $product    The product (not a variation).
	 * @param string[]   $taxonomies The chosen filters that products have.
	 * @param string     $mode       Settings::PER_VARIATION or PER_PRODUCT.
	 * @return list<array<string, mixed>>
	 */
	public function build( WC_Product $product, array $taxonomies, string $mode ): array {
		if ( ! $this->searchable( $product ) ) {
			return array();
		}

		// The stock status is a filter of Bitlane's, not a taxonomy.
		$status     = in_array( self::STOCK_STATUS, $taxonomies, true );
		$taxonomies = array_values( array_diff( $taxonomies, array( self::STOCK_STATUS ) ) );

		// Its own values: categories, tags, brands, and attributes that
		// aren't a choice between variations.
		$variation_attributes = $product->is_type( 'variable' ) ? array_keys( array_filter( $product->get_attributes(), static fn( $a ) => $a->get_variation() ) ) : array();
		$own                  = Fields::terms( $product->get_id(), array_diff( $taxonomies, $variation_attributes ) );

		// What's in stock has every value in available too (available.*), which
		// the widget filters on once the shopper wants what's in stock: XL in
		// stock is an XL that is, not a product with an XL and something else.
		if ( ! $product->is_type( 'variable' ) ) {
			$facets = Fields::merge(
				$own,
				Fields::terms( $product->get_id(), array_intersect( $taxonomies, $variation_attributes ) ),
				$status ? $this->stock_status( $product ) : array()
			);
			$docs   = array( $this->document( $product, $product, $facets, $facets, $product->is_in_stock() ? $facets : array() ) );
			return $this->filtered( $docs, $product );
		}

		$variations = $this->variations( $product );
		if ( ! $variations ) {
			return array();
		}
		$choices = array();
		foreach ( $variations as $variation ) {
			$choices[ $variation->get_id() ] = Fields::merge(
				$this->choices( $product, $variation, array_intersect( $taxonomies, $variation_attributes ) ),
				$status ? $this->stock_status( $variation ) : array()
			);
		}
		$all      = Fields::merge( $own, ...array_values( $choices ) );
		$in_stock = array_filter( $variations, static fn( $v ) => $v->is_in_stock() );

		if ( Settings::PER_PRODUCT === $mode ) {
			$available = $in_stock ? Fields::merge( $own, ...array_map( static fn( $v ) => $choices[ $v->get_id() ], array_values( $in_stock ) ) ) : array();
			return $this->filtered( array( $this->document( $product, $product, $all, $all, $available, $variations ) ), $product );
		}

		$docs = array();
		foreach ( $variations as $variation ) {
			$facets = Fields::merge( $own, $choices[ $variation->get_id() ] );
			$docs[] = $this->document( $product, $variation, $facets, $all, $variation->is_in_stock() ? $facets : array() );
		}
		return $this->filtered( $docs, $product );
	}

	/**
	 * Its stock status as a filter value (facets.stock_status), encoded as
	 * terms are: WooCommerce's name for it in the current language, the
	 * status, and a swatch.
	 *
	 * @param WC_Product $item The product or the variation.
	 * @return array<string, list<string>>
	 */
	private function stock_status( WC_Product $item ): array {
		$status = (string) $item->get_stock_status();
		$names  = wc_get_product_stock_status_options();
		$colors = array(
			'instock'     => '#16a34a',
			'onbackorder' => '#f59e0b',
			'outofstock'  => '#dc2626',
		);

		/**
		 * The swatch of a stock status in the widget's availability filter.
		 *
		 * @param string $color  A hex color, or empty for none. By default green in stock,
		 *                       orange on backorder, red out of stock.
		 * @param string $status The status: instock, onbackorder, outofstock, or another plugin's.
		 */
		$color = (string) apply_filters( 'bitlane_stock_status_color', $colors[ $status ] ?? '', $status );
		$name  = wp_specialchars_decode( (string) ( $names[ $status ] ?? $status ), ENT_QUOTES );
		return array( self::STOCK_STATUS => array( implode( '---', array( $name, $status, $status, '' !== $color ? $color : 'N/A' ) ) ) );
	}

	/**
	 * Whether the product can be found in the search.
	 *
	 * @param WC_Product $product The product.
	 */
	public function searchable( WC_Product $product ): bool {
		$searchable = 'publish' === $product->get_status()
			&& in_array( $product->get_catalog_visibility(), array( 'visible', 'search' ), true )
			&& ! $product->get_post_password()
			&& ( $product->is_in_stock() || 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) );

		/**
		 * Whether a product is in the search. By default: published, visible
		 * in search, without a password, and in stock when the store hides
		 * products that aren't.
		 *
		 * @param bool        $searchable Whether it's in the search.
		 * @param \WC_Product $product    The product.
		 */
		return (bool) apply_filters( 'bitlane_product_is_searchable', $searchable, $product );
	}

	/**
	 * The codes shoppers type: WooCommerce's GTIN, UPC, EAN or ISBN field
	 * (9.2 and later) of the product and of the variations the document
	 * covers, and what bitlane_product_codes adds; null without any.
	 *
	 * @param WC_Product   $product    The product.
	 * @param WC_Product[] $variations The variations the document covers.
	 * @return list<string>|null
	 */
	private function codes( WC_Product $product, array $variations ): ?array {
		$codes = array();
		foreach ( array_merge( array( $product ), $variations ) as $item ) {
			// WooCommerce 9.2 and later (phpstan.neon.dist).
			if ( method_exists( $item, 'get_global_unique_id' ) ) {
				$codes[] = (string) $item->get_global_unique_id();
			}
		}

		/**
		 * A product's codes shoppers may type (EAN, UPC, ISBN, MPN), for codes
		 * kept by other plugins: searchable without typos, not shown.
		 *
		 * @param string[]      $codes      The codes found.
		 * @param \WC_Product   $product    The product.
		 * @param \WC_Product[] $variations The variations the document covers.
		 */
		$codes = (array) apply_filters( 'bitlane_product_codes', $codes, $product, $variations );
		$codes = array_values( array_unique( array_filter( array_map( static fn( $code ) => trim( (string) $code ), $codes ), static fn( $code ) => '' !== $code ) ) );
		return $codes ? $codes : null;
	}

	/**
	 * A document of the product, or of one variation.
	 *
	 * @param WC_Product                  $product    The product.
	 * @param WC_Product                  $item       The product, or the variation.
	 * @param array<string, list<string>> $facets     The document's values.
	 * @param array<string, list<string>> $all        The whole product's values.
	 * @param array<string, list<string>> $available  Its values when in stock (a whole product: its own and its in-stock variations'), else none.
	 * @param WC_Product_Variation[]      $variations For a whole variable product: its variations.
	 * @return array<string, mixed>|null
	 */
	private function document( WC_Product $product, WC_Product $item, array $facets, array $all, array $available, array $variations = array() ): ?array {
		$priced       = $variations ? $variations : array( $item );
		$prices       = array_map( static fn( $p ) => Fields::minor( wc_get_price_to_display( $p ) ), $priced );
		$regular      = array_map( static fn( $p ) => Fields::minor( wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) ) ), $priced );
		$is_variation = $item instanceof WC_Product_Variation;

		/**
		 * The image size of product cards.
		 *
		 * @param string      $size    A registered size; by default WooCommerce's thumbnail.
		 * @param \WC_Product $product The product.
		 */
		$size = (string) apply_filters( 'bitlane_product_image_size', 'woocommerce_thumbnail', $product );

		$doc = array(
			'id'                => (string) $item->get_id(),
			'parent_id'         => (string) $product->get_id(),
			'title'             => wp_specialchars_decode( $product->get_name(), ENT_QUOTES ),
			'sku'               => $product->get_sku(),
			'variant_title'     => $is_variation ? $this->variant_title( $item ) : null,
			'variant_sku'       => $is_variation && $item->get_sku() !== $product->get_sku() ? $item->get_sku() : null,
			'codes'             => $this->codes( $product, $is_variation ? array( $item ) : $variations ),
			'permalink'         => $item->get_permalink(),
			'image'             => Fields::image( (int) ( $item->get_image_id() ? $item->get_image_id() : $product->get_image_id() ), $size ),
			'brand'             => $this->brand( $product ),
			'price'             => array(
				'min' => min( $prices ),
				'max' => max( $prices ),
			),
			'regular_price'     => array(
				'min' => min( $regular ),
				'max' => max( $regular ),
			),
			'short_description' => Fields::text( $product->get_short_description() ),
			'description'       => Fields::text( $product->get_description() ),
			'facets'            => (object) $facets,
			'product_facets'    => (object) $all,
			'available'         => (object) $available,
			'flags'             => array(
				'in_stock' => $variations ? (bool) array_filter( $variations, static fn( $v ) => $v->is_in_stock() ) : $item->is_in_stock(),
				'on_sale'  => (bool) array_filter( array_map( static fn( $price, $before ) => $price < $before, $prices, $regular ) ),
				'featured' => $product->is_featured(),
			),
			'total_sales'       => (int) $product->get_total_sales(),
			'rating'            => (float) $product->get_average_rating(),
			'rating_count'      => $product->get_review_count(),
			'created_at'        => $product->get_date_created() ? $product->get_date_created()->getTimestamp() : 0,
			'menu_order'        => $product->get_menu_order(),
		);
		$doc = array_filter( $doc, static fn( $value ) => null !== $value && '' !== $value );

		/**
		 * A products-index document before it's sent: change it (keep the
		 * schema, widget_public.ProductDocumentV1), or return null to leave it
		 * out.
		 *
		 * @param array<string, mixed>|null $doc     The document.
		 * @param \WC_Product               $item    What it's of: the variation, or the product.
		 * @param \WC_Product               $product The product.
		 */
		$doc = apply_filters( 'bitlane_product_document', $doc, $item, $product );
		return is_array( $doc ) ? $doc : null;
	}

	/**
	 * The documents left after bitlane_product_document, through
	 * bitlane_product_documents.
	 *
	 * @param list<array<string, mixed>|null> $docs    The documents.
	 * @param WC_Product                      $product The product.
	 * @return list<array<string, mixed>>
	 */
	private function filtered( array $docs, WC_Product $product ): array {
		$docs = array_values( array_filter( $docs, 'is_array' ) );

		/**
		 * All of a product's documents before they're sent: add, change or
		 * remove some. Documents the product had and this list hasn't are
		 * deleted from the index.
		 *
		 * @param list<array<string, mixed>> $docs    The documents.
		 * @param \WC_Product                $product The product.
		 */
		return array_values( array_filter( (array) apply_filters( 'bitlane_product_documents', $docs, $product ), 'is_array' ) );
	}

	/**
	 * The variations shoppers can buy: published, priced, and in stock when
	 * the store hides what isn't.
	 *
	 * @param WC_Product $product The variable product.
	 * @return list<WC_Product_Variation>
	 */
	private function variations( WC_Product $product ): array {
		$hide       = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
		$variations = array();
		foreach ( $product->get_children() as $id ) {
			$variation = wc_get_product( $id );
			if ( $variation instanceof WC_Product_Variation && $variation->variation_is_visible() && '' !== $variation->get_price() && ( ! $hide || $variation->is_in_stock() ) ) {
				$variations[] = $variation;
			}
		}

		/**
		 * The variations of a product that are in the search: one document
		 * each, or together in the product's.
		 *
		 * @param list<\WC_Product_Variation> $variations By default: published, priced, and in stock when the store hides those that aren't.
		 * @param \WC_Product                 $product    The variable product.
		 */
		return apply_filters( 'bitlane_product_variations', $variations, $product );
	}

	/**
	 * A variation's values of the product's variation attributes; "any" is
	 * every value the product has.
	 *
	 * @param WC_Product           $product    The product.
	 * @param WC_Product_Variation $variation  The variation.
	 * @param string[]             $taxonomies The attributes that are filters.
	 * @return array<string, list<string>>
	 */
	private function choices( WC_Product $product, WC_Product_Variation $variation, array $taxonomies ): array {
		$chosen = $variation->get_attributes();
		$facets = array();
		foreach ( $taxonomies as $taxonomy ) {
			$slug = (string) ( $chosen[ $taxonomy ] ?? '' );
			if ( '' === $slug ) {
				$facets[ $taxonomy ] = Fields::terms( $product->get_id(), array( $taxonomy ) )[ $taxonomy ] ?? array();
				continue;
			}
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$facets[ $taxonomy ] = array( Fields::value( $term ) );
			}
		}
		return array_filter( $facets );
	}

	/**
	 * Which of the product a variation is: "Red / XL".
	 *
	 * @param WC_Product $variation The variation.
	 */
	private function variant_title( WC_Product $variation ): string {
		$names = array();
		foreach ( $variation->get_attributes() as $taxonomy => $slug ) {
			$term    = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) $slug, $taxonomy ) : false;
			$names[] = $term instanceof WP_Term ? $term->name : (string) $slug;
		}
		return wp_specialchars_decode( implode( ' / ', array_filter( $names ) ), ENT_QUOTES );
	}

	/**
	 * The product's brand.
	 *
	 * @param WC_Product $product The product.
	 */
	private function brand( WC_Product $product ): string {
		$terms = taxonomy_exists( 'product_brand' ) ? get_the_terms( $product->get_id(), 'product_brand' ) : false;
		$brand = is_array( $terms ) && $terms ? wp_specialchars_decode( $terms[0]->name, ENT_QUOTES ) : '';

		/**
		 * A product's brand, shown on its card and searched. By default the
		 * first of WooCommerce's Brands (product_brand); a store keeping
		 * brands elsewhere (an attribute, a meta field) returns its own.
		 *
		 * @param string      $brand   The brand, or empty.
		 * @param \WC_Product $product The product.
		 */
		return (string) apply_filters( 'bitlane_product_brand', $brand, $product );
	}
}
