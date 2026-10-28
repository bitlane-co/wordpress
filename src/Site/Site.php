<?php
/**
 * What the site has to search: its languages, currency, content and filters.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Site;

use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Sources\Sources;

/**
 * The site as the setup screen offers it. Products are WooCommerce's;
 * other public post types (posts, pages, custom ones) go to the "other"
 * index (docs/wordpress-plan.md, "Documents"). Each language, WPML's or
 * Polylang's or the site's one, has its own indexes.
 */
final class Site {

	/**
	 * Builds the site.
	 *
	 * @param Sources   $sources   The sources of documents.
	 * @param Languages $languages The languages.
	 */
	public function __construct(
		private Sources $sources,
		private Languages $languages,
	) {}

	/**
	 * The storefront's address.
	 */
	public function url(): string {
		return home_url();
	}

	/**
	 * The site's name, or its host without one.
	 */
	public function name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * The languages as pages declare them ("en-US", "it-IT"), the default
	 * first.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array {
		return $this->languages->all();
	}

	/**
	 * The multilingual plugin ("WPML", "Polylang"), empty for none.
	 */
	public function multilingual(): string {
		return $this->languages->plugin();
	}

	/**
	 * The currency of the prices.
	 */
	public function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', 'EUR' );
	}

	/**
	 * How the store shows prices (WooCommerce's currency options), which the
	 * widget follows; null without WooCommerce: the currency's usual format.
	 *
	 * @return array{position: string, thousand_sep: string, decimal_sep: string, decimals: int}|null
	 */
	public function currency_format(): ?array {
		if ( ! function_exists( 'wc_get_price_decimals' ) ) {
			return null;
		}
		$position = (string) get_option( 'woocommerce_currency_pos', 'left' );
		return array(
			'position'     => in_array( $position, array( 'left', 'right', 'left_space', 'right_space' ), true ) ? $position : 'left',
			'thousand_sep' => wc_get_price_thousand_separator(),
			'decimal_sep'  => wc_get_price_decimal_separator(),
			'decimals'     => min( 4, max( 0, wc_get_price_decimals() ) ),
		);
	}

	/**
	 * The content that can be searched (Sources): products first, then post
	 * types and developers' sources.
	 *
	 * @return list<array{id: string, label: string, kind: string, count: int}>
	 */
	public function sources(): array {
		return array_values(
			array_map(
				static fn( $source ) => array(
					'id'    => $source->id(),
					'label' => $source->label(),
					'kind'  => $source->kind(),
					'count' => $source->count(),
				),
				$this->sources->all()
			)
		);
	}

	/**
	 * The filters the given content can have, each once with the sources
	 * that have it (a taxonomy can belong to several post types), named in
	 * the current language.
	 *
	 * @param string[] $source_ids The sources.
	 * @return list<array{attribute: string, label: string, sources: list<string>}>
	 */
	public function facets( array $source_ids ): array {
		$facets = array();
		foreach ( $this->sources->all() as $id => $source ) {
			if ( ! in_array( $id, $source_ids, true ) ) {
				continue;
			}
			foreach ( $source->facets() as $attribute => $label ) {
				$facets[ $attribute ]            ??= array(
					'attribute' => (string) $attribute,
					'label'     => $label,
					'sources'   => array(),
				);
				$facets[ $attribute ]['sources'][] = $id;
			}
		}
		return array_values( $facets );
	}

	/**
	 * The theme's colors (theme.json's palette) by slug, to pick the brand
	 * color from.
	 *
	 * @return array<string, array{name: string, color: string}>
	 */
	public function palette(): array {
		$colors = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $color ) {
			$hex = is_array( $color ) ? sanitize_hex_color( (string) ( $color['color'] ?? '' ) ) : null;
			if ( $hex ) {
				$colors[ (string) ( $color['slug'] ?? $hex ) ] = array(
					'name'  => (string) ( $color['name'] ?? $hex ),
					'color' => $hex,
				);
			}
		}
		return $colors;
	}

	/**
	 * The theme's brand color: its buttons' background, else its links'
	 * color, as global styles set them (block themes). Empty when neither is
	 * a color, as in most classic themes.
	 */
	public function brand_color(): string {
		$slugs = wp_list_pluck( $this->palette(), 'color' );
		foreach ( array( array( 'button', 'background' ), array( 'link', 'text' ) ) as [ $element, $property ] ) {
			$value = (string) wp_get_global_styles( array( 'elements', $element, 'color', $property ) );
			// A palette color: var(--wp--preset--color--<slug>) or var:preset|color|<slug>.
			if ( preg_match( '/(?:--wp--preset--color--|var:preset\|color\|)([a-z0-9-]+)/', $value, $match ) ) {
				$value = (string) ( $slugs[ $match[1] ] ?? '' );
			}
			$hex = sanitize_hex_color( $value );
			if ( $hex ) {
				return $hex;
			}
		}
		return '';
	}

	/**
	 * The site's logo, an attachment: the Customizer's (classic themes) or
	 * the Site Logo block's. 0 without one.
	 */
	public function logo_id(): int {
		$id = (int) get_theme_mod( 'custom_logo' );
		return $id ? $id : (int) get_option( 'site_logo' );
	}

	/**
	 * The widget's sort choices a source can have, by its kind, named in the
	 * admin's language, and those it starts with. The ids are Bitlane's
	 * (server/integration/presets.go), which names them in the widget and
	 * sorts ties by id.
	 *
	 * @return array<string, array{choices: list<array{id: string, label: string}>, defaults: list<string>}>
	 */
	public function sorting(): array {
		$both = array(
			array(
				'id'    => 'relevance',
				'label' => __( 'Relevance', 'bitlane' ),
			),
			array(
				'id'    => 'newest',
				'label' => __( 'Newest', 'bitlane' ),
			),
			array(
				'id'    => 'oldest',
				'label' => __( 'Oldest', 'bitlane' ),
			),
			array(
				'id'    => 'title_asc',
				'label' => __( 'Name: A to Z', 'bitlane' ),
			),
		);
		return array(
			'products' => array(
				'choices'  => array_merge(
					$both,
					array(
						array(
							'id'    => 'price_asc',
							'label' => __( 'Price: low to high', 'bitlane' ),
						),
						array(
							'id'    => 'price_desc',
							'label' => __( 'Price: high to low', 'bitlane' ),
						),
						array(
							'id'    => 'best_selling',
							'label' => __( 'Best selling', 'bitlane' ),
						),
						array(
							'id'    => 'top_rated',
							'label' => __( 'Top rated', 'bitlane' ),
						),
					)
				),
				'defaults' => array( 'relevance', 'price_asc', 'price_desc', 'newest', 'best_selling' ),
			),
			'other'    => array(
				'choices'  => $both,
				'defaults' => array( 'relevance', 'newest' ),
			),
		);
	}

	/**
	 * What the setup screen starts with: products and posts, the filters
	 * shoppers use most (the price first), and the theme's brand color and
	 * logo.
	 *
	 * @return array{sources: list<string>, facets: list<string>, search_selector: string, brand_color: string, logo_id: int}
	 */
	public function default_setup(): array {
		$sources = array_values( array_intersect( array( 'product', 'post' ), wp_list_pluck( $this->sources(), 'id' ) ) );
		$facets  = array_values(
			array_filter(
				wp_list_pluck( $this->facets( $sources ), 'attribute' ),
				static fn( $attribute ) => in_array( $attribute, array( 'price', 'product_cat', 'product_brand', 'category' ), true ) || str_starts_with( $attribute, 'pa_' )
			)
		);
		return array(
			'sources'         => $sources,
			'facets'          => $facets,
			// The classic search form, the Search block (its field and its
			// button) and WooCommerce's product search.
			'search_selector' => 'input[name="s"], .wp-block-search__input, .wp-block-search__button, .wc-block-product-search__field',
			'brand_color'     => $this->brand_color(),
			'logo_id'         => $this->logo_id(),
		);
	}
}
