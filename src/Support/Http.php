<?php
/**
 * JSON over HTTP, through WordPress's HTTP API.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Support;

use WP_Error;

/**
 * Sends a request and decodes the JSON answer. A transport failure is a
 * WP_Error; any HTTP status comes back with its body, for the caller to
 * judge.
 */
final class Http {

	/**
	 * Sends a request.
	 *
	 * @param string               $method  GET, POST, PUT, DELETE.
	 * @param string               $url     The URL.
	 * @param array<string, mixed> $options headers; json (a body sent as JSON) or form (a form body).
	 * @return array{status: int, body: array<string, mixed>, retry_after: int}|WP_Error retry_after: seconds, 0 without one.
	 */
	public function request( string $method, string $url, array $options = array() ): array|WP_Error {
		$headers = array_merge( array( 'Accept' => 'application/json' ), $options['headers'] ?? array() );
		$body    = null;
		if ( array_key_exists( 'json', $options ) ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $options['json'] );
		} elseif ( isset( $options['form'] ) ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body                    = http_build_query( $options['form'], '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => is_array( $decoded ) ? $decoded : array(),
			'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
		);
	}
}
