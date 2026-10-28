<?php
/**
 * A post type's taxonomies as filters.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

/**
 * The taxonomies of a post type that can be filters: public ones and
 * WooCommerce's product attributes (not public unless their archives are on),
 * but not post formats, shipping classes, product visibility or types.
 */
final class TaxonomyFacets {

	/**
	 * The post type's filters, by taxonomy.
	 *
	 * @param string $post_type The post type.
	 * @return array<string, string> Taxonomy => label.
	 */
	public static function of( string $post_type ): array {
		$facets = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			$allowed = ( $taxonomy->public || str_starts_with( $taxonomy->name, 'pa_' ) )
				&& ! in_array( $taxonomy->name, array( 'post_format', 'product_shipping_class', 'product_visibility', 'product_type' ), true );

			/**
			 * Whether a taxonomy may be a filter in the search widget (and is
			 * sent with each document as facets.<taxonomy>).
			 *
			 * @param bool         $allowed  By default: public taxonomies and product attributes (pa_*),
			 *                               but not post formats, shipping classes, product visibility or types.
			 * @param \WP_Taxonomy $taxonomy The taxonomy.
			 */
			if ( apply_filters( 'bitlane_allow_taxonomy_as_search_filter', $allowed, $taxonomy ) ) {
				$facets[ $taxonomy->name ] = (string) $taxonomy->labels->singular_name;
			}
		}
		return $facets;
	}
}
