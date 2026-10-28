<?php
/**
 * Polylang's languages.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Languages;

/**
 * Polylang through its functions (pll_languages_list, pll_get_post_language…).
 * Each translation is its own post, its language a term of Polylang's
 * language taxonomy (slug "en"). Languages are named by
 * their locales ("en-US"), as pages declare them.
 */
final class Polylang implements Adapter {

	/**
	 * Polylang.
	 */
	public function name(): string {
		return 'Polylang';
	}

	/**
	 * Polylang's languages, by their own names.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array {
		$default   = (string) pll_default_language( 'locale' );
		$names     = pll_languages_list( array( 'fields' => 'name' ) );
		$languages = array();
		foreach ( pll_languages_list( array( 'fields' => 'locale' ) ) as $i => $locale ) {
			$entry = array(
				'code' => str_replace( '_', '-', $locale ),
				'name' => (string) ( $names[ $i ] ?? $locale ),
			);
			if ( $locale === $default ) {
				array_unshift( $languages, $entry );
			} else {
				$languages[] = $entry;
			}
		}
		return $languages;
	}

	/**
	 * Polylang's current language.
	 */
	public function current(): string {
		return str_replace( '_', '-', (string) pll_current_language( 'locale' ) );
	}

	/**
	 * Whether Polylang translates the post type.
	 *
	 * @param string $post_type The post type.
	 */
	public function translated( string $post_type ): bool {
		return (bool) pll_is_translated_post_type( $post_type );
	}

	/**
	 * The post's language in Polylang.
	 *
	 * @param int $post_id The post.
	 */
	public function post_language( int $post_id ): string {
		return str_replace( '_', '-', (string) pll_get_post_language( $post_id, 'locale' ) );
	}

	/**
	 * The posts with the language's term.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 * @param int    $after     The last id of the previous batch.
	 * @param int    $limit     How many.
	 * @return list<string>
	 */
	public function post_ids( string $post_type, string $language, int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batches of a full sync, read once.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID LIMIT %d",
				$this->term( $language ),
				$post_type,
				$after,
				$limit
			)
		);
		return array_map( 'strval', $ids );
	}

	/**
	 * How many posts have the language's term.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 */
	public function post_count( string $post_type, string $language ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a full sync's total, read once.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				WHERE p.post_type = %s AND p.post_status = 'publish'",
				$this->term( $language ),
				$post_type
			)
		);
	}

	/**
	 * Switches Polylang's current language, which its filters of terms and
	 * links follow.
	 *
	 * @param string $language The language; empty for none.
	 */
	public function switch_to( string $language ): string {
		$was           = $this->current();
		PLL()->curlang = '' !== $language ? PLL()->model->get_language( $this->slug( $language ) ) : null;
		return $was;
	}

	/**
	 * The language's term in Polylang's language taxonomy (term_taxonomy_id),
	 * so a post's language is one primary key lookup; 0 for none.
	 *
	 * @param string $language The language.
	 */
	private function term( string $language ): int {
		$term = get_term_by( 'slug', $this->slug( $language ), 'language' );
		return $term instanceof \WP_Term ? $term->term_taxonomy_id : 0;
	}

	/**
	 * Polylang's slug of a language ("en-US" → "en").
	 *
	 * @param string $language The language.
	 */
	private function slug( string $language ): string {
		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
		foreach ( pll_languages_list( array( 'fields' => 'locale' ) ) as $i => $locale ) {
			if ( 0 === strcasecmp( str_replace( '_', '-', $locale ), $language ) ) {
				return (string) ( $slugs[ $i ] ?? '' );
			}
		}
		return '';
	}
}
