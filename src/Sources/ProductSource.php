<?php
/**
 * WooCommerce's products as a source.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

use Bitlane\WordPress\Documents\ProductDocuments;
use Bitlane\WordPress\Languages\Languages;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Products: documents per variation or per product
 * (ProductDocuments), filters from product taxonomies and attributes. A
 * translated product is in its language only.
 */
final class ProductSource implements Source {

	/**
	 * Takes changed product ids, from watch().
	 *
	 * @var (callable(array<string|int>): void)|null
	 */
	private $changed = null;

	/**
	 * Builds the source.
	 *
	 * @param ProductDocuments $builder   Builds the documents.
	 * @param Languages        $languages The products' languages.
	 */
	public function __construct(
		private ProductDocuments $builder,
		private Languages $languages,
	) {}

	/**
	 * WooCommerce's post type.
	 */
	public function id(): string {
		return 'product';
	}

	/**
	 * "Products".
	 */
	public function label(): string {
		$type = get_post_type_object( 'product' );
		return $type ? (string) $type->labels->name : __( 'Products', 'bitlane' );
	}

	/**
	 * Products.
	 */
	public function kind(): string {
		return self::PRODUCTS;
	}

	/**
	 * Published products.
	 *
	 * @param string $language A language; empty for all.
	 */
	public function count( string $language = '' ): int {
		return $this->languages->post_count( 'product', $language );
	}

	/**
	 * The price (Bitlane's price filter, from the documents' price), the
	 * availability (the stock status), then the product taxonomies that can
	 * be filters, attributes (pa_*) included.
	 *
	 * @return array<string, string>
	 */
	public function facets(): array {
		return array(
			'price'                        => __( 'Price', 'bitlane' ),
			ProductDocuments::STOCK_STATUS => __( 'Availability', 'bitlane' ),
		) + TaxonomyFacets::of( 'product' );
	}

	/**
	 * Published products in the language by id.
	 *
	 * @param string $after    The last id of the previous batch.
	 * @param int    $limit    How many.
	 * @param string $language The language.
	 * @return list<string>
	 */
	public function ids( string $after, int $limit, string $language ): array {
		return $this->languages->post_ids( 'product', $language, (int) $after, $limit );
	}

	/**
	 * Each product's documents; none for one in another language.
	 *
	 * @param string[] $ids     The products.
	 * @param Context  $context The setup.
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function documents( array $ids, Context $context ): array {
		// The price filter reads the documents' price; the rest are taxonomies.
		$taxonomies = array_values( array_diff( $context->facets, array( 'price' ) ) );
		$modified   = $this->modified( $ids );
		$docs       = array();
		foreach ( $ids as $id ) {
			$product     = wc_get_product( (int) $id );
			$docs[ $id ] = $product instanceof WC_Product && ! $product->is_type( 'variation' ) && $this->languages->has_post( $product->get_id(), $context->language )
				? $this->builder->build( $product, $taxonomies, $context->product_documents )
				: array();
			foreach ( $docs[ $id ] as $i => $doc ) {
				$docs[ $id ][ $i ]['modified_at'] = $modified[ (string) $id ] ?? 0;
			}
		}
		return $docs;
	}

	/**
	 * When each product or one of its variations last changed.
	 *
	 * @param string[] $ids The products.
	 * @return array<string, int>
	 */
	public function modified( array $ids ): array {
		return PostIds::modified( $ids, 'product_variation' );
	}

	/**
	 * Products and their variations (a variation stands for its product):
	 * saved, published or not, trashed, deleted, their stock, their sales and
	 * their reviews (rating).
	 *
	 * @param callable(array<string|int>): void $changed Takes changed product ids.
	 */
	public function watch( callable $changed ): void {
		$this->changed = $changed;

		add_action( 'save_post_product', array( $this, 'post_changed' ), 20 );
		add_action( 'save_post_product_variation', array( $this, 'post_changed' ), 20 );
		add_action( 'trashed_post', array( $this, 'post_changed' ) );
		add_action( 'untrashed_post', array( $this, 'post_changed' ) );
		add_action( 'wp_update_comment_count', array( $this, 'post_changed' ) );
		add_action( 'woocommerce_update_product', array( $this, 'post_changed' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'post_changed' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'post_changed' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'post_changed' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'product_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'product_changed' ) );
		add_action( 'transition_post_status', array( $this, 'status_changed' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'post_deleted' ), 10, 2 );
		add_action( 'woocommerce_recorded_sales', array( $this, 'order_sales' ) );
	}

	/**
	 * A product or variation post changed.
	 *
	 * @param int|\WP_Post $post The post.
	 */
	public function post_changed( $post ): void {
		$post = get_post( $post );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( 'product' === $post->post_type ) {
			$this->changed( array( $post->ID ) );
		} elseif ( 'product_variation' === $post->post_type ) {
			$this->changed( array( $post->post_parent ) );
		}
	}

	/**
	 * A product's or variation's stock changed.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public function product_changed( WC_Product $product ): void {
		$this->changed( array( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) );
	}

	/**
	 * A post was published, unpublished or scheduled.
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old one.
	 * @param \WP_Post $post       The post.
	 */
	public function status_changed( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status ) {
			$this->post_changed( $post );
		}
	}

	/**
	 * A post was deleted: only the post as it was says its type.
	 *
	 * @param int           $post_id The post.
	 * @param \WP_Post|null $post    The post as it was.
	 */
	public function post_deleted( $post_id, $post = null ): void {
		if ( $post ) {
			$this->post_changed( $post );
		}
	}

	/**
	 * An order counted its sales: its products' total_sales changed.
	 *
	 * @param int $order_id The order.
	 */
	public function order_sales( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$items = array_filter( $order->get_items(), static fn( $item ) => $item instanceof WC_Order_Item_Product );
			$this->changed( array_map( static fn( $item ) => $item->get_product_id(), array_values( $items ) ) );
		}
	}

	/**
	 * Reports changed products.
	 *
	 * @param array<string|int> $ids The products.
	 */
	private function changed( array $ids ): void {
		if ( $this->changed ) {
			( $this->changed )( $ids );
		}
	}
}
