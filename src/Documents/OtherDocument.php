<?php
/**
 * A post, page or other post type as an "other" index document.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Documents;

use WP_Post;

/**
 * Builds widget_public.OtherDocumentV1: what it is (the post type), a line
 * under the title (a post's date), a short description (the excerpt), the
 * featured image and the text. Each step has a filter for developers
 * (bitlane_other_*, bitlane_post_is_searchable).
 */
final class OtherDocument {

	/**
	 * The post's document; null when it isn't searchable.
	 *
	 * @param WP_Post  $post       The post.
	 * @param string[] $taxonomies The chosen filters its type has.
	 * @return array<string, mixed>|null
	 */
	public function build( WP_Post $post, array $taxonomies ): ?array {
		if ( ! $this->searchable( $post ) ) {
			return null;
		}

		$type = get_post_type_object( $post->post_type );

		/**
		 * What a post is, shown above its title and filterable ("News",
		 * "Store").
		 *
		 * @param string   $type By default the post type's singular name.
		 * @param \WP_Post $post The post.
		 */
		$label = (string) apply_filters( 'bitlane_other_document_type', $type ? (string) $type->labels->singular_name : $post->post_type, $post );

		/**
		 * The line under a post's title: a date, an address, an author.
		 *
		 * @param string   $subtitle By default a post's date, nothing for other types.
		 * @param \WP_Post $post     The post.
		 */
		$subtitle = (string) apply_filters( 'bitlane_other_document_subtitle', 'post' === $post->post_type ? (string) get_the_date( '', $post ) : '', $post );

		/**
		 * The image size of cards for posts, pages and other content.
		 *
		 * @param string   $size A registered size; by default medium.
		 * @param \WP_Post $post The post.
		 */
		$size = (string) apply_filters( 'bitlane_other_image_size', 'medium', $post );

		$doc = array(
			'id'                => (string) $post->ID,
			'title'             => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'permalink'         => (string) get_permalink( $post ),
			'type'              => $label,
			'subtitle'          => $subtitle,
			'short_description' => Fields::text( has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 40, '…' ) ),
			'image'             => Fields::image( (int) get_post_thumbnail_id( $post ), $size ),
			'content'           => Fields::text( $post->post_content ),
			'facets'            => (object) Fields::terms( $post->ID, $taxonomies ),
			'created_at'        => (int) get_post_time( 'U', true, $post ),
			'menu_order'        => $post->menu_order,
		);
		$doc = array_filter( $doc, static fn( $value ) => null !== $value && '' !== $value );

		/**
		 * An "other" index document before it's sent: change it (keep the
		 * schema, widget_public.OtherDocumentV1), or return null to leave it
		 * out.
		 *
		 * @param array<string, mixed>|null $doc  The document.
		 * @param \WP_Post                  $post The post.
		 */
		$doc = apply_filters( 'bitlane_other_document', $doc, $post );
		return is_array( $doc ) ? $doc : null;
	}

	/**
	 * Whether the post can be found in the search.
	 *
	 * @param WP_Post $post The post.
	 */
	public function searchable( WP_Post $post ): bool {
		/**
		 * Whether a post, page or other content is in the search. By
		 * default: published and without a password.
		 *
		 * @param bool     $searchable Whether it's in the search.
		 * @param \WP_Post $post       The post.
		 */
		return (bool) apply_filters( 'bitlane_post_is_searchable', 'publish' === $post->post_status && '' === $post->post_password, $post );
	}
}
