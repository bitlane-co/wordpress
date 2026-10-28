<?php
/**
 * The store's events, sent to Bitlane from the server.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Storefront;

use Bitlane\WordPress\Connection\BitlaneApi;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Languages\Languages;
use WP_Error;

/**
 * POST /api/integrations/:id/events: additions to the cart and paid orders,
 * up to 50 at a time, in a language (docs/conversions-plan.md C6), whose
 * products index holds their documents.
 */
final class SiteEvents {

	/**
	 * Builds the sender.
	 *
	 * @param BitlaneApi      $api        The API.
	 * @param ConnectionStore $connection The connection, for the site's id.
	 * @param Languages       $languages  The products' languages.
	 */
	public function __construct(
		private BitlaneApi $api,
		private ConnectionStore $connection,
		private Languages $languages,
	) {}

	/**
	 * The language of an event about a product: the product's (a
	 * translation is another product), else the request's.
	 *
	 * @param int $product_id The product; 0 for none.
	 */
	public function language( int $product_id = 0 ): string {
		$language = $product_id ? $this->languages->of_post( $product_id ) : '';
		return '' !== $language ? $language : $this->languages->current();
	}

	/**
	 * Whether the site is connected, so events can go.
	 */
	public function connected(): bool {
		return ! empty( $this->connection->get()['integration_id'] );
	}

	/**
	 * The widget's session from its bitlane_session cookie, which it writes
	 * only with tracking on; empty without one.
	 */
	public function session(): string {
		$session = sanitize_text_field( wp_unslash( $_COOKIE['bitlane_session'] ?? '' ) );
		return preg_match( '/^[0-9a-f-]{36}$/', $session ) ? $session : '';
	}

	/**
	 * Sends events. A site no longer connected is an error that isn't
	 * retried (Retry).
	 *
	 * @param string                     $language The language (language()).
	 * @param list<array<string, mixed>> $events   The events, as Bitlane takes them.
	 */
	public function send( string $language, array $events ): true|WP_Error {
		$id = $this->connection->get()['integration_id'] ?? null;
		if ( ! $id ) {
			return new WP_Error( 'bitlane_disconnected', 'The site is no longer connected to Bitlane.' );
		}
		$sent = $this->api->request(
			'POST',
			'/api/integrations/' . rawurlencode( (string) $id ) . '/events',
			array(
				'language' => $language,
				'events'   => $events,
			)
		);
		return is_wp_error( $sent ) ? $sent : true;
	}
}
