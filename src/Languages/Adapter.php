<?php
/**
 * A multilingual plugin, as the sync needs it.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Languages;

/**
 * What Languages asks of WPML, Polylang or a site in one language. Language
 * codes are locales as pages declare them ("en-US"): the setup sends them,
 * index names hold them, and the widget matches them to <html lang>.
 */
interface Adapter {

	/**
	 * The plugin's name for the admin screen ("WPML"); empty for none.
	 */
	public function name(): string;

	/**
	 * The site's languages, the default first.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array;

	/**
	 * The request's language, empty when it has none.
	 */
	public function current(): string;

	/**
	 * Whether each post of a type has one language, its translations being
	 * other posts. Posts of other types are in every language.
	 *
	 * @param string $post_type The post type.
	 */
	public function translated( string $post_type ): bool;

	/**
	 * A post's language, empty when it has none.
	 *
	 * @param int $post_id The post, of a translated type.
	 */
	public function post_language( int $post_id ): string;

	/**
	 * Published posts of a translated type in a language, by id: the next
	 * $limit after $after.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 * @param int    $after     The last id of the previous batch, 0 at first.
	 * @param int    $limit     How many.
	 * @return list<string>
	 */
	public function post_ids( string $post_type, string $language, int $after, int $limit ): array;

	/**
	 * How many published posts of a translated type a language has.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 */
	public function post_count( string $post_type, string $language ): int;

	/**
	 * Makes a language the request's (its terms, links and texts), returning
	 * the one it was.
	 *
	 * @param string $language The language.
	 */
	public function switch_to( string $language ): string;
}
