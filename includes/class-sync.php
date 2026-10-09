<?php
/**
 * Publish counted stock to WooCommerce, and mirror website orders into the ledger.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Sync {

	/**
	 * True while this class is writing shop stock, so an order hook cannot run twice.
	 *
	 * @var bool
	 */
	private static $pushing = false;

	/**
	 * @return bool
	 */
	public static function publishing() {
		return '1' === get_option( 'bikesense_stock_publish', '0' );
	}

	/**
	 * @param bool $publish Whether counted stock is visible on the shop.
	 * @param mixed $default_low Shop-wide threshold.
	 * @return array|WP_Error
	 */
	public static function update_settings( $publish, $default_low ) {
		$threshold = BS_Stock_Ledger::normalize_qty( $default_low );
		if ( null === $threshold || $threshold < 0 ) {
			return new WP_Error( 'bs_stock_low', 'Enter a low-stock number of zero or more.', array( 'status' => 400 ) );
		}

		$was     = self::publishing();
		$publish = (bool) $publish;
		update_option( 'bikesense_stock_default_low', BS_Stock_Ledger::format_qty( $threshold ) );
		update_option( 'bikesense_stock_publish', $publish ? '1' : '0' );

		if ( $publish && ! $was ) {
			self::push_all_tracked();
		} elseif ( ! $publish && $was ) {
			self::restore_all_tracked();
		}

		return self::settings();
	}

	/**
	 * @return array
	 */
	public static function settings() {
		$locations = array();
		foreach ( BS_Stock_Ledger::locations() as $location ) {
			$locations[] = BS_Stock_Ledger::format_location( $location );
		}

		return array(
			'publish'     => self::publishing(),
			'default_low' => (float) get_option( 'bikesense_stock_default_low', 1 ),
			'locations'   => $locations,
			'labour'      => class_exists( 'BS_Stock_Jobs' ) ? BS_Stock_Jobs::default_rates() : array(),
		);
	}

	/**
	 * Orders made from job cards never move stock. The job already booked it out.
	 *
	 * @param bool     $can Whether WooCommerce may change stock for the order.
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_stock_allowed( $can, $order ) {
		return self::is_job_order( $order ) ? false : $can;
	}

	/**
	 * Keep WooCommerce from flagging a job order as stock-reduced, so cancelling it later restores nothing.
	 *
	 * @param bool $trigger Whether to reduce stock on payment.
	 * @param int  $order_id Order id.
	 * @return bool
	 */
	public static function order_reduce_trigger( $trigger, $order_id ) {
		if ( function_exists( 'wc_get_order' ) && self::is_job_order( wc_get_order( $order_id ) ) ) {
			return false;
		}

		return $trigger;
	}

	/**
	 * @param mixed $order Order.
	 * @return bool
	 */
	public static function is_job_order( $order ) {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) && class_exists( 'BS_Stock_Jobs' ) && (int) $order->get_meta( BS_Stock_Jobs::ORDER_META_JOB, true ) > 0;
	}

	/**
	 * Copy the sellable total onto a counted product when publishing is on.
	 *
	 * @param int $product_id Product id.
	 */
	public static function push( $product_id ) {
		if ( self::$pushing || ! self::publishing() ) {
			return;
		}
		if ( '1' !== get_post_meta( $product_id, BS_Stock_Ledger::META_TRACKED, true ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return;
		}

		self::$pushing = true;
		self::mute_stock_mail();

		$snapshot = get_post_meta( $product_id, BS_Stock_Ledger::META_SNAPSHOT, true );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['stock_status'] ) ) {
			update_post_meta(
				$product_id,
				BS_Stock_Ledger::META_SNAPSHOT,
				array(
					'manage_stock'   => (bool) $product->get_manage_stock(),
					'stock_quantity' => $product->get_stock_quantity(),
					'had_quantity'   => null !== $product->get_stock_quantity(),
					'stock_status'   => $product->get_stock_status(),
					'backorders'     => $product->get_backorders(),
				)
			);
		}

		$total = BS_Stock_Ledger::sellable_qty( $product_id );
		$product->set_manage_stock( true );
		$product->set_backorders( 'no' );
		$product->set_stock_quantity( $total );
		$product->set_stock_status( $total > 0 ? 'instock' : 'outofstock' );
		$product->save();

		self::unmute_stock_mail();
		self::$pushing = false;
	}

	/**
	 * Publish every counted part. Used when the shop switch is turned on.
	 */
	public static function push_all_tracked() {
		if ( ! self::publishing() ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		foreach ( BS_Stock_Ledger::tracked_product_ids() as $product_id ) {
			self::push( $product_id );
		}
	}

	/**
	 * Put counted parts back to the shop settings they had before the first publish.
	 */
	public static function restore_all_tracked() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		self::$pushing = true;
		self::mute_stock_mail();

		foreach ( BS_Stock_Ledger::tracked_product_ids() as $product_id ) {
			$product  = wc_get_product( $product_id );
			$snapshot = get_post_meta( $product_id, BS_Stock_Ledger::META_SNAPSHOT, true );
			if ( ! $product || ! is_array( $snapshot ) || ! isset( $snapshot['stock_status'] ) ) {
				continue;
			}

			$product->set_manage_stock( ! empty( $snapshot['manage_stock'] ) );
			if ( ! empty( $snapshot['had_quantity'] ) ) {
				$product->set_stock_quantity( $snapshot['stock_quantity'] );
			}
			$product->set_stock_status( $snapshot['stock_status'] );
			if ( ! empty( $snapshot['backorders'] ) ) {
				$product->set_backorders( $snapshot['backorders'] );
			}
			$product->save();
			delete_post_meta( $product_id, BS_Stock_Ledger::META_SNAPSHOT );
		}

		self::unmute_stock_mail();
		self::$pushing = false;
	}

	/**
	 * @param WC_Order_Item_Product $item Order item.
	 * @param mixed                 $change Stock change from WooCommerce.
	 * @param WC_Order              $order Order.
	 */
	public static function on_reduce( $item, $change, $order ) {
		self::on_order_item( $item, $change, $order, 'out' );
	}

	/**
	 * @param WC_Order_Item_Product $item Order item.
	 * @param mixed                 $change Stock change from WooCommerce.
	 * @param WC_Order              $order Order.
	 */
	public static function on_restore( $item, $change, $order ) {
		self::on_order_item( $item, $change, $order, 'in' );
	}

	/**
	 * @param WC_Order_Item_Product $item Order item.
	 * @param mixed                 $change Stock change from WooCommerce.
	 * @param WC_Order              $order Order.
	 * @param string                $direction out or in.
	 */
	private static function on_order_item( $item, $change, $order, $direction ) {
		if ( self::$pushing || ! is_object( $item ) || ! is_object( $order ) || ! method_exists( $item, 'get_product' ) || self::is_job_order( $order ) ) {
			return;
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();
		if ( '1' !== get_post_meta( $product_id, BS_Stock_Ledger::META_TRACKED, true ) ) {
			return;
		}

		$qty = self::changed_qty( $change );
		if ( $qty <= 0 ) {
			return;
		}

		$location_id = BS_Stock_Ledger::shop_location_id();
		if ( ! $location_id ) {
			$locations   = BS_Stock_Ledger::locations( true );
			$location_id = $locations ? (int) $locations[0]->id : 0;
		}
		if ( ! $location_id ) {
			return;
		}

		$from = is_array( $change ) && isset( $change['from'] ) ? $change['from'] : 'x';
		$to   = is_array( $change ) && isset( $change['to'] ) ? $change['to'] : 'x';
		$key  = sprintf( 'order:%d:item:%d:%s:%s:%s', $order->get_id(), $item->get_id(), $direction, $from, $to );
		$note = ( 'out' === $direction ? 'Online order #' : 'Order restored #' ) . $order->get_order_number();

		BS_Stock_Ledger::record_order(
			array(
				'product_id'    => $product_id,
				'location_id'   => $location_id,
				'delta'         => 'out' === $direction ? 0 - $qty : $qty,
				'note'          => $note,
				'order_id'      => $order->get_id(),
				'order_item_id' => $item->get_id(),
				'source_key'    => $key,
			)
		);
	}

	/**
	 * @param mixed $change WooCommerce stock change payload.
	 * @return float
	 */
	private static function changed_qty( $change ) {
		if ( is_array( $change ) && isset( $change['from'], $change['to'] ) && is_numeric( $change['from'] ) && is_numeric( $change['to'] ) ) {
			return abs( (float) $change['to'] - (float) $change['from'] );
		}
		if ( is_numeric( $change ) ) {
			return abs( (float) $change );
		}

		return 0.0;
	}

	private static function mute_stock_mail() {
		add_filter( 'woocommerce_email_enabled_low_stock', '__return_false', 999 );
		add_filter( 'woocommerce_email_enabled_no_stock', '__return_false', 999 );
	}

	private static function unmute_stock_mail() {
		remove_filter( 'woocommerce_email_enabled_low_stock', '__return_false', 999 );
		remove_filter( 'woocommerce_email_enabled_no_stock', '__return_false', 999 );
	}
}
