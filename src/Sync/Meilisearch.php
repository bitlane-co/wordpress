<?php
/**
 * The few Meilisearch calls the sync makes.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Support\Http;
use WP_Error;

/**
 * Writes the site's documents with the write key from Credentials. No SDK
 * (docs/wordpress-plan.md W10). Writes are Meilisearch tasks: they're queued
 * there, and the sync doesn't wait for them. An error carries the status and
 * Retry-After in its data, which Queue retries on.
 */
final class Meilisearch {

	/**
	 * Builds the client.
	 *
	 * @param Http $http HTTP.
	 */
	public function __construct( private Http $http ) {}

	/**
	 * Adds or replaces documents, in requests of at most 8 MB: 50 products
	 * with their variations, each document with the descriptions, can be
	 * tens of MB, and engines are known to take 10 MB bodies through the load
	 * balancer. A document bigger than that goes on its own. The first
	 * failure stops it; a retry sends them all again (an upsert).
	 *
	 * @param array{url: string, key: string, index: string} $index     The index.
	 * @param list<array<string, mixed>>                     $documents The documents.
	 * @return true|WP_Error
	 */
	public function add( array $index, array $documents ): bool|WP_Error {
		$chunks = array();
		$chunk  = array();
		$size   = 0;
		foreach ( $documents as $document ) {
			// Its JSON and the comma between documents.
			$bytes = strlen( (string) wp_json_encode( $document ) ) + 1;
			if ( $chunk && $size + $bytes > 8000000 ) {
				$chunks[] = $chunk;
				$chunk    = array();
				$size     = 0;
			}
			$chunk[] = $document;
			$size   += $bytes;
		}
		if ( $chunk ) {
			$chunks[] = $chunk;
		}

		foreach ( $chunks as $chunk ) {
			$sent = $this->write( $index, 'POST', '/documents?primaryKey=id', $chunk );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}
		}
		return true;
	}

	/**
	 * Deletes documents by id.
	 *
	 * @param array{url: string, key: string, index: string} $index The index.
	 * @param string[]                                       $ids   The ids.
	 * @return true|WP_Error
	 */
	public function delete( array $index, array $ids ): bool|WP_Error {
		if ( ! $ids ) {
			return true;
		}
		return $this->write( $index, 'POST', '/documents/delete-batch', $ids );
	}

	/**
	 * Deletes the documents a filter matches.
	 *
	 * @param array{url: string, key: string, index: string} $index  The index.
	 * @param string                                         $filter A Meilisearch filter.
	 * @return true|WP_Error
	 */
	public function delete_where( array $index, string $filter ): bool|WP_Error {
		return $this->write( $index, 'POST', '/documents/delete', array( 'filter' => $filter ) );
	}

	/**
	 * The ids of the documents a filter matches.
	 *
	 * @param array{url: string, key: string, index: string} $index  The index.
	 * @param string                                         $filter A Meilisearch filter.
	 * @return list<string>|WP_Error
	 */
	public function ids_where( array $index, string $filter ): array|WP_Error {
		$res = $this->send(
			$index,
			'POST',
			'/documents/fetch',
			array(
				'filter' => $filter,
				'fields' => array( 'id' ),
				'limit'  => 1000,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_values( array_map( static fn( $document ) => (string) $document['id'], (array) ( $res['results'] ?? array() ) ) );
	}

	/**
	 * Which of a source's items the index has, when their documents say each
	 * changed (modified_at, 0 without one; a product's latest), and how many
	 * documents those are: products by parent_id (several documents an
	 * item), other content by id. Only those two fields come back.
	 *
	 * @param array{url: string, key: string, index: string} $index    The index.
	 * @param bool                                           $products A products source.
	 * @param string[]                                       $ids      The items.
	 * @return array{items: list<string>, modified: array<string, int>, documents: int}|WP_Error
	 */
	public function present( array $index, bool $products, array $ids ): array|WP_Error {
		$field    = $products ? 'parent_id' : 'id';
		$body     = $products
			? array( 'filter' => 'parent_id IN [' . implode( ', ', array_map( static fn( $id ) => (string) wp_json_encode( (string) $id ), $ids ) ) . ']' )
			: array( 'ids' => array_map( 'strval', $ids ) );
		$modified = array();
		for ( $offset = 0, $documents = 0; ; $offset += 10000 ) {
			$res = $this->send(
				$index,
				'POST',
				'/documents/fetch',
				$body + array(
					'fields' => array( $field, 'modified_at' ),
					'limit'  => 10000,
					'offset' => $offset,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( (array) ( $res['results'] ?? array() ) as $document ) {
				$item              = (string) $document[ $field ];
				$modified[ $item ] = max( $modified[ $item ] ?? 0, (int) ( $document['modified_at'] ?? 0 ) );
			}
			$documents = (int) ( $res['total'] ?? 0 );
			if ( $offset + 10000 >= $documents ) {
				return array(
					'items'     => array_map( 'strval', array_keys( $modified ) ),
					'modified'  => $modified,
					'documents' => $documents,
				);
			}
		}
	}

	/**
	 * How many documents the index has.
	 *
	 * @param array{url: string, key: string, index: string} $index The index.
	 * @return int|WP_Error
	 */
	public function count( array $index ): int|WP_Error {
		// The write key has documents.*, not stats.get.
		$res = $this->send( $index, 'GET', '/documents?limit=0&fields=id' );
		return is_wp_error( $res ) ? $res : (int) ( $res['total'] ?? 0 );
	}

	/**
	 * A write, which Meilisearch queues as a task.
	 *
	 * @param array{url: string, key: string, index: string} $index The index.
	 * @param string                                         $method The method.
	 * @param string                                         $path   The path under the index.
	 * @param array<mixed>                                   $body   The JSON body.
	 * @return true|WP_Error
	 */
	private function write( array $index, string $method, string $path, array $body ): bool|WP_Error {
		$res = $this->send( $index, $method, $path, $body );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * A request to the index.
	 *
	 * @param array{url: string, key: string, index: string} $index  The index.
	 * @param string                                         $method The method.
	 * @param string                                         $path   The path under the index.
	 * @param array<mixed>|null                              $body   A JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function send( array $index, string $method, string $path, ?array $body = null ): array|WP_Error {
		$options = array( 'headers' => array( 'Authorization' => 'Bearer ' . $index['key'] ) );
		if ( null !== $body ) {
			$options['json'] = $body;
		}
		$res = $this->http->request( $method, $index['url'] . '/indexes/' . rawurlencode( $index['index'] ) . $path, $options );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['status'] >= 400 ) {
			return new WP_Error(
				'bitlane_meilisearch',
				(string) ( $res['body']['message'] ?? 'Meilisearch answered ' . $res['status'] ),
				array(
					'status'      => $res['status'],
					'code'        => $res['body']['code'] ?? null,
					'retry_after' => $res['retry_after'],
				)
			);
		}
		return $res['body'];
	}
}
