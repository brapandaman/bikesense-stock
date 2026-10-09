<?php
/**
 * Wires the ledger, the shop sync, and the stock page.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Plugin {

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce' ) );
			return;
		}

		BS_Stock_Install::maybe_upgrade();
		BS_Stock_API::register();
		BS_Stock_Page::register();
		add_action( 'woocommerce_reduce_order_item_stock', array( 'BS_Stock_Sync', 'on_reduce' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( 'BS_Stock_Sync', 'on_restore' ), 10, 3 );
		add_filter( 'woocommerce_can_reduce_order_stock', array( 'BS_Stock_Sync', 'order_stock_allowed' ), 10, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', array( 'BS_Stock_Sync', 'order_stock_allowed' ), 10, 2 );
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', array( 'BS_Stock_Sync', 'order_reduce_trigger' ), 10, 2 );
	}

	public static function missing_woocommerce() {
		echo '<div class="notice notice-error"><p>Bike Sense Stock needs WooCommerce.</p></div>';
	}
}
