<?php
/**
 * A post type as a source of "other" documents.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

use Bitlane\WordPress\Documents\OtherDocument;
use Bitlane\WordPress\Languages\Languages;
use WP_Post_Type;

/**
 * Posts, pages and custom post types: one document each, filters from the
 * type's public taxonomies. A translated post is in its language only.
 */
final class PostTypeSource implements Source {

	/**
	 * Takes changed post ids, from watch().
	 *
	 * @var (callable(array<string|int>): void)|null
	 */
	private $changed = null;

	/**
	 * Builds the source.
	 *
	 * @param WP_Post_Type  $type      The post type.
	 * @param OtherDocument $builder   Builds the documents.
	 * @param Languages     $languages The posts' languages.
	 */
	public function __construct(
		private WP_Post_Type $type,
		private OtherDocument $builder,
		private Languages $languages,
	) {}

	/**
	 * The post type's name.
	 */
	public function id(): string {
		return $this->type->name;
	}

	/**
	 * The post type's plural name.
	 */
	public function label(): string {
		return (string) $this->type->labels->name;
	}

	/**
	 * Other.
	 */
	public function kind(): string {
		return self::OTHER;
	}

	/**
	 * Published posts.
	 *
	 * @param string $language A language; empty for all.
	 */
	public function count( string $language = '' ): int {
		return $this->languages->post_count( $this->type->name, $language );
	}

	/**
	 * Its taxonomies that can be filters.
	 *
	 * @return array<string, string>
	 */
	public function facets(): array {
		return TaxonomyFacets::of( $this->type->name );
	}

	/**
	 * Published posts in the language by id.
	 *
	 * @param string $after    The last id of the previous batch.
	 * @param int    $limit    How many.
	 * @param string $language The language.
	 * @return list<string>
	 */
	public function ids( string $after, int $limit, string $language ): array {
		return $this->languages->post_ids( $this->type->name, $language, (int) $after, $limit );
	}

	/**
	 * One document per post, none for one that's gone, not searchable or in
	 * another language.
	 *
	 * @param string[] $ids     The posts.
	 * @param Context  $context The setup.
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function documents( array $ids, Context $context ): array {
		$modified = $this->modified( $ids );
		$docs     = array();
		foreach ( $ids as $id ) {
			$post        = get_post( (int) $id );
			$doc         = $post && $post->post_type === $this->type->name && $this->languages->has_post( $post->ID, $context->language ) ? $this->builder->build( $post, $context->facets ) : null;
			$docs[ $id ] = $doc ? array( array_merge( $doc, array( 'modified_at' => $modified[ (string) $id ] ?? 0 ) ) ) : array();
		}
		return $docs;
	}

	/**
	 * When each post last changed.
	 *
	 * @param string[] $ids The posts.
	 * @return array<string, int>
	 */
	public function modified( array $ids ): array {
		return PostIds::modified( $ids );
	}

	/**
	 * Saved, published or unpublished, trashed, restored or deleted posts of
	 * the type.
	 *
	 * @param callable(array<string|int>): void $changed Takes changed post ids.
	 */
	public function watch( callable $changed ): void {
		$this->changed = $changed;

		add_action( 'save_post_' . $this->type->name, array( $this, 'post_changed' ), 20 );
		add_action( 'trashed_post', array( $this, 'post_changed' ) );
		add_action( 'untrashed_post', array( $this, 'post_changed' ) );
		add_action( 'transition_post_status', array( $this, 'status_changed' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'post_deleted' ), 10, 2 );
	}

	/**
	 * A post changed, when it's of the type.
	 *
	 * @param int|\WP_Post $post The post.
	 */
	public function post_changed( $post ): void {
		$post = get_post( $post );
		if ( $this->changed && $post && $this->type->name === $post->post_type && ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post ) ) {
			( $this->changed )( array( $post->ID ) );
		}
	}

	/**
	 * A post was published, unpublished or scheduled.
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old one.
	 * @param \WP_Post $post       The post.
	 */
	public function status_changed( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status ) {
			$this->post_changed( $post );
		}
	}

	/**
	 * A post was deleted: only the post as it was says its type.
	 *
	 * @param int           $post_id The post.
	 * @param \WP_Post|null $post    The post as it was.
	 */
	public function post_deleted( $post_id, $post = null ): void {
		if ( $post ) {
			$this->post_changed( $post );
		}
	}
}
