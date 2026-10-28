<?php
/**
 * Additions to the cart, reported to Bitlane by the site.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Storefront;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sync\Retry;

/**
 * WooCommerce's additions to the cart (forms, AJAX buttons, the Store API)
 * go to Bitlane from the server, at once (docs/conversions-plan.md C6): a
 * request's additions in one Action Scheduler action per language (the
 * products'), retried with backoff, with the widget's session from its
 * cookie, the document and product ids, and the device from the user agent
 * (never the agent itself).
 */
final class CartAdditions implements HasHooks {

	/**
	 * The request's additions, by language.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $pending = array();

	/**
	 * Builds the additions.
	 *
	 * @param SiteEvents $events   Sends them.
	 * @param Settings   $settings How products became documents.
	 */
	public function __construct(
		private SiteEvents $events,
		private Settings $settings,
	) {}

	/**
	 * Additions to the cart, queued at the end of the request, and the
	 * action that sends them.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_add_to_cart', array( $this, 'added' ), 10, 4 );
		add_action( 'shutdown', array( $this, 'flush' ) );
		add_action( 'bitlane_send_cart_additions', array( $this, 'send' ), 10, 3 );
	}

	/**
	 * Notes an addition to the cart.
	 *
	 * @param string $cart_item_key The cart item.
	 * @param int    $product_id    The product.
	 * @param int    $quantity      How many.
	 * @param int    $variation_id  The variation, 0 for none.
	 */
	public function added( $cart_item_key, $product_id, $quantity, $variation_id ): void {
		$this->pending[ $this->events->language( (int) $product_id ) ][] = array(
			'id'          => wp_generate_uuid4(),
			'type'        => 'add_to_cart',
			'session_id'  => $this->events->session(),
			'at'          => time(),
			'document_id' => $this->settings->document_id( (int) $product_id, (int) $variation_id ),
			'product_id'  => (string) $product_id,
			'quantity'    => max( 1, (int) $quantity ),
			'device'      => self::device( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ),
		);
	}

	/**
	 * Queues the request's additions, 50 at most a language (a runaway
	 * script).
	 */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = array();
		// Most requests add nothing: they don't read the connection.
		if ( ! $pending || ! $this->events->connected() ) {
			return;
		}
		foreach ( $pending as $language => $additions ) {
			as_enqueue_async_action( 'bitlane_send_cart_additions', array( (string) $language, array_slice( $additions, 0, 50 ), 0 ), 'bitlane' );
		}
	}

	/**
	 * Sends additions; a failure that can pass tries again later.
	 *
	 * @param string                     $language  Their products' language.
	 * @param list<array<string, mixed>> $additions The additions.
	 * @param int                        $attempt   Attempts so far.
	 */
	public function send( string $language, array $additions, int $attempt = 0 ): void {
		$sent = $this->events->send( $language, $additions );
		if ( true === $sent ) {
			return;
		}
		$delay = Retry::after( $sent, $attempt );
		if ( null !== $delay ) {
			as_schedule_single_action( time() + $delay, 'bitlane_send_cart_additions', array( $language, $additions, $attempt + 1 ), 'bitlane' );
		}
	}

	/**
	 * The device of a user agent, as the widget names it: tablets are iPads
	 * and Android without "Mobile" (an iPad that says it's a Mac is a desktop).
	 *
	 * @param string $user_agent The user agent.
	 */
	public static function device( string $user_agent ): string {
		if ( preg_match( '/iPad|Tablet|Android(?!.*Mobile)/i', $user_agent ) ) {
			return 'tablet';
		}
		return preg_match( '/Mobile|iPhone|iPod|Android/i', $user_agent ) ? 'mobile' : 'desktop';
	}
}
