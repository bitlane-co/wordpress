<?php
/**
 * What a source builds documents for.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

/**
 * The setup the documents are for: the filters the merchant picked for the
 * source, how products become documents, and the language.
 */
final class Context {

	/**
	 * Builds the context.
	 *
	 * @param string[] $facets            The picked filters (attributes) this source has.
	 * @param string   $product_documents Settings::PER_VARIATION or PER_PRODUCT.
	 * @param string   $language          The documents' language ("en-US").
	 */
	public function __construct(
		public readonly array $facets,
		public readonly string $product_documents,
		public readonly string $language,
	) {}
}
