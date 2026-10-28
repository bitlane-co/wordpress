<?php
/**
 * WPML's languages.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Languages;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hooks.

/**
 * WPML through its hooks (wpml_active_languages, wpml_switch_language…).
 * Each translation is its own post, with its language in WPML's
 * icl_translations table. WPML's codes ("en", "pt-br") become their locales
 * ("en-US", "pt-BR"), as pages declare them.
 */
final class Wpml implements Adapter {

	/**
	 * WPML.
	 */
	public function name(): string {
		return 'WPML';
	}

	/**
	 * WPML's active languages, by their own names.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array {
		$default   = (string) apply_filters( 'wpml_default_language', null );
		$languages = array();
		foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $wpml => $language ) {
			$entry = array(
				'code' => $this->code( (string) $wpml, (array) $language ),
				'name' => (string) ( $language['native_name'] ?? $wpml ),
			);
			if ( $wpml === $default ) {
				array_unshift( $languages, $entry );
			} else {
				$languages[] = $entry;
			}
		}
		return $languages;
	}

	/**
	 * WPML's current language; none while it shows all of them.
	 */
	public function current(): string {
		return $this->from_wpml( (string) apply_filters( 'wpml_current_language', null ) );
	}

	/**
	 * Whether WPML translates the post type.
	 *
	 * @param string $post_type The post type.
	 */
	public function translated( string $post_type ): bool {
		return (bool) apply_filters( 'wpml_is_translated_post_type', null, $post_type );
	}

	/**
	 * The post's language in WPML.
	 *
	 * @param int $post_id The post.
	 */
	public function post_language( int $post_id ): string {
		$wpml = apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => (string) get_post_type( $post_id ),
			)
		);
		return $this->from_wpml( (string) $wpml );
	}

	/**
	 * The posts WPML has in the language.
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
				INNER JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = %s AND t.language_code = %s
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID LIMIT %d",
				'post_' . $post_type,
				$this->to_wpml( $language ),
				$post_type,
				$after,
				$limit
			)
		);
		return array_map( 'strval', $ids );
	}

	/**
	 * How many posts WPML has in the language.
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
				INNER JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = %s AND t.language_code = %s
				WHERE p.post_type = %s AND p.post_status = 'publish'",
				'post_' . $post_type,
				$this->to_wpml( $language ),
				$post_type
			)
		);
	}

	/**
	 * Switches WPML's language.
	 *
	 * @param string $language The language; empty for WPML's own choice.
	 */
	public function switch_to( string $language ): string {
		$was = $this->current();
		do_action( 'wpml_switch_language', '' !== $language ? $this->to_wpml( $language ) : null );
		return $was;
	}

	/**
	 * A WPML language's code: its locale, or WPML's code without one.
	 *
	 * @param string               $wpml     WPML's code.
	 * @param array<string, mixed> $language WPML's language.
	 */
	private function code( string $wpml, array $language ): string {
		$locale = (string) ( $language['default_locale'] ?? '' );
		return str_replace( '_', '-', '' !== $locale ? $locale : $wpml );
	}

	/**
	 * Our code of WPML's ("en" → "en-US"); empty for none, or "all".
	 *
	 * @param string $wpml WPML's code.
	 */
	private function from_wpml( string $wpml ): string {
		$active = (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		return isset( $active[ $wpml ] ) ? $this->code( $wpml, (array) $active[ $wpml ] ) : '';
	}

	/**
	 * WPML's code of ours ("en-US" → "en").
	 *
	 * @param string $language Our code.
	 */
	private function to_wpml( string $language ): string {
		foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $wpml => $active ) {
			if ( 0 === strcasecmp( $this->code( (string) $wpml, (array) $active ), $language ) ) {
				return (string) $wpml;
			}
		}
		return $language;
	}
}
