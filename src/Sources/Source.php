<?php
/**
 * Something whose items become search documents.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

/**
 * A kind of content the setup screen offers and the sync keeps in Bitlane:
 * WooCommerce's products and each post type are built in; a developer adds
 * their own (a custom table, another plugin's data, an external API) with
 * the bitlane_document_sources filter. A source says when its items change
 * from watch(); code that writes them can also call
 * do_action( 'bitlane_sync_documents', $source_id, $item_ids ).
 *
 * Example, stores from a custom table:
 *
 *     add_filter( 'bitlane_document_sources', static function ( array $sources ) {
 *         $sources[] = new My_Store_Source(); // implements Source
 *         return $sources;
 *     } );
 *
 *     // In My_Store_Source:
 *     public function watch( callable $changed ): void {
 *         add_action( 'my_store_saved', fn( $id ) => $changed( array( $id ) ) );
 *         add_action( 'my_store_deleted', fn( $id ) => $changed( array( $id ) ) );
 *     }
 */
interface Source {

	/**
	 * The source's id: lowercase letters, digits, - and _ ("store"). Unique
	 * among sources; post types use their name.
	 */
	public function id(): string;

	/**
	 * Its name on the setup screen ("Stores").
	 */
	public function label(): string;

	/**
	 * The index its documents go to: Source::PRODUCTS (documents in
	 * widget_public.ProductDocumentV1's shape) or Source::OTHER
	 * (OtherDocumentV1's).
	 */
	public function kind(): string;

	/**
	 * How many items it has in a language, or in all of them (shown on the
	 * setup screen).
	 *
	 * @param string $language A language ("en-US"); empty for all.
	 */
	public function count( string $language = '' ): int;

	/**
	 * The filters its documents can have, by attribute (each document's
	 * facets.<attribute>): attribute => label. The merchant picks some on the
	 * setup screen.
	 *
	 * @return array<string, string>
	 */
	public function facets(): array;

	/**
	 * Item ids in a language for the full sync and the daily check, in a
	 * stable order: the next $limit after $after ("" for the first ones).
	 * Empty when there are no more. A source whose items aren't translated
	 * gives the same ids in every language.
	 *
	 * @param string $after    The last id of the previous batch.
	 * @param int    $limit    How many.
	 * @param string $language The language ("en-US").
	 * @return list<string>
	 */
	public function ids( string $after, int $limit, string $language ): array;

	/**
	 * Each item's documents in a language ($context->language), by item id,
	 * for the source's own index in that language; it runs in the language
	 * (WordPress's locale, and WPML's or Polylang's language). An item that's
	 * gone, not searchable or not in the language has none, and what it had
	 * there is deleted. A products source may give several per item
	 * (variations, sharing parent_id); any other gives at most one, whose id
	 * is the item's. The sync sets sync_run and keeps only the facets the
	 * merchant picked ($context->facets).
	 *
	 * @param string[] $ids     The items.
	 * @param Context  $context The setup.
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function documents( array $ids, Context $context ): array;

	/**
	 * When each item last changed, in Unix seconds, for the daily check: an
	 * item changed since its document was written (modified_at) syncs again,
	 * which catches changes no hook saw. Return an empty array when the
	 * source can't tell: the check then only looks for missing items. Set the
	 * same time as each document's modified_at in documents().
	 *
	 * @param string[] $ids The items.
	 * @return array<string, int>
	 */
	public function modified( array $ids ): array;

	/**
	 * Hooks into what tells when items change (saved, deleted, stock…) and
	 * calls $changed with their ids; they sync in the background. Called once
	 * a request, on init, while the site syncs.
	 *
	 * @param callable(array<string|int>): void $changed Takes changed item ids.
	 */
	public function watch( callable $changed ): void;

	public const PRODUCTS = 'products';
	public const OTHER    = 'other';
}
