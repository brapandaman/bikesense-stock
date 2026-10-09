<?php
/**
 * Tables, defaults, and the Pretoria location.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Install {

	const DB_VERSION = '1';

	/**
	 * Create tables, defaults, and the first location.
	 */
	public static function activate() {
		self::install();
		BS_Stock_Page::register_rewrite();
		flush_rewrite_rules();
		update_option( 'bikesense_stock_rewrite', BS_STOCK_VERSION );
	}

	/**
	 * Drop the public route. Ledger rows stay until the plugin is deleted.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Keep the database current if the plugin files were copied without activation.
	 */
	public static function maybe_upgrade() {
		global $wpdb;

		$table = $wpdb->prefix . 'bs_stock_locations';
		$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( $ready && self::DB_VERSION === get_option( 'bikesense_stock_db_version' ) ) {
			if ( get_option( 'bikesense_stock_rewrite' ) !== BS_STOCK_VERSION ) {
				BS_Stock_Page::register_rewrite();
				flush_rewrite_rules();
				update_option( 'bikesense_stock_rewrite', BS_STOCK_VERSION );
			}
			return;
		}

		self::install();
		BS_Stock_Page::register_rewrite();
		flush_rewrite_rules();
		update_option( 'bikesense_stock_rewrite', BS_STOCK_VERSION );
	}

	/**
	 * dbDelta is safe to run more than once.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset    = $wpdb->get_charset_collate();
		$locations  = $wpdb->prefix . 'bs_stock_locations';
		$balances   = $wpdb->prefix . 'bs_stock_balances';
		$movements  = $wpdb->prefix . 'bs_stock_movements';

		$sql = "CREATE TABLE {$locations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			code varchar(64) NOT NULL DEFAULT '',
			is_sellable tinyint(1) NOT NULL DEFAULT 1,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) {$charset};
		CREATE TABLE {$balances} (
			product_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned NOT NULL,
			qty decimal(12,2) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (product_id, location_id),
			KEY location_id (location_id)
		) {$charset};
		CREATE TABLE {$movements} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			qty decimal(12,2) NOT NULL DEFAULT 0,
			qty_delta decimal(12,2) NOT NULL DEFAULT 0,
			balance_after decimal(12,2) NOT NULL DEFAULT 0,
			note text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_key varchar(80) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY location_id (location_id),
			KEY created_at (created_at),
			KEY source_key (source_key),
			KEY order_item (order_id, order_item_id)
		) {$charset};";

		dbDelta( $sql );

		add_option( 'bikesense_stock_publish', '0' );
		add_option( 'bikesense_stock_default_low', '1' );
		update_option( 'bikesense_stock_db_version', self::DB_VERSION );

		self::seed_pretoria();
	}

	/**
	 * The shop starts as one sellable location.
	 */
	public static function seed_pretoria() {
		global $wpdb;

		$table = $wpdb->prefix . 'bs_stock_locations';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $count > 0 ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'name'        => 'Pretoria',
				'code'        => 'PTA',
				'is_sellable' => 1,
				'is_active'   => 1,
				'sort_order'  => 0,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}
}
