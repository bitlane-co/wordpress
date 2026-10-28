<?php
/**
 * The plugin as an OAuth client of Bitlane.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Connection;

use Bitlane\WordPress\Support\Http;
use Bitlane\WordPress\Support\Lock;
use WP_Error;

/**
 * Bitlane's OAuth 2.1 flow for a confidential client (docs/oauth-plan.md,
 * docs/wordpress-plan.md W1): the plugin registers itself (Dynamic Client
 * Registration) the first time, sends the admin to Bitlane's consent page
 * with PKCE, and exchanges the code from PHP, so the site doesn't have to be
 * reachable from the internet. Refreshes run one at a time, since several
 * requests can find the token expired together.
 */
final class OAuthClient {

	public const SCOPES = 'projects:read engines:write widgets:write analytics:read offline_access';

	/**
	 * Builds the client.
	 *
	 * @param ConnectionStore $connection The stored connection.
	 * @param Http            $http       HTTP.
	 * @param Lock            $lock       Refreshes one at a time.
	 * @param string          $version    The plugin's version, sent when it registers.
	 */
	public function __construct(
		private ConnectionStore $connection,
		private Http $http,
		private Lock $lock,
		private string $version,
	) {}

	/**
	 * The API, https://api.bitlane.co unless BITLANE_API_URL says otherwise.
	 */
	public function api_url(): string {
		return untrailingslashit( defined( 'BITLANE_API_URL' ) ? (string) constant( 'BITLANE_API_URL' ) : 'https://api.bitlane.co' );
	}

	/**
	 * Where Bitlane sends the admin back.
	 */
	public function redirect_uri(): string {
		return admin_url( 'admin.php?page=bitlane&oauth=callback' );
	}

	/**
	 * The authorization server's metadata (RFC 8414), kept a day.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function metadata(): array|WP_Error {
		$key    = 'bitlane_oauth_metadata_' . md5( $this->api_url() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = $this->http->request( 'GET', $this->api_url() . '/.well-known/oauth-authorization-server' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['status'] || empty( $res['body']['authorization_endpoint'] ) ) {
			return new WP_Error( 'bitlane_metadata', __( 'Bitlane could not be reached. Try again in a moment.', 'bitlane' ) );
		}
		set_transient( $key, $res['body'], DAY_IN_SECONDS );
		return $res['body'];
	}

	/**
	 * The dashboard's address, for "Open in Bitlane": the consent page's host.
	 */
	public function app_url(): ?string {
		$metadata = $this->metadata();
		if ( is_wp_error( $metadata ) ) {
			return null;
		}
		$parts = wp_parse_url( (string) $metadata['authorization_endpoint'] );
		if ( ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * The consent page's URL for a new grant, registering the client first
	 * when needed. The PKCE verifier and the expected issuer wait in a
	 * transient named after the state.
	 */
	public function authorize_url(): string|WP_Error {
		$metadata = $this->metadata();
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$client = $this->client( $metadata );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$verifier = $this->random();
		$state    = $this->random();
		set_transient(
			'bitlane_oauth_' . $state,
			array(
				'verifier' => $verifier,
				'issuer'   => (string) ( $metadata['issuer'] ?? '' ),
				'user'     => get_current_user_id(),
			),
			15 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			rawurlencode_deep(
				array(
					'response_type'         => 'code',
					'client_id'             => $client['client_id'],
					'redirect_uri'          => $this->redirect_uri(),
					'scope'                 => self::SCOPES,
					'state'                 => $state,
					'code_challenge'        => $this->base64url( hash( 'sha256', $verifier, true ) ),
					'code_challenge_method' => 'S256',
					'resource'              => $this->api_url(),
				)
			),
			(string) $metadata['authorization_endpoint']
		);
	}

	/**
	 * Handles Bitlane's answer on the redirect URI: checks the state and the
	 * issuer (RFC 9207), then exchanges the code.
	 *
	 * @param array<string, string> $query The callback's query.
	 */
	public function handle_callback( array $query ): true|WP_Error {
		$state   = $query['state'] ?? '';
		$pending = '' === $state ? false : get_transient( 'bitlane_oauth_' . $state );
		if ( ! is_array( $pending ) || get_current_user_id() !== $pending['user'] ) {
			return new WP_Error( 'bitlane_state', __( 'The connection request expired or came from elsewhere. Click Connect again.', 'bitlane' ) );
		}
		delete_transient( 'bitlane_oauth_' . $state );

		if ( isset( $query['error'] ) ) {
			return 'access_denied' === $query['error']
				? new WP_Error( 'bitlane_denied', __( 'The connection was declined in Bitlane.', 'bitlane' ) )
				: new WP_Error( 'bitlane_oauth', (string) ( $query['error_description'] ?? $query['error'] ) );
		}
		if ( isset( $query['iss'] ) && $query['iss'] !== $pending['issuer'] ) {
			return new WP_Error( 'bitlane_issuer', __( 'The answer did not come from Bitlane.', 'bitlane' ) );
		}
		if ( empty( $query['code'] ) ) {
			return new WP_Error( 'bitlane_oauth', __( 'Bitlane sent no authorization code.', 'bitlane' ) );
		}

		$tokens = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $query['code'],
				'redirect_uri'  => $this->redirect_uri(),
				'code_verifier' => $pending['verifier'],
				'resource'      => $this->api_url(),
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$this->save_tokens( $tokens, array( 'project_id' => $tokens['project_id'] ?? null ) );
		return true;
	}

	/**
	 * A valid access token, refreshed when it's about to expire.
	 */
	public function access_token(): string|WP_Error {
		$connection = $this->connection->get();
		if ( empty( $connection['refresh_token'] ) ) {
			return new WP_Error( 'bitlane_disconnected', __( 'This site isn\'t connected to Bitlane.', 'bitlane' ) );
		}
		if ( ! empty( $connection['access_token'] ) && (int) ( $connection['expires_at'] ?? 0 ) > time() + 60 ) {
			return (string) $connection['access_token'];
		}
		return $this->refresh( (string) ( $connection['access_token'] ?? '' ) );
	}

	/**
	 * Refreshes the tokens, unless another request did since $stale was read.
	 * A refused refresh token ends the connection.
	 *
	 * @param string $stale The access token found expired or refused.
	 */
	public function refresh( string $stale ): string|WP_Error {
		return $this->lock->with(
			'refresh',
			function () use ( $stale ): string|WP_Error {
				$connection = $this->connection->get();
				if ( empty( $connection['refresh_token'] ) ) {
					return new WP_Error( 'bitlane_disconnected', __( 'This site isn\'t connected to Bitlane.', 'bitlane' ) );
				}
				$renewed = ! empty( $connection['access_token'] ) && $connection['access_token'] !== $stale;
				if ( $renewed && (int) ( $connection['expires_at'] ?? 0 ) > time() + 60 ) {
					return (string) $connection['access_token'];
				}

				$tokens = $this->token(
					array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => $connection['refresh_token'],
						'resource'      => $this->api_url(),
					)
				);
				if ( is_wp_error( $tokens ) ) {
					if ( 'invalid_grant' === $tokens->get_error_code() ) {
						$this->connection->end( true );
					}
					return $tokens;
				}
				return $this->save_tokens( $tokens );
			}
		);
	}

	/**
	 * Revokes the grant's refresh token (RFC 7009); the connection ends either way.
	 */
	public function revoke(): void {
		$connection = $this->connection->get();
		$metadata   = $this->metadata();
		if ( ! empty( $connection['refresh_token'] ) && ! is_wp_error( $metadata ) && ! empty( $metadata['revocation_endpoint'] ) ) {
			$this->http->request(
				'POST',
				(string) $metadata['revocation_endpoint'],
				array(
					'headers' => $this->client_auth( $connection ),
					'form'    => array(
						'token'           => $connection['refresh_token'],
						'token_type_hint' => 'refresh_token',
					),
				)
			);
		}
		$this->connection->end( false );
	}

	/**
	 * The registered client, registering it when there's none for this
	 * redirect URI (a new site, or the site moved).
	 *
	 * @param array<string, mixed> $metadata The server's metadata.
	 * @return array<string, mixed>|WP_Error
	 */
	private function client( array $metadata ): array|WP_Error {
		$connection = $this->connection->get();
		if ( ! empty( $connection['client_id'] ) && $this->redirect_uri() === ( $connection['redirect_uri'] ?? '' ) ) {
			return $connection;
		}
		if ( empty( $metadata['registration_endpoint'] ) ) {
			return new WP_Error( 'bitlane_register', __( 'Bitlane does not accept new connections right now.', 'bitlane' ) );
		}

		$home = home_url();
		$res  = $this->http->request(
			'POST',
			(string) $metadata['registration_endpoint'],
			array(
				'json' => array(
					'redirect_uris'              => array( $this->redirect_uri() ),
					'token_endpoint_auth_method' => 'client_secret_basic',
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
					/* translators: %s: the site's host. */
					'client_name'                => sprintf( __( 'Bitlane for WordPress (%s)', 'bitlane' ), (string) wp_parse_url( $home, PHP_URL_HOST ) ),
					// Bitlane takes an https address only: a site without one registers without it.
					'client_uri'                 => str_starts_with( $home, 'https://' ) ? $home : '',
					'software_id'                => 'bitlane-wordpress',
					'software_version'           => $this->version,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! in_array( $res['status'], array( 200, 201 ), true ) || empty( $res['body']['client_id'] ) ) {
			return new WP_Error( 'bitlane_register', (string) ( $res['body']['error_description'] ?? __( 'Bitlane refused to register this site.', 'bitlane' ) ) );
		}

		return $this->connection->update(
			array(
				'client_id'     => $res['body']['client_id'],
				'client_secret' => $res['body']['client_secret'] ?? '',
				'redirect_uri'  => $this->redirect_uri(),
			)
		);
	}

	/**
	 * Calls the token endpoint with the client's credentials.
	 *
	 * @param array<string, string> $params The grant's parameters.
	 * @return array<string, mixed>|WP_Error The tokens; the OAuth error code on failure.
	 */
	private function token( array $params ): array|WP_Error {
		$metadata = $this->metadata();
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$res = $this->http->request(
			'POST',
			(string) $metadata['token_endpoint'],
			array(
				'headers' => $this->client_auth( $this->connection->get() ),
				'form'    => $params,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['status'] || empty( $res['body']['access_token'] ) ) {
			return new WP_Error(
				(string) ( $res['body']['error'] ?? 'bitlane_token' ),
				(string) ( $res['body']['error_description'] ?? __( 'Bitlane refused the connection.', 'bitlane' ) )
			);
		}
		return $res['body'];
	}

	/**
	 * Stores a token answer and returns the access token.
	 *
	 * @param array<string, mixed> $tokens The token endpoint's answer.
	 * @param array<string, mixed> $extra  More to store.
	 */
	private function save_tokens( array $tokens, array $extra = array() ): string {
		$connection = $this->connection->update(
			array_merge(
				array(
					'access_token'  => $tokens['access_token'],
					// A refresh without rotation keeps the one it has.
					'refresh_token' => $tokens['refresh_token'] ?? $this->connection->get()['refresh_token'] ?? null,
					'expires_at'    => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
					'scope'         => $tokens['scope'] ?? self::SCOPES,
					'revoked'       => null,
				),
				$extra
			)
		);
		return (string) $connection['access_token'];
	}

	/**
	 * HTTP Basic authentication with the client's id and secret (RFC 6749 2.3.1).
	 *
	 * @param array<string, mixed> $connection The connection.
	 * @return array<string, string>
	 */
	private function client_auth( array $connection ): array {
		$credentials = rawurlencode( (string) ( $connection['client_id'] ?? '' ) ) . ':' . rawurlencode( (string) ( $connection['client_secret'] ?? '' ) );
		return array( 'Authorization' => 'Basic ' . base64_encode( $credentials ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * A random value for the PKCE verifier and the state.
	 */
	private function random(): string {
		return $this->base64url( random_bytes( 32 ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes The bytes.
	 */
	private function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
