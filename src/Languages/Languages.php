<?php
/**
 * The site's languages.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Languages;

use Bitlane\WordPress\Sources\PostIds;

/**
 * Each language has its own indexes (docs/wordpress-plan.md W7), with WPML's
 * or Polylang's languages, or the site's one. The multilingual plugin is
 * found on first use, once plugins have loaded; WPML wins when both are on,
 * and bitlane_languages_adapter can replace it.
 * A post of a translated type is in its own language's indexes (WPML and
 * Polylang make a translation another post); any other item, a developer's
 * included, is in every language's.
 */
final class Languages {

	/**
	 * Builds the languages.
	 *
	 * @param Adapter|null $adapter The multilingual plugin; found on first use when null.
	 */
	public function __construct( private ?Adapter $adapter = null ) {}

	/**
	 * The multilingual plugin's name ("WPML"), empty for a site in one language.
	 */
	public function plugin(): string {
		return $this->adapter()->name();
	}

	/**
	 * The languages, the default first.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function all(): array {
		return $this->adapter()->languages();
	}

	/**
	 * The languages' codes ("en-US"), the default first.
	 *
	 * @return list<string>
	 */
	public function codes(): array {
		return wp_list_pluck( $this->all(), 'code' );
	}

	/**
	 * The default language.
	 */
	public function default(): string {
		return $this->codes()[0] ?? str_replace( '_', '-', get_locale() );
	}

	/**
	 * The request's language, else the default.
	 */
	public function current(): string {
		$current = $this->adapter()->current();
		return '' !== $current ? $current : $this->default();
	}

	/**
	 * A post's language; empty when its type isn't translated, so it's in
	 * every language.
	 *
	 * @param int $post_id The post.
	 */
	public function of_post( int $post_id ): string {
		$type = get_post_type( $post_id );
		return $type && $this->adapter()->translated( $type ) ? $this->adapter()->post_language( $post_id ) : '';
	}

	/**
	 * Whether a post is in a language's indexes: it's in that language, or
	 * in every one.
	 *
	 * @param int    $post_id  The post.
	 * @param string $language The language.
	 */
	public function has_post( int $post_id, string $language ): bool {
		$of = $this->of_post( $post_id );
		return '' === $of || 0 === strcasecmp( $of, $language );
	}

	/**
	 * A post type's published posts in a language, by id: the next $limit
	 * after $after.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language.
	 * @param int    $after     The last id of the previous batch, 0 at first.
	 * @param int    $limit     How many.
	 * @return list<string>
	 */
	public function post_ids( string $post_type, string $language, int $after, int $limit ): array {
		return $this->adapter()->translated( $post_type )
			? $this->adapter()->post_ids( $post_type, $language, $after, $limit )
			: PostIds::after( $post_type, $after, $limit );
	}

	/**
	 * How many published posts of a type a language has; every language's
	 * for an empty one.
	 *
	 * @param string $post_type The post type.
	 * @param string $language  The language, or empty.
	 */
	public function post_count( string $post_type, string $language = '' ): int {
		return '' !== $language && $this->adapter()->translated( $post_type )
			? $this->adapter()->post_count( $post_type, $language )
			: (int) ( wp_count_posts( $post_type )->publish ?? 0 );
	}

	/**
	 * Runs code in a language: the multilingual plugin's (terms, links) and
	 * WordPress's locale (texts, dates, labels), both restored after.
	 *
	 * @template T
	 * @param string        $language The language.
	 * @param callable(): T $run      The code.
	 * @return T
	 */
	public function in( string $language, callable $run ): mixed {
		$was    = $this->adapter()->switch_to( $language );
		$locale = switch_to_locale( str_replace( '-', '_', $language ) );
		try {
			return $run();
		} finally {
			if ( $locale ) {
				restore_previous_locale();
			}
			$this->adapter()->switch_to( $was );
		}
	}

	/**
	 * The multilingual plugin, found once.
	 */
	private function adapter(): Adapter {
		if ( null === $this->adapter ) {
			// Polylang answers WPML's hooks too, but doesn't define WPML's version.
			if ( defined( 'ICL_SITEPRESS_VERSION' ) && apply_filters( 'wpml_active_languages', null ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's.
				$found = new Wpml();
			} elseif ( function_exists( 'pll_languages_list' ) && did_action( 'pll_init' ) && pll_languages_list() ) {
				$found = new Polylang();
			} else {
				$found = new SingleLanguage();
			}

			/**
			 * The site's languages, as the sync reads them: another
			 * multilingual plugin, or a site's own setup.
			 *
			 * @param Adapter $adapter WPML's, Polylang's, or the site's one language.
			 */
			$this->adapter = apply_filters( 'bitlane_languages_adapter', $found );
		}
		return $this->adapter;
	}
}
