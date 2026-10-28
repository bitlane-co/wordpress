<?php
/**
 * Bitlane's API, as the connected user.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Connection;

use Bitlane\WordPress\Support\Http;
use WP_Error;

/**
 * Calls the API with the grant's access token. A 401 is retried once with
 * refreshed tokens; a second one means the grant ended (the app was revoked
 * in Bitlane, or the site disconnected there), and the connection is closed.
 */
final class BitlaneApi {

	/**
	 * Builds the client.
	 *
	 * @param OAuthClient     $oauth      Tokens.
	 * @param ConnectionStore $connection The stored connection.
	 * @param Http            $http       HTTP.
	 */
	public function __construct(
		private OAuthClient $oauth,
		private ConnectionStore $connection,
		private Http $http,
	) {}

	/**
	 * Sends a request and returns the decoded answer.
	 *
	 * @param string                    $method GET, POST, PUT, DELETE.
	 * @param string                    $path   The path, e.g. /api/integrations.
	 * @param array<string, mixed>|null $body   A JSON body.
	 * @param array<string, scalar>     $query  The query.
	 * @return array<string, mixed>|WP_Error An error carries the status and the field errors in its data.
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = array() ): array|WP_Error {
		$token = $this->oauth->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$res = $this->send( $method, $path, $body, $query, $token );
		if ( ! is_wp_error( $res ) && 401 === $res['status'] ) {
			$token = $this->oauth->refresh( $token );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$res = $this->send( $method, $path, $body, $query, $token );
			if ( ! is_wp_error( $res ) && 401 === $res['status'] ) {
				$this->connection->end( true );
				return new WP_Error( 'bitlane_disconnected', __( 'Bitlane ended this site\'s connection. Connect again to keep searching.', 'bitlane' ), array( 'status' => 401 ) );
			}
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['status'] >= 400 ) {
			return new WP_Error(
				'bitlane_api',
				(string) ( $res['body']['message'] ?? __( 'Bitlane could not do that. Try again in a moment.', 'bitlane' ) ),
				array(
					'status' => $res['status'],
					'errors' => $res['body']['errors'] ?? null,
				)
			);
		}
		return $res['body'];
	}

	/**
	 * One request with a token.
	 *
	 * @param string                    $method The method.
	 * @param string                    $path   The path.
	 * @param array<string, mixed>|null $body   A JSON body.
	 * @param array<string, scalar>     $query  The query.
	 * @param string                    $token  The access token.
	 * @return array{status: int, body: array<string, mixed>}|WP_Error
	 */
	private function send( string $method, string $path, ?array $body, array $query, string $token ): array|WP_Error {
		$options = array(
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				// Bitlane answers in the admin's language (en or it).
				'Accept-Language' => substr( get_user_locale(), 0, 2 ),
			),
		);
		if ( null !== $body ) {
			$options['json'] = $body;
		}
		return $this->http->request( $method, add_query_arg( rawurlencode_deep( $query ), $this->oauth->api_url() . $path ), $options );
	}
}
