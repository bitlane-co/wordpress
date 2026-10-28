<?php
/**
 * Published post ids in batches, and when posts changed.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sources;

/**
 * Pages through a post type's published posts by id, for the full sync:
 * WP_Query can't start after an id. Reads when a batch of posts last changed
 * in one query, for the daily check.
 */
final class PostIds {

	/**
	 * The next published ids of a post type after one.
	 *
	 * @param string $post_type The post type.
	 * @param int    $after     The last id of the previous batch, 0 at first.
	 * @param int    $limit     How many.
	 * @return list<string>
	 */
	public static function after( string $post_type, int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batches of a full sync, read once.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID LIMIT %d",
				$post_type,
				$after,
				$limit
			)
		);
		return array_map( 'strval', $ids );
	}

	/**
	 * When each post last changed, in Unix seconds; with its children
	 * (a product's variations), the latest of them all.
	 *
	 * @param string[] $ids      The posts.
	 * @param string   $children The children's post type, or empty.
	 * @return array<string, int>
	 */
	public static function modified( array $ids, string $children = '' ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ', ', $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the daily check's batches, read once; $in holds integers.
		$rows = '' === $children
			? $wpdb->get_results( "SELECT ID AS id, post_modified_gmt AS modified FROM {$wpdb->posts} WHERE ID IN ($in)" )
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS id, GREATEST(p.post_modified_gmt, COALESCE(MAX(c.post_modified_gmt), p.post_modified_gmt)) AS modified
					FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} c ON c.post_parent = p.ID AND c.post_type = %s
					WHERE p.ID IN ($in) GROUP BY p.ID, p.post_modified_gmt",
					$children
				)
			);
		// phpcs:enable
		$modified = array();
		foreach ( (array) $rows as $row ) {
			$modified[ (string) $row->id ] = (int) strtotime( $row->modified . ' UTC' );
		}
		return $modified;
	}
}
