<?php
/**
 * Fields both kinds of documents have.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Documents;

use WP_Term;

/**
 * Images, terms as facet values, text and prices as the document schemas
 * want them (server/widget_public/documents_v1.go).
 */
final class Fields {

	/**
	 * An image as the cards show it, or null.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $size          The size shown.
	 * @return array{src: string, srcset?: string, sizes?: string, width?: int, height?: int, alt?: string}|null
	 */
	public static function image( int $attachment_id, string $size = 'woocommerce_thumbnail' ): ?array {
		$src   = $attachment_id ? wp_get_attachment_image_src( $attachment_id, $size ) : false;
		$image = $src ? array_filter(
			array(
				'src'    => (string) $src[0],
				'srcset' => (string) wp_get_attachment_image_srcset( $attachment_id, $size ),
				'sizes'  => (string) wp_get_attachment_image_sizes( $attachment_id, $size ),
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
				'alt'    => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			)
		) : null;

		/**
		 * A document's image (widget_public.DocumentImageV1): another one, a
		 * CDN's address, or null for none.
		 *
		 * @param array<string, mixed>|null $image         The image: src, srcset, sizes, width, height, alt.
		 * @param int                       $attachment_id The attachment, 0 for none.
		 * @param string                    $size          The size.
		 */
		$image = apply_filters( 'bitlane_document_image', $image, $attachment_id, $size );
		return is_array( $image ) && ! empty( $image['src'] ) ? $image : null;
	}

	/**
	 * A post's terms of the taxonomies, each with its parents, as facet
	 * values by taxonomy: a product in Clothing › Shirts is found under both.
	 *
	 * @param int      $post_id    The post.
	 * @param string[] $taxonomies The taxonomies.
	 * @return array<string, list<string>>
	 */
	public static function terms( int $post_id, array $taxonomies ): array {
		$facets = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			/**
			 * A post's terms of a taxonomy that become its filter values.
			 *
			 * @param \WP_Term[] $terms    Its terms.
			 * @param int        $post_id  The post.
			 * @param string     $taxonomy The taxonomy.
			 */
			$terms = apply_filters( 'bitlane_document_terms', is_array( $terms ) ? $terms : array(), $post_id, $taxonomy );

			/**
			 * Whether a hierarchical taxonomy's parents count too: a product in
			 * Clothing › Shirts is found under Clothing as well.
			 *
			 * @param bool   $parents  True by default.
			 * @param string $taxonomy The taxonomy.
			 */
			$parents = (bool) apply_filters( 'bitlane_facet_include_parents', true, $taxonomy );

			$values = array();
			foreach ( $terms as $term ) {
				$ids = $parents ? array_merge( array( $term->term_id ), get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) ) : array( $term->term_id );
				foreach ( $ids as $id ) {
					$found = get_term( $id, $taxonomy );
					if ( $found instanceof WP_Term ) {
						$values[] = self::value( $found );
					}
				}
			}
			if ( $values ) {
				$facets[ $taxonomy ] = array_values( array_unique( $values ) );
			}
		}
		return $facets;
	}

	/**
	 * A term as a facet value: "title---slug---id---swatch", which the
	 * widget splits. The swatch is an image's URL or a color (a URL is an
	 * image to the widget), N/A without one; both come from WooCommerce's
	 * "Color / image" attributes, the color also from other attributes'
	 * common color meta, else they're empty unless a plugin sets them.
	 *
	 * @param WP_Term $term The term.
	 */
	public static function value( WP_Term $term ): string {
		$visual = self::swatch( $term );

		/**
		 * A term's color, which the widget can show as a swatch: a hex color,
		 * or empty.
		 *
		 * @param string   $color The color of a WooCommerce "Color / image" attribute's term, else empty.
		 * @param \WP_Term $term  The term.
		 */
		$color = (string) apply_filters( 'bitlane_term_color', $visual['color'], $term );

		/**
		 * A term's swatch image, which the widget shows instead of a color:
		 * its URL, or empty.
		 *
		 * @param string   $image The image of a WooCommerce "Color / image" attribute's term, else empty.
		 * @param \WP_Term $term  The term.
		 */
		$image = (string) apply_filters( 'bitlane_term_image', $visual['image'], $term );

		$swatch = '' !== $image ? $image : $color;
		$value  = implode( '---', array( wp_specialchars_decode( $term->name, ENT_QUOTES ), $term->slug, (string) $term->term_id, '' !== $swatch ? $swatch : 'N/A' ) );

		/**
		 * A term as a filter value. Keep the format
		 * "title---slug---id---swatch": the widget shows the title, the swatch
		 * (an image's URL, or a color), and filters on the whole value.
		 *
		 * @param string   $value The value.
		 * @param \WP_Term $term  The term.
		 */
		return (string) apply_filters( 'bitlane_facet_value', $value, $term );
	}

	/**
	 * The swatch of a term of a WooCommerce "Color / image" attribute
	 * (attribute type wc-visual, WooCommerce 10.9+): a color (term meta
	 * color, hex) or an image (term meta image, an attachment), never both.
	 * Other attributes' terms get the color of meta_color().
	 *
	 * @param WP_Term $term The term.
	 * @return array{color: string, image: string}
	 */
	private static function swatch( WP_Term $term ): array {
		// The attribute type of the term's taxonomy; null when it's no product attribute.
		$type = null;
		foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $attribute ) {
			if ( wc_attribute_taxonomy_name( $attribute->attribute_name ) === $term->taxonomy ) {
				$type = (string) $attribute->attribute_type;
				break;
			}
		}
		if ( 'wc-visual' !== $type ) {
			return array(
				'color' => null === $type ? '' : self::meta_color( $term ),
				'image' => '',
			);
		}

		$image_id = (int) get_term_meta( $term->term_id, 'image', true );

		/**
		 * The image size of a term's swatch.
		 *
		 * @param string   $size 'thumbnail' by default.
		 * @param \WP_Term $term The term.
		 */
		$size  = (string) apply_filters( 'bitlane_term_image_size', 'thumbnail', $term );
		$image = $image_id ? (string) wp_get_attachment_image_url( $image_id, $size ) : '';

		return array(
			'color' => '' === $image ? (string) sanitize_hex_color( (string) get_term_meta( $term->term_id, 'color', true ) ) : '',
			'image' => $image,
		);
	}

	/**
	 * The color of a product attribute's term from the term meta that swatch
	 * plugins and themes commonly use: the first key holding a hex color,
	 * with or without "#" (of a list like "#fff,#000", the first). Only
	 * product attributes (swatch()): on other taxonomies these keys can mean
	 * anything.
	 *
	 * @param WP_Term $term The term.
	 */
	private static function meta_color( WP_Term $term ): string {
		/**
		 * The term meta keys a product attribute's term color is read from, in
		 * order: the first holding a hex color wins.
		 *
		 * @param list<string> $keys The keys.
		 * @param \WP_Term     $term The term.
		 */
		$keys = (array) apply_filters(
			'bitlane_term_color_meta_keys',
			array( 'color', 'colour', 'color_value', 'color_hex', 'colorhex', 'hex', 'hex_color', 'product_attribute_color' ),
			$term
		);
		foreach ( $keys as $key ) {
			$value = get_term_meta( $term->term_id, (string) $key, true );
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( explode( ',', $value )[0] );
			$color = (string) sanitize_hex_color( '' === $value || '#' === $value[0] ? $value : '#' . $value );
			if ( '' !== $color ) {
				return $color;
			}
		}
		return '';
	}

	/**
	 * Facet values merged by taxonomy, each once.
	 *
	 * @param array<string, list<string>> ...$sets The values.
	 * @return array<string, list<string>>
	 */
	public static function merge( array ...$sets ): array {
		$merged = array();
		foreach ( $sets as $set ) {
			foreach ( $set as $taxonomy => $values ) {
				$merged[ $taxonomy ] = array_values( array_unique( array_merge( $merged[ $taxonomy ] ?? array(), $values ) ) );
			}
		}
		return $merged;
	}

	/**
	 * HTML as plain, searchable text, cut to a length.
	 *
	 * @param string $html The HTML.
	 */
	public static function text( string $html ): string {
		$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $html ) ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		/**
		 * How many characters of a description or a post's text are sent:
		 * Meilisearch searches a field's first words anyway, and documents
		 * stay small.
		 *
		 * @param int $limit 20,000 by default.
		 */
		return mb_substr( $text, 0, max( 0, (int) apply_filters( 'bitlane_document_text_limit', 20000 ) ) );
	}

	/**
	 * An amount in the currency's minor unit (cents), as the widget formats
	 * prices: by the currency's decimals, which Bitlane takes from the same
	 * locale data as WooCommerce's i18n/locale-info.php (2 for a currency it
	 * doesn't list). Not the store's "Number of decimals", a display setting:
	 * with 0, €18.00 would be 18 cents.
	 *
	 * @param float|string $amount The amount.
	 */
	public static function minor( float|string $amount ): int {
		static $decimals = null;
		if ( null === $decimals ) {
			$decimals = array_column( include WC()->plugin_path() . '/i18n/locale-info.php', 'num_decimals', 'currency_code' );
		}
		return (int) round( (float) $amount * ( 10 ** ( $decimals[ get_woocommerce_currency() ] ?? 2 ) ) );
	}
}
