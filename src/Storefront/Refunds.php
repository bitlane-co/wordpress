<?php
/**
 * Refunds of reported orders, sent to Bitlane by the site.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Storefront;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Sync\Retry;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;

/**
 * Part of a reported order taken back (docs/conversions-plan.md C11): each
 * WooCommerce refund is sent once (_bitlane_reported in its meta), and a
 * paid order cancelled with something left as one refund of what's left
 * (_bitlane_cancel_reported in the order's meta). Bitlane counts them on
 * their own day, with the order's session; the order stays as it was placed.
 * From Action Scheduler, retried with backoff (Retry).
 */
final class Refunds implements HasHooks {

	/**
	 * Builds the refunds.
	 *
	 * @param SiteEvents $events   Sends them.
	 * @param Settings   $settings How products became documents.
	 */
	public function __construct(
		private SiteEvents $events,
		private Settings $settings,
	) {}

	/**
	 * Refunds and cancellations, and the actions that send them.
	 */
	public function hooks(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancelled' ) );
		add_action( 'bitlane_send_refund', array( $this, 'send_refund' ), 10, 2 );
		add_action( 'bitlane_send_cancellation', array( $this, 'send_cancellation' ), 10, 2 );
	}

	/**
	 * A refund was made: it's sent in the background when its order was
	 * reported.
	 *
	 * @param int $order_id  The order.
	 * @param int $refund_id The refund.
	 */
	public function refunded( $order_id, $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->get_meta( '_bitlane_reported' ) && $this->events->connected() ) {
			as_enqueue_async_action( 'bitlane_send_refund', array( (int) $refund_id, 0 ), 'bitlane' );
		}
	}

	/**
	 * A reported order was cancelled: what wasn't refunded yet is sent as a
	 * refund, in the background.
	 *
	 * @param int $order_id The order.
	 */
	public function cancelled( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->get_meta( '_bitlane_reported' ) && ! $order->get_meta( '_bitlane_cancel_reported' ) && $this->events->connected() ) {
			as_enqueue_async_action( 'bitlane_send_cancellation', array( $order->get_id(), 0 ), 'bitlane' );
		}
	}

	/**
	 * Sends a refund: its amount, and the items it took back.
	 *
	 * @param int $refund_id The refund.
	 * @param int $attempt   Attempts so far.
	 */
	public function send_refund( int $refund_id, int $attempt = 0 ): void {
		$refund = wc_get_order( $refund_id );
		$order  = $refund instanceof WC_Order_Refund ? wc_get_order( $refund->get_parent_id() ) : null;
		if ( ! $refund instanceof WC_Order_Refund || ! $order instanceof WC_Order || $refund->get_meta( '_bitlane_reported' ) ) {
			return;
		}

		$items = array();
		foreach ( $refund->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && 0 !== $item->get_quantity() ) {
				$items[] = $this->item( $item, absint( $item->get_quantity() ), abs( (float) $refund->get_item_total( $item, true ) ) );
			}
		}
		$made = $refund->get_date_created();
		$sent = $this->send( $order, (string) $refund->get_id(), abs( (float) $refund->get_amount() ), $items, $made ? $made->getTimestamp() : time() );

		if ( true === $sent ) {
			$refund->update_meta_data( '_bitlane_reported', gmdate( 'c' ) );
			$refund->save_meta_data();
			return;
		}
		$delay = Retry::after( $sent, $attempt );
		if ( null !== $delay ) {
			as_schedule_single_action( time() + $delay, 'bitlane_send_refund', array( $refund_id, $attempt + 1 ), 'bitlane' );
		}
	}

	/**
	 * Sends a cancelled order's rest (what no refund took back yet) as a
	 * refund; nothing when it was all refunded already.
	 *
	 * @param int $order_id The order.
	 * @param int $attempt  Attempts so far.
	 */
	public function send_cancellation( int $order_id, int $attempt = 0 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( '_bitlane_cancel_reported' ) ) {
			return;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			// Refunded quantities are negative.
			$left = $item instanceof WC_Order_Item_Product ? $item->get_quantity() + $order->get_qty_refunded_for_item( $item->get_id() ) : 0;
			if ( $item instanceof WC_Order_Item_Product && $left > 0 ) {
				$items[] = $this->item( $item, $left, (float) $order->get_item_total( $item, true ) );
			}
		}
		$value = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
		$sent  = $value > 0 || $items ? $this->send( $order, 'cancelled', $value, $items, time() ) : true;

		if ( true === $sent ) {
			$order->update_meta_data( '_bitlane_cancel_reported', gmdate( 'c' ) );
			$order->save_meta_data();
			return;
		}
		$delay = Retry::after( $sent, $attempt );
		if ( null !== $delay ) {
			as_schedule_single_action( time() + $delay, 'bitlane_send_cancellation', array( $order_id, $attempt + 1 ), 'bitlane' );
		}
	}

	/**
	 * A refunded item as Bitlane takes it, its document id as the order's.
	 *
	 * @param WC_Order_Item_Product $item     The item.
	 * @param int                   $quantity How many went back.
	 * @param float                 $price    Its unit price.
	 * @return array<string, mixed>
	 */
	private function item( WC_Order_Item_Product $item, int $quantity, float $price ): array {
		return array(
			'document_id' => $this->settings->document_id( $item->get_product_id(), $item->get_variation_id() ),
			'product_id'  => (string) $item->get_product_id(),
			'quantity'    => $quantity,
			'price'       => $price,
		);
	}

	/**
	 * Sends a refund of the order, with its session, in its products'
	 * language.
	 *
	 * @param WC_Order                   $order     The order.
	 * @param string                     $refund_id The refund's id.
	 * @param float                      $value     The amount taken back.
	 * @param list<array<string, mixed>> $items     The items taken back.
	 * @param int                        $at        When, in Unix seconds.
	 */
	private function send( WC_Order $order, string $refund_id, float $value, array $items, int $at ): true|\WP_Error {
		$product = 0;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$product = $item->get_product_id();
				break;
			}
		}
		return $this->events->send(
			$this->events->language( $product ),
			array(
				array(
					'id'         => wp_generate_uuid4(),
					'type'       => 'refund',
					'session_id' => (string) $order->get_meta( '_bitlane_session' ),
					'at'         => $at,
					'refund'     => array(
						'order_id'  => (string) $order->get_order_number(),
						'refund_id' => $refund_id,
						'currency'  => $order->get_currency(),
						'value'     => $value,
						// Bitlane takes 200.
						'items'     => array_slice( $items, 0, 200 ),
					),
				),
			)
		);
	}
}
