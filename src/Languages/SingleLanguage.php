<?php
/**
 * A site in one language.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Languages;

use Bitlane\WordPress\Sources\PostIds;

/**
 * The site's language (Settings › General), and nothing translated.
 */
final class SingleLanguage implements Adapter {

	/**
	 * None.
	 */
	public function name(): string {
		return '';
	}

	/**
	 * The site's language, named in the admin's.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array {
		$locale = get_locale();
		$name   = class_exists( \Locale::class ) ? (string) \Locale::getDisplayName( $locale, get_user_locale() ) : '';
		return array(
			array(
				'code' => str_replace( '_', '-', $locale ),
				'name' => '' !== $name ? $name : $locale,
			),
		);
	}

	/**
	 * None: the default.
	 */
	public function current(): string {
		return '';
	}

	/**
	 * Nothing is.
	 *
	 * @param string $post_type The post type.
	 */
	public function translated( string $post_type ): bool {
		return false;
	}

	/**
	 * None.
	 *
	 * @param int $post_id The post.
	 */
	public function post_language( int $post_id ): string {
		return '';
	}

	/**
	 * All of them.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 * @param int    $after     The last id of the previous batch.
	 * @param int    $limit     How many.
	 * @return list<string>
	 */
	public function post_ids( string $post_type, string $language, int $after, int $limit ): array {
		return PostIds::after( $post_type, $after, $limit );
	}

	/**
	 * All of them.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 */
	public function post_count( string $post_type, string $language ): int {
		return (int) ( wp_count_posts( $post_type )->publish ?? 0 );
	}

	/**
	 * Nothing to switch.
	 *
	 * @param string $language The language.
	 */
	public function switch_to( string $language ): string {
		return '';
	}
}
