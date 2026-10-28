<?php
/**
 * The merchant's choices on the setup screen.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Site;

/**
 * What was set up, kept to show it again and for the sync: the engine, the
 * post types and taxonomies, the theme's search input, and product_documents,
 * how a product becomes search documents (docs/wordpress-plan.md,
 * "Documents"). The widget shows each product once either way; what differs
 * is how exactly filters match its variations.
 */
final class Settings {

	public const OPTION = 'bitlane_settings';

	/**
	 * One document per product, its variations folded in: the fewest
	 * documents, but filters combine values of different variations (a red
	 * S and a blue XL match "red + XL", and stock is the product's).
	 */
	public const PER_PRODUCT = 'per_product';

	/**
	 * One document per variation: color, size and stock filter exactly
	 * ("red + XL + in stock" needs a red XL in stock), with more documents
	 * (counted in the plan's records). The default.
	 */
	public const PER_VARIATION = 'per_variation';

	public const PRODUCT_DOCUMENTS = array( self::PER_PRODUCT, self::PER_VARIATION );

	/**
	 * Builds the settings.
	 *
	 * @param Site $site The site, for the defaults.
	 */
	public function __construct( private Site $site ) {}

	/**
	 * The settings, the defaults for what's not set, and the logo's address.
	 *
	 * @return array{engine_id: ?string, sources: list<string>, outside_widget: list<string>, facets: list<string>, sorting: array<string, list<string>>, search_selector: string, brand_color: string, logo_id: int, logo_url: string, product_documents: string, languages: list<string>}
	 */
	public function get(): array {
		$stored   = get_option( self::OPTION, array() );
		$settings = array_merge(
			array(
				'engine_id'         => null,
				// Sources synced for the site's own use, which widgets don't search.
				'outside_widget'    => array(),
				// Each source's sort choices, in order (Site::sorting()); a source
				// without an entry has its kind's defaults.
				'sorting'           => array(),
				'product_documents' => self::PER_VARIATION,
				// The languages Bitlane was last sent.
				'languages'         => array(),
			),
			$this->site->default_setup(),
			is_array( $stored ) ? $stored : array()
		);

		$settings['logo_url'] = self::logo_url( (int) $settings['logo_id'] );
		return $settings;
	}

	/**
	 * The address of a logo the widget shows at 24px high: the medium size
	 * (300px), plenty on high-density screens. Empty when it isn't an image.
	 *
	 * @param int $id The attachment.
	 */
	public static function logo_url( int $id ): string {
		return $id && wp_attachment_is_image( $id ) ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
	}

	/**
	 * What a source's documents depend on, as a short hash: a new one means
	 * a full sync of the source. Its picked filters, the languages (a new
	 * one has empty indexes), and for products how they become documents.
	 *
	 * @param string $source The source's id.
	 */
	public function hash( string $source ): string {
		$settings = $this->get();
		$facets   = array_values( array_intersect( $settings['facets'], wp_list_pluck( $this->site->facets( array( $source ) ), 'attribute' ) ) );
		return md5( (string) wp_json_encode( array( $source, $facets, $settings['languages'], 'product' === $source ? $settings['product_documents'] : null ) ) );
	}

	/**
	 * A product's document id, for purchases and additions to the cart: the
	 * variation's when each variation is a document, the product's otherwise.
	 *
	 * @param int $product_id   The product.
	 * @param int $variation_id The variation, 0 for none.
	 */
	public function document_id( int $product_id, int $variation_id ): string {
		$per_variation = self::PER_VARIATION === $this->get()['product_documents'];
		return (string) ( $per_variation && $variation_id ? $variation_id : $product_id );
	}

	/**
	 * Saves the settings.
	 *
	 * @param array<string, mixed> $settings The settings.
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}
}
