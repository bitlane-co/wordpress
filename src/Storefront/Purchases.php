<?php
/**
 * Paid orders, reported to Bitlane by the site.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Storefront;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sync\Retry;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Orders go to Bitlane from the server once they're paid (SiteEvents), so ad
 * blockers can't drop them and shoppers can't fake them, and orders paid
 * later (a bank transfer) count. The checkout keeps the widget's session from
 * its bitlane_session cookie, which it writes only with tracking on: with it,
 * Bitlane attributes the order to the shopper's searches. Nothing about the
 * shopper is sent. Each order is sent once (_bitlane_reported in its meta),
 * from Action Scheduler, retried with backoff, in its products' language.
 */
final class Purchases implements HasHooks {

	/**
	 * Builds the purchases.
	 *
	 * @param SiteEvents $events   Sends them.
	 * @param Settings   $settings How products became documents.
	 */
	public function __construct(
		private SiteEvents $events,
		private Settings $settings,
	) {}

	/**
	 * The checkouts, payments, and the action that sends.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'keep_session' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'keep_session' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'paid' ) );
		add_action( 'bitlane_send_purchase', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * Keeps the widget's session in a new order.
	 *
	 * @param WC_Order $order The order.
	 */
	public function keep_session( $order ): void {
		$session = $this->events->session();
		if ( '' !== $session ) {
			$order->update_meta_data( '_bitlane_session', $session );
			$order->save_meta_data();
		}
	}

	/**
	 * An order was paid: it's sent in the background, once.
	 *
	 * @param int $order_id The order.
	 */
	public function paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( '_bitlane_reported' ) || ! $this->events->connected() ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'bitlane_send_purchase', array( $order->get_id(), 0 ), 'bitlane' ) ) {
			as_enqueue_async_action( 'bitlane_send_purchase', array( $order->get_id(), 0 ), 'bitlane' );
		}
	}

	/**
	 * Sends an order; a failure that can pass tries again later.
	 *
	 * @param int $order_id The order.
	 * @param int $attempt  Attempts so far.
	 */
	public function send( int $order_id, int $attempt = 0 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( '_bitlane_reported' ) ) {
			return;
		}

		$items    = array();
		$language = '';
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $item->get_quantity() > 0 ) {
				$items[] = array(
					'document_id' => $this->settings->document_id( $item->get_product_id(), $item->get_variation_id() ),
					// A variation's parent: it matches a click on another variation.
					'product_id'  => (string) $item->get_product_id(),
					'quantity'    => $item->get_quantity(),
					'price'       => (float) $order->get_item_total( $item, true ),
				);
				// The products are bought in one language: the first one's.
				if ( '' === $language ) {
					$language = $this->events->language( $item->get_product_id() );
				}
			}
		}
		$placed = $order->get_date_created();
		$sent   = $this->events->send(
			'' !== $language ? $language : $this->events->language(),
			array(
				array(
					'id'         => wp_generate_uuid4(),
					'type'       => 'purchase',
					'session_id' => (string) $order->get_meta( '_bitlane_session' ),
					'at'         => $placed ? $placed->getTimestamp() : time(),
					'purchase'   => array(
						'order_id' => (string) $order->get_order_number(),
						'currency' => $order->get_currency(),
						'value'    => (float) $order->get_total(),
						// Bitlane takes 200.
						'items'    => array_slice( $items, 0, 200 ),
					),
				),
			)
		);

		if ( true === $sent ) {
			$order->update_meta_data( '_bitlane_reported', gmdate( 'c' ) );
			$order->save_meta_data();
			return;
		}
		$delay = Retry::after( $sent, $attempt );
		if ( null !== $delay ) {
			as_schedule_single_action( time() + $delay, 'bitlane_send_purchase', array( $order_id, $attempt + 1 ), 'bitlane' );
		}
	}
}
